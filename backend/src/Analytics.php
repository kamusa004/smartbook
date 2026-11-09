<?php
declare(strict_types=1);

/**
 * Bridge to the Python analytics service (analytics/app.py).
 *
 * The Python service does the forecasting (moving average + linear trend on
 * daily sales, days-of-cover on stock). If it is not running, this class falls
 * back to equivalent SQL so the feature degrades gracefully instead of
 * breaking - important on low-end cPanel hosting where a Python daemon may not
 * be available.
 */
final class Analytics
{
    public static function insights(int $shopId): array
    {
        $base = rtrim((string) Config::get('analytics.base_url', ''), '/');
        if (Config::get('analytics.enabled') && $base !== '') {
            $payload = json_encode(['shop_id' => $shopId, 'days' => 30]);
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => (float) Config::get('analytics.timeout_s', 3),
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($base . '/analyze', false, $ctx);
            if ($raw !== false) {
                $json = json_decode($raw, true);
                if (is_array($json) && ($json['ok'] ?? false) && isset($json['data'])) {
                    $json['data']['engine'] = 'python';
                    return $json['data'];
                }
            }
        }
        $fallback = self::sqlInsights($shopId);
        $fallback['engine'] = 'sql-fallback';
        return $fallback;
    }

    /** Same metrics, computed in SQL - used when Python is unreachable. */
    private static function sqlInsights(int $shopId): array
    {
        $since = date('Y-m-d 00:00:00', strtotime('-30 days'));

        $daily = Db::all(
            'SELECT DATE(sold_at) AS day, SUM(total) AS revenue, COUNT(*) AS transactions
               FROM sales WHERE shop_id = ? AND status <> "cancelled" AND sold_at >= ?
              GROUP BY DATE(sold_at) ORDER BY day',
            [$shopId, $since]
        );

        $best = Db::all(
            'SELECT i.product_name, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
               FROM sale_items i JOIN sales s ON s.id = i.sale_id
              WHERE s.shop_id = ? AND s.status <> "cancelled" AND s.sold_at >= ?
              GROUP BY i.product_name ORDER BY qty DESC LIMIT 5',
            [$shopId, $since]
        );

        $slow = Db::all(
            'SELECT p.id, p.name, p.stock_qty, COALESCE(SUM(i.quantity),0) AS sold_30d
               FROM products p
               LEFT JOIN sale_items i ON i.product_id = p.id
               LEFT JOIN sales s ON s.id = i.sale_id AND s.status <> "cancelled" AND s.sold_at >= ?
              WHERE p.shop_id = ? AND p.is_deleted = 0 AND p.is_active = 1
              GROUP BY p.id, p.name, p.stock_qty ORDER BY sold_30d ASC LIMIT 5',
            [$since, $shopId]
        );

        // Days of cover = stock / average daily sales over the window.
        $cover = Db::all(
            'SELECT p.id, p.name, p.stock_qty, p.min_stock,
                    COALESCE(SUM(CASE WHEN s.sold_at >= ? THEN i.quantity END),0) AS sold_30d
               FROM products p
               LEFT JOIN sale_items i ON i.product_id = p.id
               LEFT JOIN sales s ON s.id = i.sale_id AND s.status <> "cancelled"
              WHERE p.shop_id = ? AND p.is_deleted = 0 AND p.is_active = 1
              GROUP BY p.id, p.name, p.stock_qty, p.min_stock',
            [$since, $shopId]
        );

        $recommendations = [];
        $runOut = [];
        foreach ($cover as $p) {
            $perDay = ((float) $p['sold_30d']) / 30.0;
            if ($perDay <= 0) {
                continue;
            }
            $days = floor(((float) $p['stock_qty']) / $perDay);
            if ($days <= 7) {
                $suggest = (int) ceil($perDay * 14 - (float) $p['stock_qty']);
                $suggest = max($suggest, 1);
                $runOut[] = ['name' => $p['name'], 'days_left' => (int) $days, 'suggested_order' => $suggest];
                $recommendations[] = sprintf(
                    'Your %s stock may run out within %d day(s) at the current selling rate. Consider ordering %d more.',
                    $p['name'], max(1, (int) $days), $suggest
                );
            }
        }

        $thisWeek = array_sum(array_column(array_slice($daily, -7), 'revenue'));
        $lastWeek = array_sum(array_column(array_slice($daily, -14, 7), 'revenue'));
        $trend = $lastWeek > 0 ? round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1) : null;

        if (!$recommendations) {
            $recommendations[] = 'Stock levels look healthy for the next two weeks based on recent sales.';
        }

        return [
            'generated_at'    => Db::now(),
            'window_days'     => 30,
            'best_sellers'    => $best,
            'slow_movers'     => $slow,
            'run_out_soon'    => $runOut,
            'weekly_change'   => ['this_week' => round($thisWeek, 2), 'last_week' => round($lastWeek, 2), 'percent' => $trend],
            'forecast_next_7d'=> self::forecast($daily),
            'recommendations' => $recommendations,
        ];
    }

    /** Damped linear trend on the last 14 days of revenue. */
    private static function forecast(array $daily): array
    {
        $series = array_slice(array_map(fn($r) => (float) $r['revenue'], $daily), -14);
        if (count($series) < 3) {
            return ['daily_average' => $series ? round(array_sum($series) / count($series), 2) : 0, 'next_7d' => 0];
        }
        $n = count($series);
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumXX = 0;
        foreach ($series as $i => $y) {
            $x = $i + 1;
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumXX += $x * $x;
        }
        $denom = ($n * $sumXX - $sumX * $sumX) ?: 1;
        $slope = ($n * $sumXY - $sumX * $sumY) / $denom;
        $intercept = ($sumY - $slope * $sumX) / $n;
        $total = 0.0;
        for ($d = 1; $d <= 7; $d++) {
            $total += max(0, $intercept + $slope * ($n + $d));
        }
        return [
            'daily_average' => round($sumY / $n, 2),
            'slope'         => round($slope, 2),
            'next_7d'       => round($total, 2),
        ];
    }
}
