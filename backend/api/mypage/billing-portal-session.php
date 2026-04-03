<?php
/**
 * Stripe Customer Billing Portal セッション作成（登録カードの変更など）
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\Subscription;

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    if (empty(STRIPE_SECRET_KEY)) {
        sendErrorResponse('決済設定が完了していません', 500);
    }

    $userId = requireAuth();
    $db = (new Database())->getConnection();

    $stmt = $db->prepare('
        SELECT stripe_customer_id, user_type
        FROM users
        WHERE id = ?
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        sendErrorResponse('ユーザーが見つかりません', 404);
    }

    $stripeCustomerId = $user['stripe_customer_id'] ?? '';

    $stmt = $db->prepare('
        SELECT stripe_subscription_id, status
        FROM subscriptions
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub || empty($sub['stripe_subscription_id'])) {
        sendErrorResponse('変更対象のサブスクリプションがありません', 403);
    }

    if (!in_array($sub['status'], ['active', 'trialing', 'past_due'], true)) {
        sendErrorResponse('現在の状態ではカードを変更できません', 403);
    }

    $stmt = $db->prepare('
        SELECT payment_method
        FROM payments
        WHERE user_id = ? AND payment_status = \'completed\'
        ORDER BY paid_at DESC, created_at DESC
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $lastPaymentMethod = $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT payment_status FROM business_cards WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $cardPaymentStatus = $stmt->fetchColumn();

    if ($cardPaymentStatus === 'ST') {
        $lastPaymentMethod = 'bank_transfer';
    }

    if ($lastPaymentMethod !== 'credit_card') {
        sendErrorResponse('クレジットカード決済のお客様のみご利用いただけます', 403);
    }

    Stripe::setApiKey(STRIPE_SECRET_KEY);

    if ($stripeCustomerId === '') {
        $stripeSub = Subscription::retrieve($sub['stripe_subscription_id']);
        $cid = $stripeSub->customer ?? null;
        if (empty($cid)) {
            sendErrorResponse('顧客情報を確認できませんでした', 500);
        }
        $stripeCustomerId = is_string($cid) ? $cid : $cid->id;
        $upd = $db->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?');
        $upd->execute([$stripeCustomerId, $userId]);
    }

    $returnPath = '/edit.php';
    if (($user['user_type'] ?? '') === 'existing') {
        $returnPath .= '?type=existing';
    }

    $params = [
        'customer' => $stripeCustomerId,
        'return_url' => rtrim(BASE_URL, '/') . $returnPath,
    ];

    if (defined('STRIPE_BILLING_PORTAL_CONFIGURATION_ID') && STRIPE_BILLING_PORTAL_CONFIGURATION_ID !== '') {
        $params['configuration'] = STRIPE_BILLING_PORTAL_CONFIGURATION_ID;
    }

    $session = BillingPortalSession::create($params);

    sendSuccessResponse(['url' => $session->url], 'OK');
} catch (ApiErrorException $e) {
    error_log('Billing portal session Stripe error: ' . $e->getMessage());
    sendErrorResponse('カード変更画面を開けませんでした。しばらくしてから再度お試しください。', 502);
} catch (Exception $e) {
    error_log('Billing portal session error: ' . $e->getMessage());
    sendErrorResponse('エラーが発生しました', 500);
}
