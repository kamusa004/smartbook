<?php
declare(strict_types=1);

/**
 * Authentication + authorisation.
 *
 * Two credential styles are supported:
 *   1. Bearer token  (PWA / offline clients, cross-device)  <- primary
 *   2. PHP session   (classic browser use)
 *
 * The tenant id (shop_id) is ALWAYS taken from the resolved credential.
 * It is never read from the request body/query, which is what makes
 * cross-shop access structurally impossible rather than merely discouraged.
 */
final class Auth
{
    private static ?array $ctx = null;

    /* ------------------------------------------------------------------ */
    /* Logins                                                             */
    /* ------------------------------------------------------------------ */

    public static function loginShop(string $username, string $password, ?string $deviceId = null): array
    {
        self::throttle($username);

        $u = Db::one(
            'SELECT u.id, u.shop_id, u.full_name, u.username, u.password_hash, u.is_active, u.role_id,
                    r.code AS role_code, r.name AS role_name,
                    s.shop_uid, s.name AS shop_name, s.status AS shop_status, s.is_sample,
                    s.plan_id, s.subscription_status, s.subscription_started_at, s.subscription_expires_at,
                    s.grace_days, s.offline_grace_days, s.preferred_language, s.location
               FROM shop_users u
               JOIN shops s ON s.id = u.shop_id
               JOIN roles r ON r.id = u.role_id
              WHERE u.username = ? LIMIT 1',
            [$username]
        );

        if (!$u || !password_verify($password, $u['password_hash'])) {
            throw new AppException('Incorrect username or password.', 401, 'INVALID_CREDENTIALS');
        }
        if ((int) $u['is_active'] !== 1) {
            throw new AppException('This employee account has been deactivated by the shop owner.', 403, 'ACCOUNT_DISABLED');
        }
        if ($u['shop_status'] === 'deactivated') {
            throw new AppException('This shop account has been closed. Contact SmartBook support.', 403, 'SHOP_CLOSED');
        }
        if ($u['shop_status'] === 'suspended') {
            throw new AppException('This shop account is suspended. Contact SmartBook support.', 403, 'SHOP_SUSPENDED');
        }

        Db::exec('UPDATE shop_users SET last_login_at = ? WHERE id = ?', [Db::now(), $u['id']]);
        $token = self::issueToken((int) $u['shop_id'], (int) $u['id'], null, $deviceId);
        Audit::log((int) $u['shop_id'], 'shop_user', (int) $u['id'], 'auth.login', 'shop_users', (string) $u['id']);

        return [
            'token'       => $token,
            'csrf'        => self::csrfFor($token),
            'user'        => [
                'id'        => (int) $u['id'],
                'name'      => $u['full_name'],
                'username'  => $u['username'],
                'role'      => $u['role_code'],
                'role_name' => $u['role_name'],
            ],
            'shop'        => Shop::profile((int) $u['shop_id']),
            'permissions' => Rbac::permissionsFor((int) $u['role_id']),
        ];
    }

    public static function loginAdmin(string $username, string $password): array
    {
        self::throttle('admin:' . $username);
        $a = Db::one('SELECT * FROM system_administrators WHERE username = ? OR email = ? LIMIT 1', [$username, $username]);
        if (!$a || !password_verify($password, $a['password_hash'])) {
            throw new AppException('Incorrect administrator credentials.', 401, 'INVALID_CREDENTIALS');
        }
        if ((int) $a['is_active'] !== 1) {
            throw new AppException('This administrator account is disabled.', 403, 'ACCOUNT_DISABLED');
        }
        Db::exec('UPDATE system_administrators SET last_login_at = ? WHERE id = ?', [Db::now(), $a['id']]);
        $token = self::issueToken(null, null, (int) $a['id'], 'admin-web');
        Audit::log(null, 'sysadmin', (int) $a['id'], 'admin.login', 'system_administrators', (string) $a['id']);

        return [
            'token' => $token,
            'csrf'  => self::csrfFor($token),
            'admin' => ['id' => (int) $a['id'], 'name' => $a['full_name'], 'email' => $a['email'], 'username' => $a['username']],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Tokens                                                             */
    /* ------------------------------------------------------------------ */

    private static function issueToken(?int $shopId, ?int $userId, ?int $sysadminId, ?string $deviceId): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl   = (int) Config::get('security.token_ttl_hours', 72);
        Db::exec(
            'INSERT INTO api_tokens (shop_id, user_id, sysadmin_id, token_hash, device_id, expires_at)
             VALUES (?,?,?,?,?,?)',
            [$shopId, $userId, $sysadminId, hash('sha256', $token), $deviceId, date('Y-m-d H:i:s', time() + $ttl * 3600)]
        );
        return $token;
    }

    public static function revoke(string $token): void
    {
        Db::exec('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $h = $v;
                }
            }
        }
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', trim((string) $h), $m)) {
            return $m[1];
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Resolution                                                         */
    /* ------------------------------------------------------------------ */

    /** Resolve the caller. Returns null when unauthenticated. */
    public static function context(): ?array
    {
        if (self::$ctx !== null) {
            return self::$ctx ?: null;
        }

        // 1) Bearer token
        $token = self::bearer();
        if ($token !== null) {
            $row = Db::one(
                'SELECT t.id AS token_id, t.token_hash, t.shop_id, t.user_id, t.sysadmin_id, t.expires_at,
                        u.full_name, u.username, u.is_active, u.role_id,
                        r.code AS role_code, r.name AS role_name,
                        s.status AS shop_status, s.subscription_status, s.subscription_expires_at,
                        s.grace_days, s.offline_grace_days, s.plan_id, s.is_sample,
                        a.full_name AS admin_name, a.username AS admin_username, a.is_active AS admin_active
                   FROM api_tokens t
                   LEFT JOIN shop_users u ON u.id = t.user_id
                   LEFT JOIN roles r      ON r.id = u.role_id
                   LEFT JOIN shops s      ON s.id = t.shop_id
                   LEFT JOIN system_administrators a ON a.id = t.sysadmin_id
                  WHERE t.token_hash = ? LIMIT 1',
                [hash('sha256', $token)]
            );
            if (!$row) {
                self::$ctx = [];
                return null;
            }
            if (strtotime($row['expires_at']) < time()) {
                Db::exec('DELETE FROM api_tokens WHERE id = ?', [$row['token_id']]);
                self::$ctx = [];
                return null;
            }
            if ($row['sysadmin_id']) {
                if ((int) $row['admin_active'] !== 1) {
                    self::$ctx = [];
                    return null;
                }
                return self::$ctx = [
                    'type' => 'admin', 'token' => $token, 'shop_id' => null, 'user_id' => null,
                    'admin_id' => (int) $row['sysadmin_id'], 'name' => $row['admin_name'],
                    'permissions' => ['*'],
                ];
            }
            if (!$row['shop_id'] || (int) $row['is_active'] !== 1) {
                self::$ctx = [];
                return null;
            }
            return self::$ctx = [
                'type'        => 'shop',
                'token'       => $token,
                'shop_id'     => (int) $row['shop_id'],
                'user_id'     => (int) $row['user_id'],
                'name'        => $row['full_name'],
                'username'    => $row['username'],
                'role'        => $row['role_code'],
                'role_name'   => $row['role_name'],
                'role_id'     => (int) $row['role_id'],
                'permissions' => Rbac::permissionsFor((int) $row['role_id']),
                'shop'        => [
                    'status'               => $row['shop_status'],
                    'subscription_status'  => $row['subscription_status'],
                    'subscription_expires_at' => $row['subscription_expires_at'],
                    'grace_days'           => (int) $row['grace_days'],
                    'offline_grace_days'   => (int) $row['offline_grace_days'],
                    'plan_id'              => $row['plan_id'] ? (int) $row['plan_id'] : null,
                    'is_sample'            => (int) $row['is_sample'],
                ],
            ];
        }

        // 2) PHP session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSession();
        }
        if (!empty($_SESSION['shop_user']) && !empty($_SESSION['shop_id'])) {
            $u = Db::one(
                'SELECT u.id, u.full_name, u.username, u.role_id, r.code AS role_code, r.name AS role_name
                   FROM shop_users u JOIN roles r ON r.id = u.role_id
                  WHERE u.id = ? AND u.shop_id = ? AND u.is_active = 1',
                [$_SESSION['shop_user'], $_SESSION['shop_id']]
            );
            if ($u) {
                return self::$ctx = [
                    'type' => 'shop', 'token' => null,
                    'shop_id' => (int) $_SESSION['shop_id'], 'user_id' => (int) $u['id'],
                    'name' => $u['full_name'], 'username' => $u['username'],
                    'role' => $u['role_code'], 'role_name' => $u['role_name'], 'role_id' => (int) $u['role_id'],
                    'permissions' => Rbac::permissionsFor((int) $u['role_id']),
                    'shop' => Shop::gate((int) $_SESSION['shop_id']),
                ];
            }
        }
        if (!empty($_SESSION['sysadmin_id'])) {
            return self::$ctx = [
                'type' => 'admin', 'token' => null, 'shop_id' => null, 'user_id' => null,
                'admin_id' => (int) $_SESSION['sysadmin_id'], 'name' => 'Administrator', 'permissions' => ['*'],
            ];
        }

        self::$ctx = [];
        return null;
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string) Config::get('security.session_name', 'smartbook_sid'));
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true,
            'secure'   => (bool) Config::get('security.force_https', false),
            'samesite' => 'Lax',
        ]);
        @session_start();
    }

    /* ------------------------------------------------------------------ */
    /* Guards                                                             */
    /* ------------------------------------------------------------------ */

    public static function requireShop(): array
    {
        $c = self::context();
        if (!$c || $c['type'] !== 'shop') {
            throw new AppException('Sign in to continue.', 401, 'UNAUTHENTICATED');
        }
        if (($c['shop']['status'] ?? '') === 'deactivated') {
            throw new AppException('This shop account is closed.', 403, 'SHOP_CLOSED');
        }
        return $c;
    }

    public static function requireAdmin(): array
    {
        $c = self::context();
        if (!$c || $c['type'] !== 'admin') {
            throw new AppException('Administrator sign-in required.', 401, 'UNAUTHENTICATED');
        }
        return $c;
    }

    /** RBAC middleware: throws 403 unless the role holds the permission. */
    public static function can(string $permission): bool
    {
        $c = self::context();
        if (!$c) {
            return false;
        }
        return in_array('*', $c['permissions'], true) || in_array($permission, $c['permissions'], true);
    }

    public static function requirePermission(string $permission): void
    {
        if (!self::can($permission)) {
            throw new AppException('Your role is not allowed to perform this action (' . $permission . ').', 403, 'FORBIDDEN');
        }
    }

    /* ------------------------------------------------------------------ */
    /* CSRF                                                               */
    /* ------------------------------------------------------------------ */

    public static function csrfFor(?string $token): string
    {
        if ($token !== null) {
            // Stateless, derived from the bearer token: nothing to store or expire.
            return hash_hmac('sha256', $token, (string) Config::get('app.name'));
        }
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        if (!Config::get('security.csrf', true)) {
            return;
        }
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (Input::body()['_csrf'] ?? '');
        $c = self::context();
        $expected = $c && $c['token'] ? self::csrfFor($c['token']) : (($_SESSION['csrf'] ?? ''));
        if (!$expected || !is_string($sent) || !hash_equals($expected, $sent)) {
            throw new AppException('Security token missing or invalid. Refresh the page and try again.', 419, 'CSRF');
        }
    }

    private static function throttle(string $key): void
    {
        $max = (int) Config::get('security.max_login_attempts', 8);
        $win = (int) Config::get('security.login_window_min', 15);
        $k = 'login_attempts:' . md5($key);
        $path = sys_get_temp_dir() . '/smartbook_' . $k . '.json';
        $now = time();
        $data = ['count' => 0, 'first' => $now];
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true) ?: $data;
        }
        if ($now - (int) $data['first'] > $win * 60) {
            $data = ['count' => 0, 'first' => $now];
        }
        $data['count']++;
        @file_put_contents($path, json_encode($data));
        if ($data['count'] > $max) {
            throw new AppException('Too many sign-in attempts. Please wait a few minutes and try again.', 429, 'THROTTLED');
        }
    }
}
