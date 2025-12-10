<?php
/**
 * Stripe Webhook Handler
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Stripe SDK読み込み
require_once __DIR__ . '/../../vendor/autoload.php';
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

header('Content-Type: application/json; charset=UTF-8');

try {
    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $endpoint_secret = STRIPE_WEBHOOK_SECRET;

    // Webhook署名検証
    $event = null;
    try {
        $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
    } catch (\UnexpectedValueException $e) {
        // Invalid payload
        error_log("Stripe Webhook: Invalid payload - " . $e->getMessage());
        http_response_code(400);
        sendErrorResponse('Invalid payload', 400);
        exit;
    } catch (SignatureVerificationException $e) {
        // Invalid signature
        error_log("Stripe Webhook: Invalid signature - " . $e->getMessage());
        http_response_code(400);
        sendErrorResponse('Invalid signature', 400);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    switch ($event['type']) {
        case 'payment_intent.succeeded':
            $paymentIntent = $event['data']['object'];
            $paymentIntentId = $paymentIntent['id'];
            
            // 決済ステータス更新
            $stmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'completed', paid_at = NOW()
                WHERE stripe_payment_intent_id = ? AND payment_status != 'completed'
            ");
            $stmt->execute([$paymentIntentId]);
            
            // 決済完了後の処理（QRコード発行など）
            $stmt = $db->prepare("
                SELECT p.user_id, p.business_card_id, p.payment_type
                FROM payments p
                WHERE p.stripe_payment_intent_id = ? AND p.payment_status = 'completed'
            ");
            $stmt->execute([$paymentIntentId]);
            $payment = $stmt->fetch();
            
            if ($payment) {
                // ビジネスカードの公開状態を更新
                $stmt = $db->prepare("
                    UPDATE business_cards
                    SET payment_status = 'paid', is_published = TRUE
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$payment['business_card_id'], $payment['user_id']]);
                
                // QRコードが未発行の場合、発行処理をトリガー
                $stmt = $db->prepare("
                    SELECT qr_code_issued FROM business_cards WHERE id = ?
                ");
                $stmt->execute([$payment['business_card_id']]);
                $bc = $stmt->fetch();
                
                if ($bc && !$bc['qr_code_issued']) {
                    // QRコード発行APIを呼び出す（非同期で実行）
                    // ここではログに記録するのみ（実際の実装ではバックグラウンドジョブを使用）
                    error_log("QR code generation triggered for business_card_id: " . $payment['business_card_id']);
                }
            }
            
            break;

        case 'payment_intent.payment_failed':
            $paymentIntent = $event['data']['object'];
            $paymentIntentId = $paymentIntent['id'];
            $failureMessage = $paymentIntent['last_payment_error']['message'] ?? 'Payment failed';
            
            $stmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'failed'
                WHERE stripe_payment_intent_id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$paymentIntentId]);
            
            error_log("Payment failed for PaymentIntent: {$paymentIntentId} - {$failureMessage}");
            break;

        case 'payment_intent.canceled':
            $paymentIntent = $event['data']['object'];
            $paymentIntentId = $paymentIntent['id'];
            
            $stmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'failed'
                WHERE stripe_payment_intent_id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$paymentIntentId]);
            break;

        case 'customer.subscription.created':
        case 'customer.subscription.updated':
            $subscription = $event['data']['object'];
            $subscriptionId = $subscription['id'];
            $customerId = $subscription['customer'];
            $status = $subscription['status'];
            $currentPeriodEnd = $subscription['current_period_end'] ?? null;

            // サブスクリプション情報を保存/更新
            $stmt = $db->prepare("
                SELECT user_id, business_card_id
                FROM users
                WHERE stripe_customer_id = ?
            ");
            $stmt->execute([$customerId]);
            $user = $stmt->fetch();

            if ($user) {
                $nextBillingDate = $currentPeriodEnd ? date('Y-m-d', $currentPeriodEnd) : null;
                $subscriptionStatus = ($status === 'active' || $status === 'trialing') ? 'active' : 'cancelled';

                $stmt = $db->prepare("
                    INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, status, billing_cycle, next_billing_date)
                    VALUES (?, ?, ?, ?, 'monthly', ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        next_billing_date = VALUES(next_billing_date),
                        updated_at = NOW()
                ");
                $stmt->execute([
                    $user['user_id'],
                    $user['business_card_id'],
                    $subscriptionId,
                    $subscriptionStatus,
                    $nextBillingDate
                ]);
            }
            break;

        case 'customer.subscription.deleted':
            $subscription = $event['data']['object'];
            $subscriptionId = $subscription['id'];
            
            $stmt = $db->prepare("
                UPDATE subscriptions 
                SET status = 'cancelled', cancelled_at = NOW()
                WHERE stripe_subscription_id = ?
            ");
            $stmt->execute([$subscriptionId]);
            break;
    }

    sendSuccessResponse([], 'Webhook processed');

} catch (Exception $e) {
    error_log("Stripe Webhook Error: " . $e->getMessage());
    http_response_code(400);
    sendErrorResponse('Webhook processing failed', 400);
}

