<?php
declare(strict_types=1);

/**
 * Shop operations: products, sales, customers/credit, suppliers, expenses,
 * reports, dashboard.
 *
 * Every method receives $shopId from the resolved credential (never from the
 * request) and every statement filters on it. That is the tenant boundary.
 */
final class ShopService
{
    /* ================================================================== */
    /* PRODUCTS                                                           */
    /* ================================================================== */

    public static function listProducts(int $shopId, array $q): array
    {
        $where = ['p.shop_id = ?', 'p.is_deleted = 0'];
        $params = [$shopId];

        if (!empty($q['search'])) {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $q['search'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($q['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int) $q['category_id'];
        }
        if (($q['stock'] ?? '') === 'low') {
            $where[] = 'p.stock_qty > 0 AND p.stock_qty <= p.min_stock';
        } elseif (($q['stock'] ?? '') === 'out') {
            $where[] = 'p.stock_qty <= 0';
        }
        if (($q['active_only'] ?? '') === '1') {
            $where[] = 'p.is_active = 1';
        }

        $rows = Db::all(
            'SELECT p.id, p.client_uuid, p.sku, p.name, p.barcode, p.unit, p.purchase_price, p.selling_price,
                    p.stock_qty, p.min_stock, p.expiry_date, p.is_active, p.image_path, p.category_id,
                    c.name AS category, p.updated_at
               FROM products p
               LEFT JOIN product_categories c ON c.id = p.category_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY p.name ASC
              LIMIT 500',
            $params
        );
        foreach ($rows as &$r) {
            $r['stock_status'] = (float) $r['stock_qty'] <= 0 ? 'out' : ((float) $r['stock_qty'] <= (float) $r['min_stock'] ? 'low' : 'ok');
            $r['margin'] = (float) $r['selling_price'] - (float) $r['purchase_price'];
        }
        return $rows;
    }

    public static function saveProduct(int $shopId, array $in, bool $isNew): array
    {
        $uuid = Input::uuid($in['client_uuid'] ?? null);
        $name = Input::need($in, 'name', 160);
        $selling = Input::num($in, 'selling_price');
        if ($selling < 0) {
            throw new AppException('Selling price cannot be negative.', 422, 'VALIDATION');
        }
        $categoryId = Input::int($in, 'category_id') ?: null;
        if ($categoryId) {
            $ok = Db::val('SELECT 1 FROM product_categories WHERE id = ? AND shop_id = ?', [$categoryId, $shopId]);
            if (!$ok) {
                throw new AppException('That category does not belong to this shop.', 422, 'VALIDATION');
            }
        }
        $barcode = Input::str($in, 'barcode', '', 80) ?: null;

        $fields = [
            'name'           => $name,
            'sku'            => Input::str($in, 'sku', '', 60) ?: null,
            'category_id'    => $categoryId,
            'barcode'        => $barcode,
            'unit'           => Input::str($in, 'unit', 'pcs', 20),
            'purchase_price' => Input::num($in, 'purchase_price'),
            'selling_price'  => $selling,
            'min_stock'      => Input::num($in, 'min_stock'),
            'description'    => Input::str($in, 'description', '', 400) ?: null,
            'expiry_date'    => Input::date($in, 'expiry_date') ? substr(Input::date($in, 'expiry_date'), 0, 10) : null,
            'is_active'      => Input::bool($in, 'is_active', true) ? 1 : 0,
            'updated_at'     => Db::now(),
        ];

        if (!$isNew) {
            $existing = Db::one('SELECT id FROM products WHERE id = ? AND shop_id = ?', [Input::int($in, 'id'), $shopId]);
            if (!$existing) {
                throw new AppException('Product not found in this shop.', 404, 'PRODUCT_NOT_FOUND');
            }
            Db::exec(
                'UPDATE products SET name=?, sku=?, category_id=?, barcode=?, unit=?, purchase_price=?, selling_price=?,
                        min_stock=?, description=?, expiry_date=?, is_active=?, updated_at=? WHERE id=? AND shop_id=?',
                array_merge(array_values($fields), [$existing['id'], $shopId])
            );
            return self::getProduct($shopId, (int) $existing['id']);
        }

        // Idempotent on client_uuid (offline created products)
        $dup = Db::one('SELECT id FROM products WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($dup) {
            $in['id'] = (int) $dup['id'];
            return self::saveProduct($shopId, $in, false);
        }

        $openingQty = Input::num($in, 'opening_qty', 0);
        Db::exec(
            'INSERT INTO products (shop_id, client_uuid, name, sku, category_id, barcode, unit, purchase_price,
                    selling_price, min_stock, description, expiry_date, is_active, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge([$shopId, $uuid], array_values($fields))
        );
        $id = Db::id();

        if ($openingQty != 0.0) {
            StockService::move($shopId, [
                'client_uuid'   => $uuid . '-opening',
                'product_id'    => $id,
                'movement_type' => 'opening',
                'qty_change'    => $openingQty,
                'unit_cost'     => Input::num($in, 'purchase_price'),
                'reason'        => 'Opening stock',
            ]);
        }
        return self::getProduct($shopId, $id);
    }

    public static function getProduct(int $shopId, int $id): array
    {
        $p = Db::one(
            'SELECT p.*, c.name AS category FROM products p
              LEFT JOIN product_categories c ON c.id = p.category_id
             WHERE p.id = ? AND p.shop_id = ?',
            [$id, $shopId]
        );
        if (!$p) {
            throw new AppException('Product not found in this shop.', 404, 'PRODUCT_NOT_FOUND');
        }
        $p['movements'] = Db::all(
            'SELECT m.id, m.movement_type, m.qty_change, m.reference, m.reason, m.created_at, u.full_name AS employee
               FROM inventory_movements m LEFT JOIN shop_users u ON u.id = m.employee_id
              WHERE m.shop_id = ? AND m.product_id = ?
              ORDER BY m.created_at DESC, m.id DESC LIMIT 60',
            [$shopId, $id]
        );
        return $p;
    }

    public static function deleteProduct(int $shopId, int $id): array
    {
        Db::exec('UPDATE products SET is_deleted = 1, is_active = 0, updated_at = ? WHERE id = ? AND shop_id = ?', [Db::now(), $id, $shopId]);
        return ['deleted' => true];
    }

    public static function categories(int $shopId): array
    {
        return Db::all(
            'SELECT c.id, c.name, c.client_uuid, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_deleted = 0) AS products
               FROM product_categories c WHERE c.shop_id = ? AND c.is_deleted = 0 ORDER BY c.name',
            [$shopId]
        );
    }

    public static function saveCategory(int $shopId, array $in): array
    {
        $name = Input::need($in, 'name', 100);
        $uuid = Input::uuid($in['client_uuid'] ?? null);
        if (Input::int($in, 'id')) {
            Db::exec('UPDATE product_categories SET name = ?, updated_at = ? WHERE id = ? AND shop_id = ?',
                [$name, Db::now(), Input::int($in, 'id'), $shopId]);
            return Db::one('SELECT * FROM product_categories WHERE id = ? AND shop_id = ?', [Input::int($in, 'id'), $shopId]) ?: [];
        }
        $dup = Db::one('SELECT id FROM product_categories WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($dup) {
            return Db::one('SELECT * FROM product_categories WHERE id = ?', [$dup['id']]) ?: [];
        }
        Db::exec('INSERT INTO product_categories (shop_id, name, client_uuid) VALUES (?,?,?)', [$shopId, $name, $uuid]);
        return Db::one('SELECT * FROM product_categories WHERE id = ?', [Db::id()]) ?: [];
    }

    /* ================================================================== */
    /* SALES  (works identically online and from the offline queue)        */
    /* ================================================================== */

    /**
     * Record a sale. Idempotent on client_uuid: replaying the same offline
     * sale returns the stored sale and never double-deducts stock.
     */
    public static function createSale(int $shopId, array $in, int $userId, ?string $deviceId = null): array
    {
        $uuid = Input::uuid($in['client_uuid'] ?? null);

        $existing = Db::one('SELECT * FROM sales WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($existing) {
            return ['sale' => self::getSale($shopId, (int) $existing['id']), 'duplicate' => true];
        }

        $items = $in['items'] ?? [];
        if (!is_array($items) || !$items) {
            throw new AppException('A sale needs at least one item.', 422, 'VALIDATION');
        }
        if (count($items) > 60) {
            throw new AppException('Too many items in one sale.', 422, 'VALIDATION');
        }

        $method = Input::str($in, 'payment_method', 'cash', 20);
        if (!in_array($method, ['cash', 'mtn_momo', 'airtel_money', 'credit'], true)) {
            throw new AppException('Unknown payment method.', 422, 'VALIDATION');
        }

        $customerId = Input::int($in, 'customer_id') ?: null;
        if ($method === 'credit' && !$customerId) {
            throw new AppException('Choose a customer for a credit sale.', 422, 'VALIDATION');
        }
        if ($customerId) {
            $ok = Db::val('SELECT 1 FROM customers WHERE id = ? AND shop_id = ? AND is_deleted = 0', [$customerId, $shopId]);
            if (!$ok) {
                throw new AppException('Customer not found in this shop.', 422, 'VALIDATION');
            }
        }

        Db::begin();
        try {
            $subtotal = 0.0;
            $prepared = [];
            foreach ($items as $line) {
                $qty = (float) ($line['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new AppException('Every item needs a quantity greater than zero.', 422, 'VALIDATION');
                }
                $p = Db::one(
                    'SELECT id, name, selling_price, purchase_price, stock_qty FROM products
                      WHERE id = ? AND shop_id = ? AND is_deleted = 0',
                    [(int) ($line['product_id'] ?? 0), $shopId]
                );
                if (!$p) {
                    throw new AppException('A product in this sale is no longer available in your shop.', 422, 'VALIDATION');
                }
                // Allow negative stock only when the shop opted in; default is a hard stop.
                if ((float) $p['stock_qty'] < $qty && !Input::bool($in, 'allow_negative_stock', false)) {
                    throw new AppException(
                        sprintf('Not enough stock for %s: %s available, %s requested.', $p['name'], rtrim(rtrim(number_format((float) $p['stock_qty'], 3), '0'), '.'), rtrim(rtrim(number_format($qty, 3), '0'), '.')),
                        409,
                        'INSUFFICIENT_STOCK',
                        ['product_id' => (int) $p['id'], 'available' => (float) $p['stock_qty']]
                    );
                }
                $unitPrice = isset($line['unit_price']) && $line['unit_price'] !== '' ? (float) $line['unit_price'] : (float) $p['selling_price'];
                $lineTotal = round($unitPrice * $qty, 2);
                $subtotal += $lineTotal;
                $prepared[] = [
                    'product_id' => (int) $p['id'],
                    'name'       => $p['name'],
                    'qty'        => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost'  => (float) $p['purchase_price'],
                    'line_total' => $lineTotal,
                ];
            }

            $discount = round(max(0, Input::num($in, 'discount')), 2);
            if ($discount > $subtotal) {
                throw new AppException('Discount cannot be larger than the sale total.', 422, 'VALIDATION');
            }
            $total = round($subtotal - $discount, 2);
            $soldAt = $in['sold_at'] ?? Db::now();
            $soldAt = date('Y-m-d H:i:s', strtotime((string) $soldAt) ?: time());
            $paid = $method === 'credit' ? round(Input::num($in, 'amount_paid', 0), 2) : $total;
            if ($paid > $total) {
                $paid = $total;
            }
            $saleNo = Input::str($in, 'sale_no', '', 40) ?: self::nextSaleNo($shopId);
            $status = $method === 'credit' && $paid < $total ? 'credit' : 'completed';

            Db::exec(
                'INSERT INTO sales (shop_id, client_uuid, sale_no, customer_id, employee_id, subtotal, discount, total,
                        amount_paid, payment_method, status, note, device_id, sold_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$shopId, $uuid, $saleNo, $customerId, $userId, $subtotal, $discount, $total, $paid,
                 $method, $status, Input::str($in, 'note', '', 255) ?: null, $deviceId, $soldAt]
            );
            $saleId = Db::id();

            foreach ($prepared as $line) {
                Db::exec(
                    'INSERT INTO sale_items (shop_id, sale_id, product_id, product_name, quantity, unit_price, unit_cost, line_total)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$shopId, $saleId, $line['product_id'], $line['name'], $line['qty'], $line['unit_price'], $line['unit_cost'], $line['line_total']]
                );
                StockService::move($shopId, [
                    'client_uuid'   => $uuid . ':item:' . $line['product_id'],
                    'product_id'    => $line['product_id'],
                    'movement_type' => 'sale',
                    'qty_change'    => -$line['qty'],
                    'unit_cost'     => $line['unit_cost'],
                    'reference'     => $saleNo,
                    'employee_id'   => $userId,
                    'device_id'     => $deviceId,
                ]);
            }

            $onCredit = round($total - $paid, 2);
            if ($onCredit > 0 && $customerId) {
                CreditService::charge($shopId, $customerId, $onCredit, $saleId, $userId, 'Credit sale ' . $saleNo, $soldAt, null);
            }

            Db::commit();
            return ['sale' => self::getSale($shopId, $saleId), 'duplicate' => false];
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public static function nextSaleNo(int $shopId): string
    {
        $seq = (int) Db::val('SELECT COUNT(*) FROM sales WHERE shop_id = ?', [$shopId], 0) + 1;
        do {
            $no = sprintf('S%s-%05d', date('ymd'), $seq);
            $taken = Db::val('SELECT 1 FROM sales WHERE shop_id = ? AND sale_no = ?', [$shopId, $no]);
            $seq++;
        } while ($taken);
        return $no;
    }

    public static function getSale(int $shopId, int $id): array
    {
        $s = Db::one(
            'SELECT s.*, u.full_name AS cashier, c.name AS customer_name, c.phone AS customer_phone
               FROM sales s
               LEFT JOIN shop_users u ON u.id = s.employee_id
               LEFT JOIN customers c  ON c.id = s.customer_id
              WHERE s.id = ? AND s.shop_id = ?',
            [$id, $shopId]
        );
        if (!$s) {
            throw new AppException('Sale not found in this shop.', 404, 'SALE_NOT_FOUND');
        }
        $s['items'] = Db::all('SELECT * FROM sale_items WHERE sale_id = ? AND shop_id = ?', [$id, $shopId]);
        return $s;
    }

    public static function listSales(int $shopId, array $q): array
    {
        $where = ['s.shop_id = ?'];
        $params = [$shopId];
        if (!empty($q['from'])) {
            $where[] = 's.sold_at >= ?';
            $params[] = date('Y-m-d 00:00:00', strtotime((string) $q['from']));
        }
        if (!empty($q['to'])) {
            $where[] = 's.sold_at <= ?';
            $params[] = date('Y-m-d 23:59:59', strtotime((string) $q['to']));
        }
        if (!empty($q['payment_method'])) {
            $where[] = 's.payment_method = ?';
            $params[] = $q['payment_method'];
        }
        $rows = Db::all(
            'SELECT s.id, s.sale_no, s.total, s.amount_paid, s.discount, s.payment_method, s.status, s.sold_at,
                    u.full_name AS cashier, c.name AS customer_name,
                    (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.id) AS item_count
               FROM sales s
               LEFT JOIN shop_users u ON u.id = s.employee_id
               LEFT JOIN customers c ON c.id = s.customer_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY s.sold_at DESC, s.id DESC LIMIT 300',
            $params
        );
        return $rows;
    }

    /** Return / cancel: reverses stock through the same ledger, keeps an audit trail. */
    public static function cancelSale(int $shopId, int $saleId, int $userId, string $reason): array
    {
        $sale = self::getSale($shopId, $saleId);
        if ($sale['status'] === 'cancelled') {
            return $sale;
        }
        Db::begin();
        try {
            $items = Db::all('SELECT * FROM sale_items WHERE sale_id = ? AND shop_id = ?', [$saleId, $shopId]);
            foreach ($items as $i) {
                StockService::move($shopId, [
                    'client_uuid'   => $sale['client_uuid'] . ':return:' . $i['id'],
                    'product_id'    => (int) $i['product_id'],
                    'movement_type' => 'return',
                    'qty_change'    => (float) $i['quantity'],
                    'unit_cost'     => (float) $i['unit_cost'],
                    'reference'     => $sale['sale_no'],
                    'reason'        => $reason,
                    'employee_id'   => $userId,
                ]);
            }
            if ($sale['customer_id'] && (float) $sale['amount_paid'] < (float) $sale['total']) {
                CreditService::pay($shopId, (int) $sale['customer_id'], (float) $sale['total'] - (float) $sale['amount_paid'], $userId, 'Reversal of ' . $sale['sale_no'], null, Db::uuid());
            }
            Db::exec('UPDATE sales SET status = ?, updated_at = ? WHERE id = ? AND shop_id = ?', ['cancelled', Db::now(), $saleId, $shopId]);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
        Audit::log($shopId, 'shop_user', $userId, 'sale.cancel', 'sales', (string) $saleId, ['reason' => $reason]);
        return self::getSale($shopId, $saleId);
    }

    /* ================================================================== */
    /* CUSTOMERS / CREDIT                                                 */
    /* ================================================================== */

    public static function listCustomers(int $shopId, array $q): array
    {
        $where = ['shop_id = ?', 'is_deleted = 0'];
        $params = [$shopId];
        if (!empty($q['search'])) {
            $where[] = '(name LIKE ? OR phone LIKE ?)';
            $params[] = '%' . $q['search'] . '%';
            $params[] = '%' . $q['search'] . '%';
        }
        if (($q['owing'] ?? '') === '1') {
            $where[] = 'balance > 0';
        }
        return Db::all(
            'SELECT *, (SELECT COUNT(*) FROM sales s WHERE s.customer_id = customers.id) AS purchases
               FROM customers WHERE ' . implode(' AND ', $where) . '
              ORDER BY balance DESC, name ASC LIMIT 400',
            $params
        );
    }

    public static function saveCustomer(int $shopId, array $in): array
    {
        $uuid = Input::uuid($in['client_uuid'] ?? null);
        $name = Input::need($in, 'name', 160);
        $dup = Db::one('SELECT id FROM customers WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($dup) {
            $id = (int) $dup['id'];
        } elseif (Input::int($in, 'id')) {
            $id = Input::int($in, 'id');
            if (!Db::val('SELECT 1 FROM customers WHERE id = ? AND shop_id = ?', [$id, $shopId])) {
                throw new AppException('Customer not found in this shop.', 404, 'NOT_FOUND');
            }
        } else {
            Db::exec('INSERT INTO customers (shop_id, client_uuid, name, phone, email, address, credit_limit, notes)
                      VALUES (?,?,?,?,?,?,?,?)', [
                $shopId, $uuid, $name,
                Input::str($in, 'phone', '', 30) ?: null,
                Input::str($in, 'email', '', 160) ?: null,
                Input::str($in, 'address', '', 200) ?: null,
                Input::num($in, 'credit_limit'),
                Input::str($in, 'notes', '', 255) ?: null,
            ]);
            $id = Db::id();
        }
        Db::exec('UPDATE customers SET name=?, phone=?, email=?, address=?, credit_limit=?, notes=?, updated_at=? WHERE id=? AND shop_id=?', [
            $name,
            Input::str($in, 'phone', '', 30) ?: null,
            Input::str($in, 'email', '', 160) ?: null,
            Input::str($in, 'address', '', 200) ?: null,
            Input::num($in, 'credit_limit'),
            Input::str($in, 'notes', '', 255) ?: null,
            Db::now(), $id, $shopId,
        ]);
        return Db::one('SELECT * FROM customers WHERE id = ? AND shop_id = ?', [$id, $shopId]) ?: [];
    }

    public static function customerLedger(int $shopId, int $customerId): array
    {
        $c = Db::one('SELECT * FROM customers WHERE id = ? AND shop_id = ?', [$customerId, $shopId]);
        if (!$c) {
            throw new AppException('Customer not found in this shop.', 404, 'NOT_FOUND');
        }
        $c['ledger'] = Db::all(
            'SELECT entry_type, amount, balance_after, note, occurred_at FROM customer_credit
              WHERE shop_id = ? AND customer_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 200',
            [$shopId, $customerId]
        );
        $c['sales'] = Db::all(
            'SELECT id, sale_no, total, amount_paid, payment_method, status, sold_at FROM sales
              WHERE shop_id = ? AND customer_id = ? ORDER BY sold_at DESC LIMIT 100',
            [$shopId, $customerId]
        );
        return $c;
    }

    /* ================================================================== */
    /* SUPPLIERS + PURCHASES                                              */
    /* ================================================================== */

    public static function listSuppliers(int $shopId): array
    {
        return Db::all(
            'SELECT s.*, (SELECT COUNT(*) FROM purchase_orders p WHERE p.supplier_id = s.id) AS orders
               FROM suppliers s WHERE s.shop_id = ? AND s.is_deleted = 0 ORDER BY s.name',
            [$shopId]
        );
    }

    public static function saveSupplier(int $shopId, array $in): array
    {
        $uuid = Input::uuid($in['client_uuid'] ?? null);
        $name = Input::need($in, 'name', 160);
        $dup = Db::one('SELECT id FROM suppliers WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($dup) {
            $id = (int) $dup['id'];
        } elseif (Input::int($in, 'id')) {
            $id = Input::int($in, 'id');
        } else {
            Db::exec('INSERT INTO suppliers (shop_id, client_uuid, name, contact_person, phone, email, address)
                      VALUES (?,?,?,?,?,?,?)', [
                $shopId, $uuid, $name,
                Input::str($in, 'contact_person', '', 120) ?: null,
                Input::str($in, 'phone', '', 30) ?: null,
                Input::str($in, 'email', '', 160) ?: null,
                Input::str($in, 'address', '', 200) ?: null,
            ]);
            $id = Db::id();
        }
        Db::exec('UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, updated_at=? WHERE id=? AND shop_id=?', [
            $name,
            Input::str($in, 'contact_person', '', 120) ?: null,
            Input::str($in, 'phone', '', 30) ?: null,
            Input::str($in, 'email', '', 160) ?: null,
            Input::str($in, 'address', '', 200) ?: null,
            Db::now(), $id, $shopId,
        ]);
        return Db::one('SELECT * FROM suppliers WHERE id = ? AND shop_id = ?', [$id, $shopId]) ?: [];
    }

    /** Receive a purchase order: adds stock through the ledger in one transaction. */
    public static function receivePurchase(int $shopId, array $in, int $userId): array
    {
        $uuid = Input::uuid($in['client_uuid'] ?? null);
        $existing = Db::one('SELECT * FROM purchase_orders WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($existing) {
            return $existing + ['duplicate' => true];
        }
        $items = $in['items'] ?? [];
        if (!is_array($items) || !$items) {
            throw new AppException('Add at least one product to the purchase.', 422, 'VALIDATION');
        }
        $supplierId = Input::int($in, 'supplier_id') ?: null;

        Db::begin();
        try {
            $total = 0.0;
            foreach ($items as $line) {
                $total += round((float) ($line['quantity'] ?? 0) * (float) ($line['unit_cost'] ?? 0), 2);
            }
            $poNo = Input::str($in, 'po_no', '', 40) ?: 'PO-' . date('ymd') . '-' . strtoupper(substr($uuid, 0, 4));
            Db::exec(
                'INSERT INTO purchase_orders (shop_id, client_uuid, po_no, supplier_id, status, total, amount_paid, received_at, note)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$shopId, $uuid, $poNo, $supplierId, 'received', $total, Input::num($in, 'amount_paid'), Db::now(), Input::str($in, 'note', '', 255) ?: null]
            );
            $poId = Db::id();

            foreach ($items as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $qty = (float) ($line['quantity'] ?? 0);
                $cost = (float) ($line['unit_cost'] ?? 0);
                if ($qty <= 0) {
                    throw new AppException('Purchase quantities must be greater than zero.', 422, 'VALIDATION');
                }
                $p = Db::one('SELECT id FROM products WHERE id = ? AND shop_id = ?', [$productId, $shopId]);
                if (!$p) {
                    throw new AppException('Product not found in this shop.', 422, 'VALIDATION');
                }
                Db::exec('INSERT INTO purchase_items (shop_id, po_id, product_id, quantity, unit_cost, line_total) VALUES (?,?,?,?,?,?)',
                    [$shopId, $poId, $productId, $qty, $cost, round($qty * $cost, 2)]);
                StockService::move($shopId, [
                    'client_uuid'   => $uuid . ':item:' . $productId,
                    'product_id'    => $productId,
                    'movement_type' => 'purchase',
                    'qty_change'    => $qty,
                    'unit_cost'     => $cost,
                    'reference'     => $poNo,
                    'employee_id'   => $userId,
                ]);
            }
            $owed = round($total - Input::num($in, 'amount_paid'), 2);
            if ($supplierId && $owed != 0.0) {
                Db::exec('UPDATE suppliers SET balance = balance + ?, updated_at = ? WHERE id = ? AND shop_id = ?',
                    [$owed, Db::now(), $supplierId, $shopId]);
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return Db::one('SELECT * FROM purchase_orders WHERE id = ?', [$poId]) ?: [];
    }

    public static function listPurchases(int $shopId): array
    {
        return Db::all(
            'SELECT p.*, s.name AS supplier_name, (SELECT COUNT(*) FROM purchase_items i WHERE i.po_id = p.id) AS lines
               FROM purchase_orders p LEFT JOIN suppliers s ON s.id = p.supplier_id
              WHERE p.shop_id = ? ORDER BY p.ordered_at DESC LIMIT 200',
            [$shopId]
        );
    }

    /* ================================================================== */
    /* EXPENSES                                                           */
    /* ================================================================== */

    public static function listExpenses(int $shopId, array $q): array
    {
        $where = ['shop_id = ?', 'is_deleted = 0'];
        $params = [$shopId];
        if (!empty($q['from'])) {
            $where[] = 'spent_at >= ?';
            $params[] = date('Y-m-d 00:00:00', strtotime((string) $q['from']));
        }
        if (!empty($q['to'])) {
            $where[] = 'spent_at <= ?';
            $params[] = date('Y-m-d 23:59:59', strtotime((string) $q['to']));
        }
        return Db::all('SELECT * FROM expenses WHERE ' . implode(' AND ', $where) . ' ORDER BY spent_at DESC LIMIT 400', $params);
    }

    public static function saveExpense(int $shopId, array $in, int $userId): array
    {
        $uuid = Input::uuid($in['client_uuid'] ?? null);
        $dup = Db::one('SELECT * FROM expenses WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($dup) {
            return $dup + ['duplicate' => true];
        }
        $amount = Input::num($in, 'amount');
        if ($amount <= 0) {
            throw new AppException('Expense amount must be greater than zero.', 422, 'VALIDATION');
        }
        Db::exec(
            'INSERT INTO expenses (shop_id, client_uuid, category, description, amount, spent_at, employee_id)
             VALUES (?,?,?,?,?,?,?)',
            [
                $shopId, $uuid,
                Input::str($in, 'category', 'other', 20),
                Input::need($in, 'description', 200),
                $amount,
                Input::date($in, 'spent_at', Db::now()),
                $userId,
            ]
        );
        return Db::one('SELECT * FROM expenses WHERE id = ?', [Db::id()]) ?: [];
    }

    /* ================================================================== */
    /* DASHBOARD + REPORTS                                                */
    /* ================================================================== */

    public static function dashboard(int $shopId): array
    {
        $today = date('Y-m-d');
        $sum = function (string $from, string $to) use ($shopId): array {
            return Db::one(
                'SELECT COALESCE(SUM(total),0) AS revenue, COALESCE(SUM(amount_paid),0) AS cash,
                        COUNT(*) AS transactions
                   FROM sales WHERE shop_id = ? AND status <> "cancelled" AND sold_at BETWEEN ? AND ?',
                [$shopId, $from . ' 00:00:00', $to . ' 23:59:59']
            ) ?: ['revenue' => 0, 'cash' => 0, 'transactions' => 0];
        };

        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $monthStart = date('Y-m-01');

        $cogs = function (string $from, string $to) use ($shopId): float {
            return (float) Db::val(
                'SELECT COALESCE(SUM(i.quantity * i.unit_cost),0)
                   FROM sale_items i JOIN sales s ON s.id = i.sale_id
                  WHERE s.shop_id = ? AND s.status <> "cancelled" AND s.sold_at BETWEEN ? AND ?',
                [$shopId, $from . ' 00:00:00', $to . ' 23:59:59'],
                0
            );
        };

        $expenses = function (string $from, string $to) use ($shopId): float {
            return (float) Db::val(
                'SELECT COALESCE(SUM(amount),0) FROM expenses WHERE shop_id = ? AND is_deleted = 0 AND spent_at BETWEEN ? AND ?',
                [$shopId, $from . ' 00:00:00', $to . ' 23:59:59'],
                0
            );
        };

        $revMonth = (float) ($sum($monthStart, $today)['revenue'] ?? 0);
        $cogsMonth = $cogs($monthStart, $today);
        $expMonth = $expenses($monthStart, $today);

        $bestSellers = Db::all(
            'SELECT i.product_name, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
               FROM sale_items i JOIN sales s ON s.id = i.sale_id
              WHERE s.shop_id = ? AND s.status <> "cancelled" AND s.sold_at >= ?
              GROUP BY i.product_name ORDER BY qty DESC LIMIT 5',
            [$shopId, $monthStart . ' 00:00:00']
        );

        $slowMovers = Db::all(
            'SELECT p.name, p.stock_qty, p.selling_price,
                    COALESCE((SELECT SUM(i.quantity) FROM sale_items i JOIN sales s ON s.id = i.sale_id
                               WHERE i.product_id = p.id AND s.status <> "cancelled" AND s.sold_at >= ?),0) AS sold
               FROM products p WHERE p.shop_id = ? AND p.is_deleted = 0 AND p.is_active = 1
              ORDER BY sold ASC, p.stock_qty DESC LIMIT 5',
            [$shopId, date('Y-m-d 00:00:00', strtotime('-30 days'))]
        );

        return [
            'sales_today'   => $sum($today, $today),
            'sales_week'    => $sum($weekStart, $today),
            'sales_month'   => $sum($monthStart, $today),
            'profit'        => [
                'revenue'       => round($revMonth, 2),
                'cogs'          => round($cogsMonth, 2),
                'gross_profit'  => round($revMonth - $cogsMonth, 2),
                'expenses'      => round($expMonth, 2),
                'net_profit'    => round($revMonth - $cogsMonth - $expMonth, 2),
            ],
            'counts' => [
                'products'      => (int) Db::val('SELECT COUNT(*) FROM products WHERE shop_id = ? AND is_deleted = 0', [$shopId], 0),
                'low_stock'     => (int) Db::val('SELECT COUNT(*) FROM products WHERE shop_id = ? AND is_deleted = 0 AND stock_qty > 0 AND stock_qty <= min_stock', [$shopId], 0),
                'out_of_stock'  => (int) Db::val('SELECT COUNT(*) FROM products WHERE shop_id = ? AND is_deleted = 0 AND stock_qty <= 0', [$shopId], 0),
                'customers'     => (int) Db::val('SELECT COUNT(*) FROM customers WHERE shop_id = ? AND is_deleted = 0', [$shopId], 0),
                'employees'     => (int) Db::val('SELECT COUNT(*) FROM shop_users WHERE shop_id = ? AND is_active = 1', [$shopId], 0),
            ],
            'outstanding_credit' => (float) Db::val('SELECT COALESCE(SUM(balance),0) FROM customers WHERE shop_id = ? AND is_deleted = 0', [$shopId], 0),
            'best_sellers'  => $bestSellers,
            'slow_movers'   => $slowMovers,
            'low_stock_list'=> Db::all('SELECT id, name, stock_qty, min_stock, unit FROM products WHERE shop_id = ? AND is_deleted = 0 AND stock_qty <= min_stock ORDER BY stock_qty ASC LIMIT 12', [$shopId]),
            'recent_sales'  => Db::all(
                'SELECT s.id, s.sale_no, s.total, s.payment_method, s.status, s.sold_at, u.full_name AS cashier
                   FROM sales s LEFT JOIN shop_users u ON u.id = s.employee_id
                  WHERE s.shop_id = ? ORDER BY s.sold_at DESC, s.id DESC LIMIT 10',
                [$shopId]
            ),
            'trend_14d'     => Db::all(
                'SELECT DATE(sold_at) AS day, SUM(total) AS revenue, COUNT(*) AS transactions
                   FROM sales WHERE shop_id = ? AND status <> "cancelled" AND sold_at >= ?
                  GROUP BY DATE(sold_at) ORDER BY day',
                [$shopId, date('Y-m-d 00:00:00', strtotime('-13 days'))]
            ),
            'notifications' => Db::all('SELECT * FROM notifications WHERE shop_id = ? ORDER BY created_at DESC LIMIT 8', [$shopId]),
        ];
    }

    public static function report(int $shopId, string $period, ?string $from = null, ?string $to = null): array
    {
        switch ($period) {
            case 'today': $from = $to = date('Y-m-d'); break;
            case 'week':  $from = date('Y-m-d', strtotime('monday this week')); $to = date('Y-m-d'); break;
            case 'month': $from = date('Y-m-01'); $to = date('Y-m-d'); break;
            case 'year':  $from = date('Y-01-01'); $to = date('Y-m-d'); break;
            default:      $from = $from ?: date('Y-m-01'); $to = $to ?: date('Y-m-d');
        }
        $p = [$shopId, $from . ' 00:00:00', $to . ' 23:59:59'];

        $revenue = (float) Db::val('SELECT COALESCE(SUM(total),0) FROM sales WHERE shop_id=? AND status<>"cancelled" AND sold_at BETWEEN ? AND ?', $p, 0);
        $cash    = (float) Db::val('SELECT COALESCE(SUM(amount_paid),0) FROM sales WHERE shop_id=? AND status<>"cancelled" AND sold_at BETWEEN ? AND ?', $p, 0);
        $cogs    = (float) Db::val('SELECT COALESCE(SUM(i.quantity*i.unit_cost),0) FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.shop_id=? AND s.status<>"cancelled" AND s.sold_at BETWEEN ? AND ?', $p, 0);
        $credit  = (float) Db::val('SELECT COALESCE(SUM(total-amount_paid),0) FROM sales WHERE shop_id=? AND status<>"cancelled" AND sold_at BETWEEN ? AND ?', $p, 0);
        $purchases = (float) Db::val('SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE shop_id=? AND status="received" AND ordered_at BETWEEN ? AND ?', $p, 0);
        $expenses  = (float) Db::val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE shop_id=? AND is_deleted=0 AND spent_at BETWEEN ? AND ?', $p, 0);

        return [
            'period'     => ['type' => $period, 'from' => $from, 'to' => $to],
            'totals'     => [
                'revenue'       => round($revenue, 2),
                'cash_received' => round($cash, 2),
                'credit_given'  => round($credit, 2),
                'purchases'     => round($purchases, 2),
                'cogs'          => round($cogs, 2),
                'gross_profit'  => round($revenue - $cogs, 2),
                'expenses'      => round($expenses, 2),
                'net_profit'    => round($revenue - $cogs - $expenses, 2),
                'transactions'  => (int) Db::val('SELECT COUNT(*) FROM sales WHERE shop_id=? AND status<>"cancelled" AND sold_at BETWEEN ? AND ?', $p, 0),
            ],
            'by_day'     => Db::all('SELECT DATE(sold_at) AS day, SUM(total) AS revenue, COUNT(*) AS transactions FROM sales WHERE shop_id=? AND status<>"cancelled" AND sold_at BETWEEN ? AND ? GROUP BY DATE(sold_at) ORDER BY day', $p),
            'by_payment' => Db::all('SELECT payment_method, SUM(total) AS total, COUNT(*) AS transactions FROM sales WHERE shop_id=? AND status<>"cancelled" AND sold_at BETWEEN ? AND ? GROUP BY payment_method', $p),
            'by_category'=> Db::all('SELECT COALESCE(c.name,"Uncategorised") AS category, SUM(i.line_total) AS revenue, SUM(i.quantity) AS qty FROM sale_items i JOIN sales s ON s.id=i.sale_id JOIN products p ON p.id=i.product_id LEFT JOIN product_categories c ON c.id=p.category_id WHERE s.shop_id=? AND s.status<>"cancelled" AND s.sold_at BETWEEN ? AND ? GROUP BY category ORDER BY revenue DESC', $p),
            'top_products' => Db::all('SELECT i.product_name, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.shop_id=? AND s.status<>"cancelled" AND s.sold_at BETWEEN ? AND ? GROUP BY i.product_name ORDER BY revenue DESC LIMIT 10', $p),
            'expenses_by_category' => Db::all('SELECT category, SUM(amount) AS total FROM expenses WHERE shop_id=? AND is_deleted=0 AND spent_at BETWEEN ? AND ? GROUP BY category ORDER BY total DESC', $p),
            'debts'      => Db::all('SELECT name, phone, balance FROM customers WHERE shop_id=? AND balance > 0 ORDER BY balance DESC LIMIT 50', [$shopId]),
        ];
    }

    /* ================================================================== */
    /* EMPLOYEES                                                          */
    /* ================================================================== */

    public static function listEmployees(int $shopId): array
    {
        return Db::all(
            'SELECT u.id, u.full_name, u.username, u.phone, u.is_active, u.last_login_at, u.created_at,
                    r.code AS role, r.name AS role_name
               FROM shop_users u JOIN roles r ON r.id = u.role_id
              WHERE u.shop_id = ? ORDER BY u.is_active DESC, u.full_name',
            [$shopId]
        );
    }

    public static function saveEmployee(int $shopId, array $in, array $ctx): array
    {
        $roleCode = Input::str($in, 'role', 'cashier', 30);
        $role = Db::one('SELECT id, code FROM roles WHERE code = ?', [$roleCode]);
        if (!$role) {
            throw new AppException('Unknown role.', 422, 'VALIDATION');
        }
        // Escalation guard: only the owner may create another owner, and the
        // owner account itself cannot be edited through this endpoint.
        if ($roleCode === 'owner' && ($ctx['role'] ?? '') !== 'owner') {
            throw new AppException('Only the shop owner can assign the owner role.', 403, 'FORBIDDEN');
        }

        $id = Input::int($in, 'id');
        if ($id) {
            $target = Db::one('SELECT id, role_id FROM shop_users WHERE id = ? AND shop_id = ?', [$id, $shopId]);
            if (!$target) {
                throw new AppException('Employee not found in this shop.', 404, 'NOT_FOUND');
            }
            $targetRole = Db::val('SELECT code FROM roles WHERE id = ?', [$target['role_id']]);
            if ($targetRole === 'owner' && $id !== ($ctx['user_id'] ?? 0)) {
                throw new AppException('The shop owner account cannot be edited here.', 403, 'FORBIDDEN');
            }
            $sql = 'UPDATE shop_users SET full_name=?, phone=?, role_id=?, is_active=?, updated_at=?';
            $args = [Input::need($in, 'full_name', 160), Input::str($in, 'phone', '', 30) ?: null, (int) $role['id'], Input::bool($in, 'is_active', true) ? 1 : 0, Db::now()];
            if (!empty($in['password'])) {
                $sql .= ', password_hash=?';
                $args[] = password_hash(self::checkPassword($in['password']), PASSWORD_BCRYPT, ['cost' => (int) Config::get('security.bcrypt_cost', 11)]);
            }
            $sql .= ' WHERE id=? AND shop_id=?';
            $args[] = $id;
            $args[] = $shopId;
            Db::exec($sql, $args);
            return ['id' => $id];
        }

        // Seat limit from the subscription plan
        $planMax = (int) Db::val(
            'SELECT p.max_users FROM shops s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.id = ?',
            [$shopId],
            3
        );
        $active = (int) Db::val('SELECT COUNT(*) FROM shop_users WHERE shop_id = ? AND is_active = 1', [$shopId], 0);
        if ($active >= $planMax) {
            throw new AppException("Your plan allows {$planMax} active users. Deactivate an employee or upgrade your plan.", 402, 'SEAT_LIMIT');
        }

        $username = strtolower(Input::need($in, 'username', 60));
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            throw new AppException('Username may contain letters, numbers, dot, dash and underscore only.', 422, 'VALIDATION');
        }
        if (Db::val('SELECT 1 FROM shop_users WHERE shop_id = ? AND username = ?', [$shopId, $username])) {
            throw new AppException('That username is already used in this shop.', 409, 'DUPLICATE');
        }
        Db::exec(
            'INSERT INTO shop_users (shop_id, full_name, username, phone, password_hash, role_id, is_active)
             VALUES (?,?,?,?,?,?,1)',
            [
                $shopId, Input::need($in, 'full_name', 160), $username,
                Input::str($in, 'phone', '', 30) ?: null,
                password_hash(self::checkPassword($in['password'] ?? $in['pin'] ?? ''), PASSWORD_BCRYPT, ['cost' => (int) Config::get('security.bcrypt_cost', 11)]),
                (int) $role['id'],
            ]
        );
        return ['id' => Db::id(), 'username' => $username];
    }

    private static function checkPassword($pw): string
    {
        $pw = (string) $pw;
        if (strlen($pw) < 4) {
            throw new AppException('Use a password or PIN of at least 4 characters.', 422, 'VALIDATION');
        }
        return $pw;
    }

    /* ================================================================== */
    /* ACTIVITY LOG                                                       */
    /* ================================================================== */

    public static function auditLogs(int $shopId, int $limit = 200): array
    {
        return Db::all(
            'SELECT a.id, a.action, a.entity, a.entity_id, a.ip_address, a.created_at, a.actor_type, a.actor_id,
                    COALESCE(u.full_name, "System") AS actor
               FROM audit_logs a LEFT JOIN shop_users u ON u.id = a.actor_id AND a.actor_type = "shop_user"
              WHERE a.shop_id = ? ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit)),
            [$shopId]
        );
    }
}

/** Credit ledger writes go through here so the balance cache stays correct. */
final class CreditService
{
    public static function charge(int $shopId, int $customerId, float $amount, ?int $saleId, int $userId, string $note, string $at, ?string $uuid): array
    {
        return self::post($shopId, $customerId, 'charge', $amount, $saleId, $userId, $note, $at, $uuid);
    }

    public static function pay(int $shopId, int $customerId, float $amount, int $userId, string $note, ?string $at = null, ?string $uuid = null): array
    {
        return self::post($shopId, $customerId, 'payment', $amount, null, $userId, $note, $at ?? Db::now(), $uuid);
    }

    private static function post(int $shopId, int $customerId, string $type, float $amount, ?int $saleId, int $userId, string $note, string $at, ?string $uuid): array
    {
        $uuid = $uuid ? Input::uuid($uuid) : Db::uuid();
        $dup = Db::one('SELECT * FROM customer_credit WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($dup) {
            return $dup + ['duplicate' => true];
        }
        $cust = Db::one('SELECT id, balance FROM customers WHERE id = ? AND shop_id = ?', [$customerId, $shopId]);
        if (!$cust) {
            throw new AppException('Customer not found in this shop.', 404, 'NOT_FOUND');
        }
        if ($amount <= 0) {
            throw new AppException('Amount must be greater than zero.', 422, 'VALIDATION');
        }
        $signed = $type === 'payment' ? -$amount : $amount;
        $newBalance = round((float) $cust['balance'] + $signed, 2);

        Db::exec(
            'INSERT INTO customer_credit (shop_id, client_uuid, customer_id, sale_id, entry_type, amount, balance_after, note, employee_id, occurred_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$shopId, $uuid, $customerId, $saleId, $type, $amount, $newBalance, mb_substr($note, 0, 200), $userId, $at]
        );
        Db::exec('UPDATE customers SET balance = ?, updated_at = ? WHERE id = ? AND shop_id = ?', [$newBalance, Db::now(), $customerId, $shopId]);

        if ($type === 'charge' && $newBalance > 0) {
            Notify::push($shopId, 'credit', 'Credit balance updated', sprintf('Customer balance is now %s UGX.', number_format($newBalance)));
        }
        return Db::one('SELECT * FROM customer_credit WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]) ?: [];
    }
}
