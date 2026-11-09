<?php
declare(strict_types=1);

final class Rbac
{
    public const PERMISSIONS = [
        'dashboard.view'    => 'View dashboard',
        'products.view'     => 'View products',
        'products.manage'   => 'Add / edit / deactivate products',
        'stock.adjust'      => 'Record stock adjustments and losses',
        'sales.create'      => 'Record sales (POS)',
        'sales.view'        => 'View sales and receipts',
        'sales.cancel'      => 'Cancel / return a sale',
        'customers.view'    => 'View customers and credit',
        'customers.manage'  => 'Add / edit customers and record credit payments',
        'suppliers.view'    => 'View suppliers',
        'suppliers.manage'  => 'Manage suppliers and purchase orders',
        'expenses.view'     => 'View expenses',
        'expenses.manage'   => 'Record expenses',
        'reports.view'      => 'View and export reports',
        'analytics.view'    => 'View smart analytics and forecasts',
        'employees.manage'  => 'Manage employee accounts',
        'settings.manage'   => 'Shop settings, backup and restore',
        'audit.view'        => 'View activity logs',
        'subscription.manage' => 'Manage subscription and payments',
        'sync.use'          => 'Use offline synchronisation',
    ];

    /** role code => permissions */
    private const MAP = [
        'owner' => ['*'],
        'manager' => [
            'dashboard.view', 'products.view', 'products.manage', 'stock.adjust', 'sales.create', 'sales.view',
            'sales.cancel', 'customers.view', 'customers.manage', 'suppliers.view', 'suppliers.manage',
            'expenses.view', 'expenses.manage', 'reports.view', 'analytics.view', 'employees.manage',
            'audit.view', 'sync.use',
        ],
        'cashier' => ['dashboard.view', 'products.view', 'sales.create', 'sales.view', 'customers.view', 'sync.use'],
        'storekeeper' => [
            'dashboard.view', 'products.view', 'products.manage', 'stock.adjust', 'suppliers.view',
            'suppliers.manage', 'analytics.view', 'sync.use',
        ],
        'accountant' => [
            'dashboard.view', 'products.view', 'sales.view', 'customers.view', 'customers.manage',
            'expenses.view', 'expenses.manage', 'reports.view', 'analytics.view', 'subscription.manage', 'sync.use',
        ],
    ];

    /** Permission list for a role id, resolved from the DB (role_permissions). */
    public static function permissionsFor(int $roleId): array
    {
        static $cache = [];
        if (isset($cache[$roleId])) {
            return $cache[$roleId];
        }
        $code = Db::val('SELECT code FROM roles WHERE id = ?', [$roleId], null);
        if ($code === 'owner') {
            return $cache[$roleId] = ['*'];
        }
        $perms = array_column(
            Db::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$roleId]),
            'code'
        );
        if (!$perms && $code && isset(self::MAP[$code])) {
            $perms = self::MAP[$code];
        }
        return $cache[$roleId] = $perms;
    }
}

/**
 * Tenant + subscription policy.
 *
 * Policy (from the brief): an expired shop keeps READ access to all of its
 * business records; only NEW transactions and premium features are blocked.
 * Records are never deleted or hidden because of non-payment.
 */
final class Shop
{
    public static function gate(int $shopId): array
    {
        $s = Db::one(
            'SELECT id, shop_uid, name, owner_name, phone, email, location, business_category, preferred_language,
                    status, is_sample, plan_id, subscription_status, subscription_started_at,
                    subscription_expires_at, grace_days, offline_grace_days
               FROM shops WHERE id = ?',
            [$shopId]
        );
        if (!$s) {
            throw new AppException('Shop not found.', 404, 'NOT_FOUND');
        }
        $s['id'] = (int) $s['id'];
        $s['is_sample'] = (int) $s['is_sample'];
        $s['grace_days'] = (int) $s['grace_days'];
        $s['offline_grace_days'] = (int) $s['offline_grace_days'];
        $s['plan_id'] = $s['plan_id'] ? (int) $s['plan_id'] : null;
        return $s;
    }

    public static function profile(int $shopId): array
    {
        $s = self::gate($shopId);
        $plan = $s['plan_id'] ? Db::one('SELECT id, code, name, price_ugx, max_users, features FROM subscription_plans WHERE id = ?', [$s['plan_id']]) : null;
        if ($plan) {
            $plan['price_ugx'] = (int) $plan['price_ugx'];
            $plan['max_users'] = (int) $plan['max_users'];
            $plan['features'] = json_decode((string) $plan['features'], true) ?: [];
        }
        $state = self::subscriptionState($s);
        return [
            'id'          => $s['id'],
            'shop_uid'    => $s['shop_uid'],
            'name'        => $s['name'],
            'owner_name'  => $s['owner_name'],
            'phone'       => $s['phone'],
            'email'       => $s['email'],
            'location'    => $s['location'],
            'category'    => $s['business_category'],
            'language'    => $s['preferred_language'],
            'status'      => $s['status'],
            'is_sample'   => $s['is_sample'],
            'plan'        => $plan,
            'subscription'=> $state,
        ];
    }

    /**
     * Subscription state machine.
     *   trial   -> active -> grace -> expired   (never deletes data)
     */
    public static function subscriptionState(array $shop): array
    {
        $now = time();
        $expires = $shop['subscription_expires_at'] ? strtotime((string) $shop['subscription_expires_at']) : null;
        $graceDays = (int) ($shop['grace_days'] ?? Config::get('subscription.default_grace_days', 5));
        $offlineDays = (int) ($shop['offline_grace_days'] ?? Config::get('subscription.offline_grace_days', 3));
        $status = (string) ($shop['subscription_status'] ?? 'trial');

        if ($shop['status'] === 'suspended') {
            $status = 'suspended';
        } elseif ($expires !== null) {
            if ($now <= $expires) {
                $status = 'active';
            } elseif ($now <= $expires + $graceDays * 86400) {
                $status = 'grace';
            } else {
                $status = 'expired';
            }
        }

        $daysLeft = $expires ? (int) ceil(($expires - $now) / 86400) : null;
        // What a device may keep doing with no network at all.
        $offlineUntil = $expires ? $expires + $offlineDays * 86400 : $now + 30 * 86400;

        return [
            'status'            => $status,
            'expires_at'        => $shop['subscription_expires_at'],
            'days_left'         => $daysLeft,
            'grace_days'        => $graceDays,
            'offline_grace_days'=> $offlineDays,
            'offline_write_until' => date('c', $offlineUntil),
            // The single flag the server enforces on mutating requests.
            'writes_allowed'    => in_array($status, ['trial', 'active', 'grace'], true),
            'message'           => self::message($status, $daysLeft),
        ];
    }

    private static function message(string $status, ?int $days): string
    {
        switch ($status) {
            case 'trial':
                return 'You are on a free trial. All features are available.';
            case 'active':
                return $days !== null && $days <= 5
                    ? "Your subscription expires in {$days} day(s). Renew to avoid interruption."
                    : 'Subscription active.';
            case 'grace':
                return 'Your subscription has expired. You are in the grace period - renew now to keep recording sales.';
            case 'expired':
                return 'Subscription expired. You can still view and export all your records, but new sales are paused until you renew.';
            case 'suspended':
                return 'Account suspended by SmartBook. Your records remain safe and readable.';
            default:
                return '';
        }
    }

    public static function update(int $shopId, array $in): array
    {
        $fields = [];
        $args = [];
        $map = [
            'name' => 160, 'owner_name' => 160, 'phone' => 30, 'email' => 160, 'location' => 200,
            'business_category' => 80, 'preferred_language' => 5,
        ];
        foreach ($map as $key => $max) {
            if (array_key_exists($key, $in) && trim((string) $in[$key]) !== '') {
                $fields[] = $key . ' = ?';
                $args[] = mb_substr(trim((string) $in[$key]), 0, $max);
            }
        }
        if (!$fields) {
            throw new AppException('Nothing to update.', 422, 'VALIDATION');
        }
        $args[] = Db::now();
        $args[] = $shopId;
        Db::exec('UPDATE shops SET ' . implode(', ', $fields) . ', updated_at = ? WHERE id = ?', $args);
        return self::profile($shopId);
    }

    /** Block NEW transactions once the subscription has fully lapsed. Reads always pass. */
    public static function assertCanWrite(array $ctx): void
    {
        $state = self::subscriptionState($ctx['shop'] + [
            'status' => $ctx['shop']['status'] ?? 'active',
            'grace_days' => $ctx['shop']['grace_days'] ?? 5,
            'offline_grace_days' => $ctx['shop']['offline_grace_days'] ?? 3,
        ]);
        if (!$state['writes_allowed']) {
            throw new AppException($state['message'], 402, 'SUBSCRIPTION_EXPIRED', ['subscription' => $state]);
        }
    }
}

final class Audit
{
    public static function log(?int $shopId, string $actorType, ?int $actorId, string $action, ?string $entity = null, ?string $entityId = null, array $meta = []): void
    {
        try {
            Db::exec(
                'INSERT INTO audit_logs (shop_id, actor_type, actor_id, action, entity, entity_id, ip_address, meta)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$shopId, $actorType, $actorId, $action, $entity, $entityId, self::ip(), $meta ? json_encode($meta) : null]
            );
        } catch (Throwable $e) {
            // Auditing must never break the business operation it describes.
        }
    }

    public static function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                return substr(trim(explode(',', (string) $_SERVER[$k])[0]), 0, 45);
            }
        }
        return 'cli';
    }
}

final class Notify
{
    public static function push(int $shopId, string $type, string $title, string $body = ''): void
    {
        Db::exec(
            'INSERT INTO notifications (shop_id, type, title, body) VALUES (?,?,?,?)',
            [$shopId, $type, mb_substr($title, 0, 160), mb_substr($body, 0, 400)]
        );
    }
}
