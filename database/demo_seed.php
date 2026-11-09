<?php
declare(strict_types=1);

/**
 * Demonstration seeder - creates realistic sample shops at Mile 4, Mbarara.
 *
 * Every row it creates belongs to a shop with is_sample = 1, so the interface
 * can always label it as demonstration data. Safe to re-run: it skips shops
 * whose username already exists.
 *
 * Run:  php database/demo_seed.php
 */
require __DIR__ . '/../backend/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$DEMO = [
    [
        'shop' => ['name' => 'Nakumatt Mini Store', 'owner_name' => 'Grace Atuhaire', 'phone' => '0772113401',
                   'location' => 'Mile 4, Mbarara - Bushenyi Road', 'category' => 'Retail shop', 'plan' => 'standard',
                   'username' => 'grace', 'password' => 'Demo@2024'],
        'products' => [
            // name, category, buy, sell, opening, min, barcode
            ['Sugar Kabuye 1kg', 'Food & Groceries', 3800, 4500, 60, 15, '6001234500011'],
            ['Posho (Maize flour) 2kg', 'Food & Groceries', 4200, 5000, 40, 10, '6001234500028'],
            ['Cooking Oil 1L', 'Food & Groceries', 7500, 9000, 24, 8, '6001234500035'],
            ['Coca-Cola 500ml', 'Beverages', 1500, 2000, 96, 24, '6001234500042'],
            ['Rwenzori Water 500ml', 'Beverages', 700, 1000, 120, 30, '6001234500059'],
            ['Salt 500g', 'Food & Groceries', 900, 1300, 50, 12, '6001234500066'],
            ['Bar Soap (Sunlight)', 'Household', 2200, 3000, 36, 10, '6001234500073'],
            ['Omo 500g', 'Household', 4800, 6000, 18, 6, '6001234500080'],
            ['Exercise Book 48pg', 'Stationery', 700, 1200, 80, 20, '6001234500097'],
            ['Blue Pen', 'Stationery', 200, 500, 150, 40, '6001234500103'],
            ['Bread 400g', 'Food & Groceries', 2500, 3200, 12, 6, null],
            ['Milk 500ml', 'Beverages', 1600, 2200, 20, 8, null]
        ],
        'customers' => [['John Mugisha', '0772445566', 12000], ['Sarah Kyomuhendo', '0700334455', 5000], ['Denis Tumusiime', '0788112233', 0]],
        'expenses' => [['rent', 'Monthly shop rent', 180000], ['electricity', 'Umeme token', 30000], ['transport', 'Boda to wholesale market', 12000]]
    ],
    [
        'shop' => ['name' => 'Mile 4 Hardware & General', 'owner_name' => 'Robert Byaruhanga', 'phone' => '0752889900',
                   'location' => 'Mile 4, Mbarara - Bushenyi Road', 'category' => 'Hardware', 'plan' => 'basic',
                   'username' => 'robert', 'password' => 'Demo@2024'],
        'products' => [
            ['Cement 50kg', 'Hardware', 32000, 37000, 40, 10, '6009876500011'],
            ['Iron Sheets 28g', 'Hardware', 28000, 34000, 25, 6, '6009876500028'],
            ['Nails 1kg (4 inch)', 'Hardware', 6000, 8500, 30, 8, '6009876500035'],
            ['Padlock Medium', 'Hardware', 7000, 12000, 15, 5, '6009876500042'],
            ['Paint 4L (White)', 'Hardware', 38000, 48000, 8, 3, '6009876500059'],
            ['Hoe', 'Hardware', 12000, 18000, 10, 4, null],
            ['Wheelbarrow', 'Hardware', 85000, 115000, 4, 2, null],
            ['PVC Pipe 3m', 'Hardware', 14000, 19000, 12, 4, null]
        ],
        'customers' => [['Kato Builders Ltd', '0700111222', 240000], ['Mbarara Youth SACCO', '0755333444', 60000]],
        'expenses' => [['rent', 'Monthly shop rent', 150000], ['transport', 'Lorry hire - Kampala', 90000]]
    ]
];

foreach ($DEMO as $spec) {
    $username = $spec['shop']['username'];
    if (Db::val('SELECT 1 FROM shop_users WHERE username = ?', [$username])) {
        echo "skip {$username} (already exists)\n";
        continue;
    }

    $plan = Db::one('SELECT id, price_ugx FROM subscription_plans WHERE code = ?', [$spec['shop']['plan']]);
    Db::exec(
        'INSERT INTO shops (shop_uid, name, owner_name, phone, email, location, business_category, preferred_language,
                status, is_sample, plan_id, subscription_status, subscription_started_at, subscription_expires_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [Db::shopUid(), $spec['shop']['name'], $spec['shop']['owner_name'], $spec['shop']['phone'],
         strtolower($username) . '@example.ug', $spec['shop']['location'], $spec['shop']['category'], 'en',
         'active', 1, $plan['id'] ?? null, 'active', date('Y-m-d H:i:s', strtotime('-20 days')),
         date('Y-m-d H:i:s', strtotime('+40 days'))]
    );
    $shopId = Db::id();

    $ownerRole = Db::val('SELECT id FROM roles WHERE code = "owner"');
    Db::exec('INSERT INTO shop_users (shop_id, full_name, username, phone, password_hash, role_id) VALUES (?,?,?,?,?,?)',
        [$shopId, $spec['shop']['owner_name'], $username, $spec['shop']['phone'],
         password_hash($spec['shop']['password'], PASSWORD_BCRYPT, ['cost' => 11]), $ownerRole]);

    // additional staff
    $cashierRole = Db::val('SELECT id FROM roles WHERE code = "cashier"');
    Db::exec('INSERT INTO shop_users (shop_id, full_name, username, phone, password_hash, role_id) VALUES (?,?,?,?,?,?)',
        [$shopId, 'Cashier ' . substr($spec['shop']['name'], 0, 12), $username . '_cashier', '0770000001',
         password_hash('Cashier@2024', PASSWORD_BCRYPT, ['cost' => 11]), $cashierRole]);

    $catIds = [];
    $oldCats = Db::all('SELECT id, name FROM product_categories WHERE shop_id = ?', [$shopId]);
    foreach ($oldCats as $oc) {
        $catIds[$oc['name']] = (int) $oc['id'];
    }

    $productIds = [];
    foreach ($spec['products'] as [$name, $cat, $buy, $sell, $qty, $min, $barcode]) {
        if (!isset($catIds[$cat])) {
            Db::exec('INSERT INTO product_categories (shop_id, name, client_uuid) VALUES (?,?,?)', [$shopId, $cat, Db::uuid()]);
            $catIds[$cat] = Db::id();
        }
        Db::exec(
            'INSERT INTO products (shop_id, client_uuid, name, category_id, barcode, unit, purchase_price, selling_price, min_stock, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,1)',
            [$shopId, Db::uuid(), $name, $catIds[$cat], $barcode, 'pcs', $buy, $sell, $min]
        );
        $pid = Db::id();
        StockService::move($shopId, [
            'client_uuid'   => Db::uuid(),
            'product_id'    => $pid,
            'movement_type' => 'opening',
            'qty_change'    => $qty,
            'unit_cost'     => $buy,
            'reason'        => 'Opening stock (sample data)',
        ]);
        $productIds[] = ['id' => $pid, 'sell' => $sell, 'name' => $name];
    }

    $custIds = [];
    foreach ($spec['customers'] as [$cname, $cphone, $owed]) {
        Db::exec('INSERT INTO customers (shop_id, client_uuid, name, phone, balance) VALUES (?,?,?,?,0)',
            [$shopId, Db::uuid(), $cname, $cphone]);
        $custIds[] = ['id' => Db::id(), 'owed' => $owed];
    }

    // ~55 back-dated sales across the last 30 days
    $ownerId = (int) Db::val('SELECT id FROM shop_users WHERE shop_id = ? ORDER BY id LIMIT 1', [$shopId]);
    mt_srand(crc32($username));
    for ($i = 0; $i < 55; $i++) {
        $daysAgo = mt_rand(0, 29);
        $at = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days " . mt_rand(8, 19) . ':' . mt_rand(10, 59)));
        $picked = [];
        $n = mt_rand(1, 3);
        for ($k = 0; $k < $n; $k++) {
            $p = $productIds[mt_rand(0, count($productIds) - 1)];
            $picked[$p['id']] = ['product_id' => $p['id'], 'quantity' => mt_rand(1, 4), 'unit_price' => $p['sell']];
        }
        $method = ['cash', 'cash', 'cash', 'mtn_momo', 'airtel_money', 'credit'][mt_rand(0, 5)];
        $items = array_values($picked);
        // top up opening stock so sample sales never hit zero artificially
        try {
            ShopService::createSale($shopId, [
                'client_uuid'    => Db::uuid(),
                'payment_method' => $method,
                'customer_id'    => $method === 'credit' ? $custIds[mt_rand(0, count($custIds) - 1)]['id'] : null,
                'sold_at'        => $at,
                'items'          => $items,
            ], $ownerId, 'seed');
        } catch (Throwable $e) {
            // stock guard tripped - skip this generated sale
        }
    }

    foreach ($spec['expenses'] as [$cat, $desc, $amount]) {
        Db::exec('INSERT INTO expenses (shop_id, client_uuid, category, description, amount, spent_at, employee_id)
                  VALUES (?,?,?,?,?,?,?)',
            [$shopId, Db::uuid(), $cat, $desc, $amount, date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 20) . ' days')), $ownerId]);
    }

    Notify::push($shopId, 'system', 'Sample shop ready',
        'This is demonstration data for piloting at Mile 4. Replace it with your own products before real use.');

    echo "created sample shop: {$spec['shop']['name']} (id {$shopId})\n";
}

echo "done.\n";
