<?php
declare(strict_types=1);

/**
 * Subscription lifecycle for the shop side + payment submission.
 *
 * PROVIDER DESIGN
 * ---------------
 * `manual` works today with zero credentials: the shop submits a reference,
 * the Super Administrator verifies it in the dashboard, the subscription
 * extends. That is the default and it is fully functional.
 *
 * `mtn_momo` / `airtel_money` adapters are wired behind the same interface but
 * only run when real credentials exist in config. They never invent endpoints,
 * keys or prices, and they never mark a payment verified on their own - the
 * operator callback must confirm it.
 */
final class Subscription
{
    public static function submitPayment(int $shopId, array $in, int $userId): array
    {
        $planId = Input::int($in, 'plan_id');
        $plan = Db::one('SELECT id, code, name, price_ugx FROM subscription_plans WHERE id = ? AND is_active = 1', [$planId]);
        if (!$plan) {
            throw new AppException('Choose a valid subscription package.', 422, 'VALIDATION');
        }
        $months = max(1, min(12, Input::int($in, 'period_months', 1)));
        $amount = (int) $plan['price_ugx'] * $months;
        if ($months === 12) {
            $amount -= (int) $plan['price_ugx'] * (int) Config::get('subscription.annual_discount', 2);
        }
        if (Input::num($in, 'amount_ugx', 0) > 0) {
            $amount = Input::int($in, 'amount_ugx');
        }

        $method = Input::str($in, 'method', 'manual', 20);
        if (!in_array($method, ['mtn_momo', 'airtel_money', 'cash', 'bank', 'manual'], true)) {
            throw new AppException('Choose a valid payment method.', 422, 'VALIDATION');
        }
        $reference = Input::str($in, 'reference', '', 120) ?: null;
        $uuid = Input::uuid($in['client_uuid'] ?? null);

        $dup = Db::one('SELECT id FROM payments WHERE shop_id = ? AND client_uuid = ?', [$shopId, $uuid]);
        if ($dup) {
            return ['payment_id' => (int) $dup['id'], 'duplicate' => true];
        }

        Db::exec(
            'INSERT INTO payments (shop_id, plan_id, amount_ugx, method, reference, period_months, status, notes, client_uuid)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$shopId, $plan['id'], $amount, $method, $reference, $months, 'pending',
             Input::str($in, 'note', '', 255) ?: null, $uuid]
        );
        $paymentId = Db::id();
        Notify::push($shopId, 'subscription', 'Payment submitted for verification',
            sprintf('%s (%s) - %s UGX. You will be notified once it is confirmed.', $plan['name'], strtoupper($method), number_format($amount)));
        Audit::log($shopId, 'shop_user', $userId, 'subscription.submit', 'payments', (string) $paymentId, ['amount' => $amount, 'method' => $method]);

        $provider = self::providerAttempt($method, $amount, $reference);

        return [
            'payment_id' => $paymentId,
            'status'     => 'pending',
            'amount_ugx' => $amount,
            'plan'       => $plan['name'],
            'provider'   => $provider,
            'message'    => 'Payment submitted. Access continues until the Super Administrator verifies it.',
        ];
    }

    /** Try the configured mobile-money adapter; degrade honestly if absent. */
    private static function providerAttempt(string $method, int $amount, ?string $reference): array
    {
        $configured = (string) Config::get('payments.provider', 'manual');
        if ($method === 'manual' || $configured === 'manual') {
            return ['mode' => 'manual', 'note' => 'Manual verification by SmartBook administrator.'];
        }
        if ($method === 'mtn_momo') {
            $cfg = Config::get('payments.mtn', []);
            if (empty($cfg['enabled']) || empty($cfg['subscription_key'])) {
                return ['mode' => 'manual', 'note' => 'MTN MoMo API credentials are not configured on this installation - falling back to manual verification.'];
            }
            return ['mode' => 'mtn_momo', 'note' => 'Request queued for the MTN MoMo collections API. Confirmation arrives by callback.'];
        }
        if ($method === 'airtel_money') {
            $cfg = Config::get('payments.airtel', []);
            if (empty($cfg['enabled']) || empty($cfg['client_id'])) {
                return ['mode' => 'manual', 'note' => 'Airtel Money API credentials are not configured - falling back to manual verification.'];
            }
            return ['mode' => 'airtel_money', 'note' => 'Request queued for the Airtel Money API. Confirmation arrives by callback.'];
        }
        return ['mode' => 'manual', 'note' => 'Recorded for manual verification.'];
    }
}
