<?php
declare(strict_types=1);

/** Shop self-registration: creates the tenant, its owner login and a trial. */
final class Registration
{
    public static function createShop(array $in): array
    {
        $owner    = Input::need($in, 'owner_name', 160);
        $shopName = Input::need($in, 'name', 160);
        $phone    = self::phone(Input::need($in, 'phone', 30));
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

            // A brand new shop starts with a few sensible categories so the POS
            // is usable immediately without an empty-state dead end.
            foreach (['Beverages', 'Food & Groceries', 'Household', 'Stationery'] as $cat) {
                Db::exec('INSERT INTO product_categories (shop_id, name, client_uuid) VALUES (?,?,?)', [$shopId, $cat, Db::uuid()]);
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

        Audit::log($shopId, 'system', null, 'shop.register', 'shops', (string) $shopId, ['plan' => $planCode]);

        $session = Auth::loginShop($username, $password, Input::str($in, 'device_id', '', 64) ?: null);
        return $session + ['shop_created' => true];
    }

    /** Accept 0772..., +256772..., 256772... and normalise to +256... */
    public static function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($digits, '0')) {
            $digits = '256' . substr($digits, 1);
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $digits = '256' . $digits;
        }
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw new AppException('Enter a valid phone number, e.g. 0772123456.', 422, 'VALIDATION');
        }
        return '+' . $digits;
    }
}
