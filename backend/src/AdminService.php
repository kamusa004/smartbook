<?php
declare(strict_types=1);

/**
 * Super Administrator API. Platform-level only: no shop's business data is
 * ever returned through these endpoints, only counts, plans, money and status.
 */
final class AdminService
{
    public static function overview(): array
    {
        $totals = Db::one(
            'SELECT COUNT(*) AS shops,
                    SUM(status = "active") AS active_shops,
                    SUM(status = "suspended") AS suspended_shops,
                    SUM(status = "pending") AS pending_shops,
                    SUM(subscription_expires_at < NOW()) AS lapsed
               FROM shops'
        ) ?: [];

        $revenue = Db::one(
            'SELECT COALESCE(SUM(amount_ugx),0) AS all_time,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(),"%Y-%m-01") THEN amount_ugx END),0) AS this_month,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN amount_ugx END),0) AS last_7d,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(),"%Y-01-01") THEN amount_ugx END),0) AS this_year
               FROM payments WHERE status = "verified"'
        ) ?: [];

        return [
            'shops' => [
                'total'     => (int) ($totals['shops'] ?? 0),
                'active'    => (int) ($totals['active_shops'] ?? 0),
                'suspended' => (int) ($totals['suspended_shops'] ?? 0),
                'pending'   => (int) ($totals['pending_shops'] ?? 0),
                'lapsed'    => (int) ($totals['lapsed'] ?? 0),
            ],
            'revenue' => [
                'all_time'   => (int) $revenue['all_time'],
                'this_month' => (int) $revenue['this_month'],
                'last_7d'    => (int) $revenue['last_7d'],
                'this_year'  => (int) $revenue['this_year'],
            ],
            'pending_payments' => (int) Db::val('SELECT COUNT(*) FROM payments WHERE status = "pending"', [], 0),
            'open_support'     => (int) Db::val('SELECT COUNT(*) FROM support_requests WHERE status <> "closed"', [], 0),
            'monthly_series'   => Db::all(
                'SELECT DATE_FORMAT(created_at,"%Y-%m") AS month, SUM(amount_ugx) AS total, COUNT(*) AS payments
                   FROM payments WHERE status = "verified" AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  GROUP BY month ORDER BY month'
            ),
            'plan_mix' => Db::all(
                'SELECT p.name, p.code, COUNT(s.id) AS shops, p.price_ugx
                   FROM subscription_plans p LEFT JOIN shops s ON s.plan_id = p.id
                  GROUP BY p.id, p.name, p.code, p.price_ugx ORDER BY p.price_ugx'
            ),
            'recent_shops' => Db::all(
                'SELECT id, shop_uid, name, owner_name, phone, location, status, subscription_status,
                        subscription_expires_at, is_sample, created_at
                   FROM shops ORDER BY created_at DESC LIMIT 12'
            ),
        ];
    }

    public static function shops(array $q): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($q['search'])) {
            $where[] = '(name LIKE ? OR owner_name LIKE ? OR phone LIKE ? OR shop_uid LIKE ?)';
            $like = '%' . $q['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($q['status'])) {
            $where[] = 's.status = ?';
            $params[] = $q['status'];
        }
        $rows = Db::all(
            'SELECT s.id, s.shop_uid, s.name, s.owner_name, s.phone, s.email, s.location, s.status,
                    s.subscription_status, s.subscription_started_at, s.subscription_expires_at,
                    s.is_sample, s.created_at, p.name AS plan_name, p.price_ugx,
                    (SELECT COUNT(*) FROM shop_users u WHERE u.shop_id = s.id AND u.is_active = 1) AS users,
                    (SELECT COALESCE(SUM(amount_ugx),0) FROM payments pay WHERE pay.shop_id = s.id AND pay.status = "verified") AS paid_total
               FROM shops s LEFT JOIN subscription_plans p ON p.id = s.plan_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY s.created_at DESC LIMIT 300',
            $params
        );
        return $rows;
    }

    public static function shopDetail(int $shopId): array
    {
        $shop = Db::one(
            'SELECT s.*, p.name AS plan_name, p.price_ugx FROM shops s
              LEFT JOIN subscription_plans p ON p.id = s.plan_id WHERE s.id = ?', [$shopId]
        );
        if (!$shop) {
            throw new AppException('Shop not found.', 404, 'NOT_FOUND');
        }
        $shop['users'] = Db::all(
            'SELECT u.id, u.full_name, u.username, u.is_active, u.last_login_at, r.name AS role_name
               FROM shop_users u JOIN roles r ON r.id = u.role_id WHERE u.shop_id = ? ORDER BY u.id', [$shopId]
        );
        $shop['payments'] = Db::all('SELECT * FROM payments WHERE shop_id = ? ORDER BY created_at DESC LIMIT 50', [$shopId]);
        // Aggregate activity metrics only - never the underlying records.
        $shop['activity'] = [
            'products'  => (int) Db::val('SELECT COUNT(*) FROM products WHERE shop_id = ?', [$shopId], 0),
            'sales'     => (int) Db::val('SELECT COUNT(*) FROM sales WHERE shop_id = ?', [$shopId], 0),
            'employees' => (int) Db::val('SELECT COUNT(*) FROM shop_users WHERE shop_id = ?', [$shopId], 0),
            'last_sale' => Db::val('SELECT MAX(sold_at) FROM sales WHERE shop_id = ?', [$shopId], null),
        ];
        return $shop;
    }

    public static function setShopStatus(int $shopId, string $status, int $adminId): array
    {
        if (!in_array($status, ['pending', 'active', 'suspended', 'deactivated'], true)) {
            throw new AppException('Invalid shop status.', 422, 'VALIDATION');
        }
        Db::exec('UPDATE shops SET status = ?, updated_at = ? WHERE id = ?', [$status, Db::now(), $shopId]);
        Audit::log(null, 'sysadmin', $adminId, 'admin.shop_status', 'shops', (string) $shopId, ['status' => $status]);
        if ($status === 'suspended') {
            // Tokens are revoked so the device must re-authenticate after reactivation.
            Db::exec('DELETE FROM api_tokens WHERE shop_id = ?', [$shopId]);
        }
        return ['id' => $shopId, 'status' => $status];
    }

    public static function plans(): array
    {
        $plans = Db::all('SELECT * FROM subscription_plans ORDER BY price_ugx');
        foreach ($plans as &$p) {
            $p['features'] = json_decode((string) $p['features'], true) ?: [];
            $p['price_ugx'] = (int) $p['price_ugx'];
            $p['max_users'] = (int) $p['max_users'];
            $p['shop_count'] = (int) Db::val('SELECT COUNT(*) FROM shops WHERE plan_id = ?', [$p['id']], 0);
        }
        return $plans;
    }

    public static function savePlan(array $in, int $adminId): array
    {
        $id = Input::int($in, 'id');
        $features = $in['features'] ?? [];
        if (is_string($features)) {
            $features = array_values(array_filter(array_map('trim', explode(',', $features))));
        }
        $data = [
            'code'      => strtolower(Input::need($in, 'code', 30)),
            'name'      => Input::need($in, 'name', 80),
            'price'     => Input::int($in, 'price_ugx'),
            'max_users' => max(1, Input::int($in, 'max_users', 3)),
            'features'  => json_encode($features),
            'active'    => Input::bool($in, 'is_active', true) ? 1 : 0,
        ];
        if ($id) {
            Db::exec('UPDATE subscription_plans SET code=?, name=?, price_ugx=?, max_users=?, features=?, is_active=?, updated_at=? WHERE id=?',
                [$data['code'], $data['name'], $data['price'], $data['max_users'], $data['features'], $data['active'], Db::now(), $id]);
        } else {
            Db::exec('INSERT INTO subscription_plans (code, name, price_ugx, max_users, features, is_active) VALUES (?,?,?,?,?,?)',
                [$data['code'], $data['name'], $data['price'], $data['max_users'], $data['features'], $data['active']]);
            $id = Db::id();
        }
        Audit::log(null, 'sysadmin', $adminId, 'admin.plan_save', 'subscription_plans', (string) $id, $data);
        return ['id' => $id];
    }

    public static function payments(array $q): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($q['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $q['status'];
        }
        return Db::all(
            'SELECT p.*, s.name AS shop_name, s.shop_uid, pl.name AS plan_name, a.full_name AS verified_by_name
               FROM payments p
               JOIN shops s ON s.id = p.shop_id
               LEFT JOIN subscription_plans pl ON pl.id = p.plan_id
               LEFT JOIN system_administrators a ON a.id = p.verified_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY p.created_at DESC LIMIT 300',
            $params
        );
    }

    /**
     * Verify a payment and extend the shop's subscription.
     * This is the manual verification path used until MoMo/Airtel APIs are live.
     */
    public static function verifyPayment(int $paymentId, int $adminId, bool $approve, string $note = ''): array
    {
        $pay = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$pay) {
            throw new AppException('Payment not found.', 404, 'NOT_FOUND');
        }
        if ($pay['status'] !== 'pending') {
            throw new AppException('This payment has already been reviewed.', 409, 'ALREADY_REVIEWED');
        }

        if (!$approve) {
            Db::exec('UPDATE payments SET status = "rejected", verified_by = ?, verified_at = ?, notes = ?, updated_at = ? WHERE id = ?',
                [$adminId, Db::now(), $note ?: 'Rejected by administrator', Db::now(), $paymentId]);
            return ['status' => 'rejected'];
        }

        Db::begin();
        try {
            Db::exec('UPDATE payments SET status = "verified", verified_by = ?, verified_at = ?, notes = ?, updated_at = ? WHERE id = ?',
                [$adminId, Db::now(), $note ?: null, Db::now(), $paymentId]);

            $shop = Db::one('SELECT id, subscription_expires_at, plan_id FROM shops WHERE id = ?', [$pay['shop_id']]);
            $months = max(1, (int) $pay['period_months']);
            $base = ($shop['subscription_expires_at'] && strtotime((string) $shop['subscription_expires_at']) > time())
                ? strtotime((string) $shop['subscription_expires_at'])
                : time();
            $newExpiry = date('Y-m-d H:i:s', strtotime("+{$months} months", $base));

            Db::exec(
                'UPDATE shops SET subscription_status = "active", subscription_started_at = COALESCE(subscription_started_at, ?),
                        subscription_expires_at = ?, plan_id = COALESCE(?, plan_id), status = IF(status = "pending","active",status), updated_at = ?
                  WHERE id = ?',
                [Db::now(), $newExpiry, $pay['plan_id'], Db::now(), $shop['id']]
            );
            Db::exec(
                'INSERT INTO subscriptions (shop_id, plan_id, payment_id, period_months, amount_ugx, starts_at, ends_at, status)
                 VALUES (?,?,?,?,?,?,?,"active")',
                [$shop['id'], $pay['plan_id'], $paymentId, $months, (int) $pay['amount_ugx'], Db::now(), $newExpiry]
            );
            Notify::push((int) $shop['id'], 'subscription', 'Subscription renewed',
                'Your SmartBook subscription is active until ' . date('d M Y', strtotime($newExpiry)) . '.');
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
        Audit::log(null, 'sysadmin', $adminId, 'admin.payment_verify', 'payments', (string) $paymentId, ['amount' => (int) $pay['amount_ugx']]);
        return ['status' => 'verified', 'subscription_expires_at' => $newExpiry];
    }

    /**
     * Create a shop account on behalf of a shop (platform admin action).
     * Same structure as self-registration: tenant + owner login + trial,
     * but the admin stays signed in and credentials are returned once.
     */
    public static function createShop(array $in, int $adminId): array
    {
        $owner    = Input::need($in, 'owner_name', 160);
        $shopName = Input::need($in, 'name', 160);
        $phone    = Registration::phone(Input::need($in, 'phone', 30));
        $username = strtolower(Input::need($in, 'username', 60));
        $password = (string) ($in['password'] ?? '');

        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            throw new AppException('Username may contain letters, numbers, dot, dash and underscore only (3-60 characters).', 422, 'VALIDATION');
        }
        if (strlen($password) < 6) {
            throw new AppException('Choose a password of at least 6 characters.', 422, 'VALIDATION');
        }
        if (Db::val('SELECT 1 FROM shop_users WHERE username = ?', [$username])) {
            throw new AppException('That username is already taken. Please choose another.', 409, 'DUPLICATE');
        }

        $planCode = Input::str($in, 'plan', (string) Config::get('subscription.default_plan', 'basic'), 30);
        $plan = Db::one('SELECT id, price_ugx FROM subscription_plans WHERE code = ? AND is_active = 1', [$planCode]);
        if (!$plan) {
            $plan = Db::one('SELECT id, price_ugx FROM subscription_plans ORDER BY price_ugx LIMIT 1');
        }
        $trialDays = (int) Config::get('subscription.trial_days', 14);

        Db::begin();
        try {
            Db::exec(
                'INSERT INTO shops (shop_uid, name, owner_name, phone, email, location, business_category,
                        preferred_language, status, plan_id, subscription_status, subscription_started_at, subscription_expires_at,
                        grace_days, offline_grace_days)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    Db::shopUid(), $shopName, $owner, $phone,
                    Input::str($in, 'email', '', 160) ?: null,
                    Input::need($in, 'location', 200),
                    Input::str($in, 'business_category', 'Retail shop', 80),
                    in_array(Input::str($in, 'language', 'en', 5), Config::get('app.locales'), true) ? Input::str($in, 'language', 'en', 5) : 'en',
                    'active',
                    $plan['id'] ?? null,
                    'trial',
                    Db::now(),
                    date('Y-m-d H:i:s', time() + $trialDays * 86400),
                    (int) Config::get('subscription.default_grace_days', 5),
                    (int) Config::get('subscription.offline_grace_days', 3),
                ]
            );
            $shopId = Db::id();

            $ownerRole = Db::one('SELECT id FROM roles WHERE code = "owner"');
            Db::exec(
                'INSERT INTO shop_users (shop_id, full_name, username, phone, password_hash, role_id, is_active)
                 VALUES (?,?,?,?,?,?,1)',
                [$shopId, $owner, $username, $phone,
                 password_hash($password, PASSWORD_BCRYPT, ['cost' => (int) Config::get('security.bcrypt_cost', 11)]),
                 $ownerRole['id'] ?? 1]
            );

            foreach (['Beverages', 'Food & Groceries', 'Household', 'Stationery'] as $cat) {
                Db::exec('INSERT INTO product_categories (shop_id, name, client_uuid) VALUES (?,?,?)',
                    [$shopId, $cat, Db::uuid()]);
            }

            Notify::push($shopId, 'system', 'Welcome to SmartBook',
                "Your free trial runs until " . date('d M Y', time() + $trialDays * 86400) . ". Add your products to get started.");

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            if ($e instanceof AppException) {
                throw $e;
            }
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new AppException('That username is already taken. Please choose another.', 409, 'DUPLICATE');
            }
            throw $e;
        }

        Audit::log($shopId, 'sysadmin', $adminId, 'admin.shop_create', 'shops', (string) $shopId, ['plan' => $planCode]);

        return [
            'shop_id'   => $shopId,
            'shop_uid'  => (string) Db::val('SELECT shop_uid FROM shops WHERE id = ?', [$shopId]),
            'name'      => $shopName,
            'username'  => $username,
            'expires_at'=> date('d M Y', time() + $trialDays * 86400),
        ];
    }

    public static function announcement(array $in, int $adminId): array
    {
        Db::exec('INSERT INTO announcements (title, body, created_by) VALUES (?,?,?)',
            [Input::need($in, 'title', 160), Input::need($in, 'body', 4000), $adminId]);
        $id = Db::id();
        // Broadcast into every shop's notification centre.
        foreach (Db::all('SELECT id FROM shops') as $s) {
            Notify::push((int) $s['id'], 'system', Input::str($in, 'title', '', 160), Input::str($in, 'body', '', 400));
        }
        return ['id' => $id];
    }

    public static function announcements(): array
    {
        return Db::all('SELECT a.*, s.full_name AS author FROM announcements a
                          LEFT JOIN system_administrators s ON s.id = a.created_by
                         ORDER BY a.created_at DESC LIMIT 50');
    }

    public static function support(array $q): array
    {
        return Db::all(
            'SELECT r.*, s.name AS shop_name FROM support_requests r LEFT JOIN shops s ON s.id = r.shop_id
              ORDER BY r.status = "closed", r.created_at DESC LIMIT 100'
        );
    }

    public static function logs(int $limit = 200): array
    {
        return Db::all(
            'SELECT a.id, a.action, a.entity, a.entity_id, a.ip_address, a.created_at, a.actor_type,
                    COALESCE(u.full_name, sa.full_name, "System") AS actor, s.name AS shop_name
               FROM audit_logs a
               LEFT JOIN shop_users u ON u.id = a.actor_id AND a.actor_type = "shop_user"
               LEFT JOIN system_administrators sa ON sa.id = a.actor_id AND a.actor_type = "sysadmin"
               LEFT JOIN shops s ON s.id = a.shop_id
              ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    public static function admins(): array
    {
        return Db::all('SELECT id, full_name, username, email, is_active, last_login_at, created_at FROM system_administrators ORDER BY id');
    }

    public static function createAdmin(array $in, int $actorId): array
    {
        $username = strtolower(Input::need($in, 'username', 60));
        if (Db::val('SELECT 1 FROM system_administrators WHERE username = ? OR email = ?', [$username, Input::str($in, 'email', '')])) {
            throw new AppException('That username or email already exists.', 409, 'DUPLICATE');
        }
        Db::exec('INSERT INTO system_administrators (full_name, username, email, password_hash) VALUES (?,?,?,?)', [
            Input::need($in, 'full_name', 120),
            $username,
            Input::need($in, 'email', 160),
            password_hash(Input::need($in, 'password', 200), PASSWORD_BCRYPT, ['cost' => (int) Config::get('security.bcrypt_cost', 11)]),
        ]);
        Audit::log(null, 'sysadmin', $actorId, 'admin.create_admin', 'system_administrators', (string) Db::id());
        return ['id' => Db::id()];
    }
}
