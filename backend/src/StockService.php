<?php
declare(strict_types=1);

/**
 * StockService - the single place that changes stock.
 *
 * Why deltas: a device that has been offline for two days must not push an
 * absolute stock figure that overwrites sales rung up on another device. It
 * pushes the signed movement (+/-) it actually observed. SUM(qty_change)
 * therefore always reconciles, whatever order the movements arrive in.
 */
final class StockService
{
    /**
     * Record one signed stock movement and refresh the product cache.
     * Idempotent on (shop_id, client_uuid): a replayed offline record returns
     * the existing movement instead of applying the delta twice.
     */
    public static function move(int $shopId, array $m): array
    {
        $uuid = Input::uuid($m['client_uuid'] ?? null);

        $existing = Db::one('SELECT * FROM inventory_movements WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($existing) {
            return $existing + ['_duplicate' => true];
        }

        $product = Db::one('SELECT id, name, stock_qty, min_stock FROM products WHERE id = ? AND shop_id = ?', [(int) $m['product_id'], $shopId]);
        if (!$product) {
            throw new AppException('Product not found in this shop.', 404, 'PRODUCT_NOT_FOUND');
        }

        Db::q(
            'INSERT INTO inventory_movements
                (shop_id, client_uuid, product_id, movement_type, qty_change, unit_cost, reference, reason, employee_id, device_id, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $shopId, $uuid, (int) $product['id'],
                $m['movement_type'] ?? 'adjustment',
                (float) $m['qty_change'],
                (float) ($m['unit_cost'] ?? 0),
                isset($m['reference']) ? mb_substr((string) $m['reference'], 0, 60) : null,
                isset($m['reason']) ? mb_substr((string) $m['reason'], 0, 200) : null,
                isset($m['employee_id']) ? (int) $m['employee_id'] : null,
                isset($m['device_id']) ? mb_substr((string) $m['device_id'], 0, 64) : null,
                $m['created_at'] ?? Db::now(),
            ]
        );

        $newQty = self::recalc($shopId, (int) $product['id']);
        self::checkLowStock($shopId, (int) $product['id'], $newQty, (float) $product['min_stock'], (string) $product['name']);

        return Db::one('SELECT * FROM inventory_movements WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]) ?: [];
    }

    /** Recompute the cached quantity from the movement ledger. */
    public static function recalc(int $shopId, int $productId): float
    {
        $sum = (float) Db::val(
            'SELECT COALESCE(SUM(qty_change),0) FROM inventory_movements WHERE shop_id = ? AND product_id = ?',
            [$shopId, $productId],
            0
        );
        Db::exec('UPDATE products SET stock_qty = ? WHERE id = ? AND shop_id = ?', [$sum, $productId, $shopId]);
        return $sum;
    }

    private static function checkLowStock(int $shopId, int $productId, float $qty, float $min, string $name): void
    {
        if ($qty <= 0) {
            Notify::push($shopId, 'out_of_stock', "{$name} is out of stock", 'Record a purchase or adjust stock to continue selling this item.');
        } elseif ($qty <= $min) {
            Notify::push($shopId, 'low_stock', "{$name} is low on stock", sprintf('Only %s left (minimum %s). Consider restocking.', self::fmt($qty), self::fmt($min)));
        }
    }

    public static function adjust(int $shopId, array $in, int $userId): array
    {
        $productId = Input::int($in, 'product_id');
        $qtyChange = Input::num($in, 'qty_change');
        if ($qtyChange == 0.0) {
            throw new AppException('Enter a quantity that is not zero.', 422, 'VALIDATION');
        }
        return self::move($shopId, [
            'client_uuid'   => $in['client_uuid'] ?? null,
            'product_id'    => $productId,
            'movement_type' => Input::str($in, 'movement_type', 'adjustment', 20),
            'qty_change'    => $qtyChange,
            'unit_cost'     => Input::num($in, 'unit_cost'),
            'reason'        => Input::str($in, 'reason', '', 200),
            'employee_id'   => $userId,
        ]);
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.');
    }
}
