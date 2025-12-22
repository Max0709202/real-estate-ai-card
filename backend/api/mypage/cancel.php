<?php
/**
 * Cancel Subscription API
 * Allows users to cancel their subscription from My Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/stripe-service.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();

    $input = json_decode(file_get_contents('php://input'), true);
    $cancelImmediately = isset($input['cancel_immediately']) && $input['cancel_immediately'] === true;

    $db = (new Database())->getConnection();

    // Get user's subscription
    $stmt = $db->prepare("
        SELECT s.id, s.stripe_subscription_id, s.business_card_id, s.user_id, u.email
        FROM subscriptions s
        JOIN users u ON s.user_id = u.id
        WHERE s.user_id = ? AND s.status IN ('active', 'trialing', 'past_due')
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $subscription = $stmt->fetch();

    if (!$subscription) {
        sendErrorResponse('アクティブなサブスクリプションが見つかりません', 404);
    }

    // Get business card info
    $stmt = $db->prepare("SELECT id, url_slug FROM business_cards WHERE id = ?");
    $stmt->execute([$subscription['business_card_id']]);
    $businessCard = $stmt->fetch();

    if (!$businessCard) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Cancel subscription in Stripe
        $stripeService = new StripeService();
        $stripeSubscription = $stripeService->cancelSubscription($subscription['stripe_subscription_id'], $cancelImmediately);

        // Update database
        $newStatus = ($cancelImmediately || $stripeSubscription->status === 'canceled') ? 'canceled' : 'active'; // Will be canceled at period end
        
        $stmt = $db->prepare("
            UPDATE subscriptions
            SET status = ?,
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $subscription['id']]);

        // Update business card status
        $stmt = $db->prepare("
            UPDATE business_cards
            SET card_status = 'canceled',
                is_published = FALSE,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$subscription['business_card_id']]);

        // Log the cancellation
        logAdminChange(
            $db,
            $userId,
            $subscription['email'],
            'subscription_canceled',
            'subscription',
            $subscription['id'],
            "サブスクリプションキャンセル: ユーザー {$subscription['email']} (ビジネスカードID: {$subscription['business_card_id']}, URL: {$businessCard['url_slug']})"
        );

        $db->commit();

        $message = $cancelImmediately 
            ? 'サブスクリプションを即座にキャンセルしました'
            : 'サブスクリプションを期間終了時にキャンセルするよう設定しました';

        sendSuccessResponse([
            'subscription_id' => $subscription['id'],
            'status' => $newStatus,
            'canceled_at_period_end' => !$cancelImmediately
        ], $message);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Cancel Subscription Error: " . $e->getMessage());
    sendErrorResponse('サブスクリプションのキャンセルに失敗しました: ' . $e->getMessage(), 500);
}

