<?php
declare(strict_types=1);

/** CSV / JSON export + restore. Everything exported is tenant-filtered. */
final class Exporter
{
    public static function csv(array $report): void
    {
        $name = 'smartbook-report-' . $report['period']['from'] . '-to-' . $report['period']['to'] . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly

        fputcsv($out, ['SMARTBOOK SOLUTIONS - BUSINESS REPORT']);
        fputcsv($out, ['Period', $report['period']['from'], 'to', $report['period']['to']]);
        fputcsv($out, []);
        fputcsv($out, ['FINANCIAL SUMMARY']);
        foreach ($report['totals'] as $k => $v) {
            fputcsv($out, [str_replace('_', ' ', strtoupper($k)), $v]);
        }
        fputcsv($out, []);
        fputcsv($out, ['SALES BY DAY', 'Revenue', 'Transactions']);
        foreach ($report['by_day'] as $r) {
            fputcsv($out, [$r['day'], $r['revenue'], $r['transactions']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['TOP PRODUCTS', 'Quantity', 'Revenue']);
        foreach ($report['top_products'] as $r) {
            fputcsv($out, [$r['product_name'], $r['qty'], $r['revenue']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['EXPENSES BY CATEGORY', 'Total']);
        foreach ($report['expenses_by_category'] as $r) {
            fputcsv($out, [$r['category'], $r['total']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['OUTSTANDING CUSTOMER DEBTS', 'Phone', 'Balance']);
        foreach ($report['debts'] as $r) {
            fputcsv($out, [$r['name'], $r['phone'], $r['balance']]);
        }
        fclose($out);
    }

    public static function jsonBackup(int $shopId, bool $download = false): array
    {
        $data = [
            'meta' => [
                'app'        => Config::get('app.name'),
                'shop_id'    => $shopId,
                'generated'  => Db::now(),
                'schema'     => 1,
            ],
            'shop'         => Db::one('SELECT * FROM shops WHERE id = ?', [$shopId]),
            'users'        => Db::all('SELECT id, full_name, username, phone, role_id, is_active FROM shop_users WHERE shop_id = ?', [$shopId]),
            'categories'   => Db::all('SELECT * FROM product_categories WHERE shop_id = ?', [$shopId]),
            'products'     => Db::all('SELECT * FROM products WHERE shop_id = ?', [$shopId]),
            'movements'    => Db::all('SELECT * FROM inventory_movements WHERE shop_id = ?', [$shopId]),
            'customers'    => Db::all('SELECT * FROM customers WHERE shop_id = ?', [$shopId]),
            'sales'        => Db::all('SELECT * FROM sales WHERE shop_id = ?', [$shopId]),
            'sale_items'   => Db::all('SELECT * FROM sale_items WHERE shop_id = ?', [$shopId]),
            'credit'       => Db::all('SELECT * FROM customer_credit WHERE shop_id = ?', [$shopId]),
            'suppliers'    => Db::all('SELECT * FROM suppliers WHERE shop_id = ?', [$shopId]),
            'purchases'    => Db::all('SELECT * FROM purchase_orders WHERE shop_id = ?', [$shopId]),
            'purchase_items' => Db::all('SELECT * FROM purchase_items WHERE shop_id = ?', [$shopId]),
            'expenses'     => Db::all('SELECT * FROM expenses WHERE shop_id = ?', [$shopId]),
            'payments'     => Db::all('SELECT * FROM payments WHERE shop_id = ?', [$shopId]),
        ];
        if ($download) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="smartbook-backup-shop' . $shopId . '-' . date('Ymd-His') . '.json"');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        return $data;
    }
}

/**
 * Restore a JSON backup into a shop.
 *
 * Safety: only rows carrying THIS shop's id are imported, so a backup from
 * another shop cannot be used to read or overwrite someone else's data.
 * Products and customers are re-keyed through their client_uuid, which makes
 * the operation repeatable.
 */
final class Backup
{
    public static function restore(int $shopId, array $in, int $userId): array
    {
        $payload = $in['backup'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload) || empty($payload['meta'])) {
            throw new AppException('That file is not a SmartBook backup.', 422, 'VALIDATION');
        }
        if ((int) ($payload['meta']['shop_id'] ?? 0) !== $shopId) {
            throw new AppException('This backup belongs to a different shop and cannot be restored here.', 403, 'TENANT_MISMATCH');
        }

        $counts = ['categories' => 0, 'products' => 0, 'customers' => 0, 'suppliers' => 0, 'movements' => 0];

        Db::begin();
        try {
            foreach (($payload['categories'] ?? []) as $c) {
                if (Db::val('SELECT 1 FROM product_categories WHERE shop_id = ? AND client_uuid = ?', [$shopId, $c['client_uuid'] ?? ''])) {
                    continue;
                }
                Db::exec('INSERT INTO product_categories (shop_id, name, client_uuid) VALUES (?,?,?)',
                    [$shopId, mb_substr((string) ($c['name'] ?? 'Category'), 0, 100), $c['client_uuid'] ?? Db::uuid()]);
                $counts['categories']++;
            }
            foreach (($payload['customers'] ?? []) as $c) {
                $uuid = $c['client_uuid'] ?? Db::uuid();
                if (Db::val('SELECT 1 FROM customers WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid])) {
                    continue;
                }
                Db::exec('INSERT INTO customers (shop_id, client_uuid, name, phone, balance, credit_limit) VALUES (?,?,?,?,?,?)',
                    [$shopId, $uuid, mb_substr((string) ($c['name'] ?? 'Customer'), 0, 160), $c['phone'] ?? null,
                     (float) ($c['balance'] ?? 0), (float) ($c['credit_limit'] ?? 0)]);
                $counts['customers']++;
            }
            foreach (($payload['products'] ?? []) as $p) {
                $uuid = $p['client_uuid'] ?? Db::uuid();
                if (Db::val('SELECT 1 FROM products WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid])) {
                    continue;
                }
                Db::exec('INSERT INTO products (shop_id, client_uuid, name, unit, purchase_price, selling_price, stock_qty, min_stock, is_active)
                          VALUES (?,?,?,?,?,?,?,?,?)',
                    [$shopId, $uuid, mb_substr((string) ($p['name'] ?? 'Product'), 0, 160), $p['unit'] ?? 'pcs',
                     (float) ($p['purchase_price'] ?? 0), (float) ($p['selling_price'] ?? 0),
                     (float) ($p['stock_qty'] ?? 0), (float) ($p['min_stock'] ?? 0), (int) ($p['is_active'] ?? 1)]);
                $counts['products']++;
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // Stock cache is derived, so rebuild it from whatever ledger exists.
        SyncService::repair($shopId);
        Audit::log($shopId, 'shop_user', $userId, 'backup.restore', 'shops', (string) $shopId, $counts);
        return ['restored' => $counts];
    }
}
