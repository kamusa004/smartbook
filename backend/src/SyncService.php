<?php
declare(strict_types=1);

/**
 * SyncService - the offline-first heart of SmartBook.
 *
 * PUSH  : client sends its queued records (UUID + signed stock deltas).
 *         Each is applied idempotently; duplicates are reported back so the
 *         device can safely clear its queue without risk of double posting.
 * PULL  : client sends the cursor it last saw; server returns everything
 *         changed since, plus a new cursor.
 *
 * Order matters: customers -> categories -> products -> sales -> credit ->
 * expenses -> purchases, so that a sale never references a product the
 * server has not seen yet.
 */
final class SyncService
{
    private const PULL_ENTITIES = [
        'categories' => ['product_categories', ['id', 'name', 'client_uuid', 'updated_at']],
        'products'   => ['products', ['id', 'client_uuid', 'sku', 'name', 'category_id', 'barcode', 'unit',
                                      'purchase_price', 'selling_price', 'stock_qty', 'min_stock', 'expiry_date',
                                      'is_active', 'image_path', 'updated_at']],
        'customers'  => ['customers', ['id', 'client_uuid', 'name', 'phone', 'email', 'address', 'credit_limit', 'balance', 'updated_at']],
        'suppliers'  => ['suppliers', ['id', 'name', 'contact_person', 'phone', 'email', 'address', 'balance', 'updated_at']],
        'sales'      => ['sales', ['id', 'client_uuid', 'sale_no', 'customer_id', 'employee_id', 'subtotal', 'discount',
                                   'total', 'amount_paid', 'payment_method', 'status', 'sold_at', 'updated_at']],
        'expenses'   => ['expenses', ['id', 'client_uuid', 'category', 'description', 'amount', 'spent_at', 'updated_at']],
        'credit'     => ['customer_credit', ['id', 'customer_id', 'entry_type', 'amount', 'balance_after', 'note', 'occurred_at', 'updated_at']],
    ];

    public static function push(int $shopId, array $in, ?string $deviceId): array
    {
        $results = [];
        // Strict order: dependencies first.
        $order = ['customers', 'categories', 'products', 'stock_movements', 'sales', 'credit_payments', 'expenses'];

        foreach ($order as $entity) {
            $rows = $in[$entity] ?? [];
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                try {
                    $r = self::applyOne($shopId, $entity, $row, $deviceId);
                    $results[] = $r;
                } catch (AppException $e) {
                    $results[] = ['entity' => $entity, 'client_uuid' => $row['client_uuid'] ?? null,
                                  'result' => 'rejected', 'message' => $e->getMessage()];
                    Db::exec('INSERT INTO sync_logs (shop_id, device_id, direction, entity, record_uuid, result, message) VALUES (?,?,?,?,?,?,?)',
                        [$shopId, $deviceId, 'push', $entity, substr((string) ($row['client_uuid'] ?? ''), 0, 36), 'rejected', mb_substr($e->getMessage(), 0, 255)]);
                } catch (Throwable $e) {
                    $results[] = ['entity' => $entity, 'client_uuid' => $row['client_uuid'] ?? null,
                                  'result' => 'error', 'message' => 'Could not apply this record.'];
                }
            }
        }
        return [
            'results' => $results,
            'summary' => [
                'applied'   => count(array_filter($results, fn($r) => $r['result'] === 'applied')),
                'duplicate' => count(array_filter($results, fn($r) => $r['result'] === 'duplicate')),
                'rejected'  => count(array_filter($results, fn($r) => $r['result'] === 'rejected')),
                'error'     => count(array_filter($results, fn($r) => $r['result'] === 'error')),
            ],
        ];
    }

    private static function applyOne(int $shopId, string $entity, array $row, ?string $deviceId): array
    {
        $uuid = Input::uuid($row['client_uuid'] ?? null);
        $userId = (int) (Auth::context()['user_id'] ?? 0);

        switch ($entity) {
            case 'customers':
                $c = ShopService::saveCustomer($shopId, $row + ['client_uuid' => $uuid]);
                $res = 'applied';
                break;

            case 'categories':
                $c = ShopService::saveCategory($shopId, $row + ['client_uuid' => $uuid]);
                $res = 'applied';
                break;

            case 'products':
                $c = ShopService::saveProduct($shopId, $row + ['client_uuid' => $uuid], empty($row['id']));
                $res = 'applied';
                break;

            case 'stock_movements':
                $m = StockService::move($shopId, $row + ['client_uuid' => $uuid, 'employee_id' => $userId, 'device_id' => $deviceId]);
                $c = $m;
                $res = !empty($m['_duplicate']) ? 'duplicate' : 'applied';
                break;

            case 'sales':
                $s = ShopService::createSale($shopId, $row + ['client_uuid' => $uuid], $userId, $deviceId);
                $c = $s['sale'];
                $res = $s['duplicate'] ? 'duplicate' : 'applied';
                break;

            case 'credit_payments':
                $cust = (int) ($row['customer_id'] ?? 0);
                $amount = (float) ($row['amount'] ?? 0);
                if (!$cust || $amount <= 0) {
                    throw new AppException('Credit payment needs a customer and an amount.', 422, 'VALIDATION');
                }
                $p = CreditService::pay($shopId, $cust, $amount, $userId, (string) ($row['note'] ?? 'Offline credit payment'),
                    isset($row['paid_at']) ? date('Y-m-d H:i:s', strtotime((string) $row['paid_at'])) : null, $uuid);
                $c = $p;
                $res = !empty($p['duplicate']) ? 'duplicate' : 'applied';
                break;

            case 'expenses':
                $e = ShopService::saveExpense($shopId, $row + ['client_uuid' => $uuid], $userId);
                $c = $e;
                $res = !empty($e['duplicate']) ? 'duplicate' : 'applied';
                break;

            default:
                throw new AppException('Unsupported sync entity.', 422, 'VALIDATION');
        }

        Db::exec('INSERT INTO sync_logs (shop_id, device_id, direction, entity, record_uuid, result) VALUES (?,?,?,?,?,?)',
            [$shopId, $deviceId, 'push', $entity, $uuid, $res]);

        return [
            'entity'      => $entity,
            'client_uuid' => $uuid,
            'result'      => $res,
            'server_id'   => is_array($c) && isset($c['id']) ? (int) $c['id'] : null,
        ];
    }

    /** Everything changed since $since, in dependency order. */
    public static function pull(int $shopId, ?string $since, int $limit = 500): array
    {
        $since = $since ? date('Y-m-d H:i:s', strtotime($since) ?: 0) : '1970-01-01 00:00:00';
        $data = [];
        $cursor = $since;

        foreach (self::PULL_ENTITIES as $key => [$table, $cols]) {
            $rows = Db::all(
                'SELECT ' . implode(',', $cols) . ' FROM ' . $table .
                ' WHERE shop_id = ? AND updated_at > ? ORDER BY updated_at ASC LIMIT ' . max(1, min(2000, $limit)),
                [$shopId, $since]
            );
            $data[$key] = $rows;
            foreach ($rows as $r) {
                if (($r['updated_at'] ?? '') > $cursor) {
                    $cursor = $r['updated_at'];
                }
            }
        }

        // Sale items are pulled by sale id set (they have no updated_at of their own).
        $saleIds = array_column($data['sales'], 'id');
        $data['sale_items'] = $saleIds
            ? Db::all('SELECT id, sale_id, product_id, product_name, quantity, unit_price, line_total FROM sale_items
                        WHERE shop_id = ? AND sale_id IN (' . implode(',', array_map('intval', $saleIds)) . ')', [$shopId])
            : [];

        Db::exec('INSERT INTO sync_logs (shop_id, device_id, direction, entity, result, message) VALUES (?,?,?,?,?,?)',
            [$shopId, null, 'pull', null, 'applied', 'since=' . $since]);

        return [
            'cursor'    => $cursor === '1970-01-01 00:00:00' ? date('Y-m-d H:i:s') : $cursor,
            'server_time' => Db::now(),
            'data'      => $data,
        ];
    }

    /**
     * Conflict report: products whose cached stock disagrees with the movement
     * ledger (e.g. a device pushed adjustments out of order). The server wins
     * because the ledger is authoritative; the device is told the truth.
     */
    public static function conflicts(int $shopId): array
    {
        return Db::all(
            'SELECT p.id, p.name, p.stock_qty AS cached, COALESCE(SUM(m.qty_change),0) AS ledger
               FROM products p LEFT JOIN inventory_movements m ON m.product_id = p.id
              WHERE p.shop_id = ? AND p.is_deleted = 0
              GROUP BY p.id, p.name, p.stock_qty
             HAVING ABS(p.stock_qty - COALESCE(SUM(m.qty_change),0)) > 0.0001
              LIMIT 100',
            [$shopId]
        );
    }

    /** Rebuild every cached quantity from the ledger. Idempotent repair. */
    public static function repair(int $shopId): array
    {
        Db::exec(
            'UPDATE products p SET stock_qty = (
                SELECT COALESCE(SUM(m.qty_change),0) FROM inventory_movements m WHERE m.product_id = p.id
             ) WHERE p.shop_id = ?',
            [$shopId]
        );
        return ['repaired' => true, 'conflicts_remaining' => count(self::conflicts($shopId))];
    }
}
