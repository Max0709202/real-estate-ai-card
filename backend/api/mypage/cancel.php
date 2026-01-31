<?php
/**
 * Cancel Subscription API
 * Allows users to cancel their subscription from My Page
 */
// Set error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/stripe-service.php';
require_once __DIR__ . '/../middleware/auth.php';
} catch (Exception $e) {
    error_log("Cancel API require error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();

    $input = json_decode(file_get_contents('php://input'), true);
    $cancelImmediately = isset($input['cancel_immediately']) && $input['cancel_immediately'] === true;

    $db = (new Database())->getConnection();

    // Get user's subscription - check for any subscription regardless of status
    // This handles cases where subscription might be in various states
    $stmt = $db->prepare("
        SELECT s.id, s.stripe_subscription_id, s.business_card_id, s.user_id, s.status, u.email
        FROM subscriptions s
        JOIN users u ON s.user_id = u.id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $subscription = $stmt->fetch();

    if (!$subscription) {
        // Check if user has a business card with payment completed but no subscription
        // This can happen if payment was completed but subscription creation failed or was skipped
        $stmt = $db->prepare("
            SELECT bc.id, bc.payment_status, bc.card_status, u.user_type, u.stripe_customer_id
            FROM business_cards bc
            JOIN users u ON bc.user_id = u.id
            WHERE bc.user_id = ? AND bc.payment_status IN ('CR', 'BANK_PAID')
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $businessCard = $stmt->fetch();

        if ($businessCard) {
            // Automatically create subscription if payment is completed but subscription doesn't exist
            // This handles cases where subscription creation was missed during payment processing
            try {
                // Determine monthly amount based on user type and payment status
                $monthlyAmount = 0;
                if ($businessCard['user_type'] === 'new' || $businessCard['user_type'] === null) {
                    $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
                } elseif ($businessCard['user_type'] === 'existing') {
                    // Existing users typically don't have monthly fees, but we'll create subscription anyway
                    $monthlyAmount = 0;
                } else {
                    // Default: treat as new user
                    $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
                }

                // Try to find existing Stripe subscription for this customer
                $stripeSubscriptionId = null;
                if (!empty($businessCard['stripe_customer_id'])) {
                    try {
                        if (class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                            $stripeSubscriptions = \Stripe\Subscription::all([
                                'customer' => $businessCard['stripe_customer_id'],
                                'limit' => 1,
                                'status' => 'all'
                            ]);

                            if ($stripeSubscriptions && count($stripeSubscriptions->data) > 0) {
                                $stripeSubscriptionId = $stripeSubscriptions->data[0]->id;
                                error_log("Found existing Stripe subscription: {$stripeSubscriptionId} for customer: {$businessCard['stripe_customer_id']}");
                            }
                        }
                    } catch (Exception $stripeError) {
                        error_log("Error checking for existing Stripe subscription: " . $stripeError->getMessage());
                        // Continue without Stripe subscription ID
                    }
                }

                // Create subscription record
                // Handle case where stripe_subscription_id might not allow NULL yet (before migration)
                // If NULL and column doesn't allow NULL, use a temporary placeholder that can be updated later
                $subscriptionIdForInsert = $stripeSubscriptionId;
                if (empty($subscriptionIdForInsert)) {
                    // Check if column allows NULL by attempting to insert with NULL first
                    // If it fails, we'll catch the error and provide a better message
                    $subscriptionIdForInsert = null;
                }

                try {
                    $stmt = $db->prepare("
                        INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, stripe_customer_id, status, amount, billing_cycle, next_billing_date)
                        VALUES (?, ?, ?, ?, 'active', ?, 'monthly', DATE_ADD(NOW(), INTERVAL 1 MONTH))
                    ");
                    $stmt->execute([
                        $userId,
                        $businessCard['id'],
                        $subscriptionIdForInsert,
                        $businessCard['stripe_customer_id'] ?? null,
                        $monthlyAmount
                    ]);
                } catch (PDOException $dbError) {
                    // If error is due to NOT NULL constraint, provide helpful message
                    if (strpos($dbError->getMessage(), 'cannot be null') !== false || 
                        strpos($dbError->getMessage(), '1048') !== false) {
                        error_log("Database schema error: stripe_subscription_id column does not allow NULL. Please run migration: backend/database/migrations/make_stripe_subscription_id_nullable.sql");
                        throw new Exception('データベーススキーマの更新が必要です。管理者にお問い合わせください。エラー: stripe_subscription_idカラムがNULLを許可していません。');
                    }
                    throw $dbError;
                }

                error_log("Auto-created subscription for user_id={$userId}, bc_id={$businessCard['id']} in cancel.php. Monthly amount: {$monthlyAmount}");

                // Re-fetch subscription
                $stmt = $db->prepare("
                    SELECT s.id, s.stripe_subscription_id, s.business_card_id, s.user_id, s.status, u.email
                    FROM subscriptions s
                    JOIN users u ON s.user_id = u.id
                    WHERE s.user_id = ? AND s.business_card_id = ?
                    ORDER BY s.created_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$userId, $businessCard['id']]);
                $subscription = $stmt->fetch();

                if (!$subscription) {
                    sendErrorResponse('サブスクリプションの作成に失敗しました。管理者にお問い合わせください。', 500);
                }
            } catch (Exception $e) {
                error_log("Error auto-creating subscription in cancel.php: " . $e->getMessage());
                sendErrorResponse('サブスクリプションがまだ作成されていません。支払いは完了していますが、サブスクリプションの設定が完了していない可能性があります。しばらくお待ちいただくか、管理者にお問い合わせください。', 404);
            }
        } else {
            sendErrorResponse('アクティブなサブスクリプションが見つかりません。支払いが完了していないか、サブスクリプションが存在しません。', 404);
        }
    }

    // Check if subscription is already canceled
    if ($subscription['status'] === 'canceled') {
        sendErrorResponse('このサブスクリプションは既にキャンセルされています', 400);
    }

    // Check if subscription status allows cancellation
    if (!in_array($subscription['status'], ['active', 'trialing', 'past_due', 'incomplete'])) {
        sendErrorResponse('このサブスクリプションはキャンセルできません（ステータス: ' . $subscription['status'] . '）', 400);
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
        $newStatus = 'canceled';
        $stripeSubscription = null;

        // Cancel subscription in Stripe only if stripe_subscription_id exists
        if (!empty($subscription['stripe_subscription_id'])) {
            try {
        $stripeService = new StripeService();
        $stripeSubscription = $stripeService->cancelSubscription($subscription['stripe_subscription_id'], $cancelImmediately);

                // Update status based on Stripe response
                if ($stripeSubscription) {
        $newStatus = ($cancelImmediately || $stripeSubscription->status === 'canceled') ? 'canceled' : 'active'; // Will be canceled at period end
                } else {
                    // If Stripe cancellation fails, still mark as canceled in DB
                    $newStatus = 'canceled';
                }
            } catch (Exception $stripeError) {
                error_log("Stripe cancellation error: " . $stripeError->getMessage());
                // If Stripe cancellation fails, still mark as canceled in DB
                // This handles cases where Stripe subscription doesn't exist but DB record does
                $newStatus = 'canceled';
            }
        } else {
            // No Stripe subscription ID - just update database
            // This can happen for subscriptions created without Stripe (e.g., bank transfer only)
            $newStatus = 'canceled';
        }
        
        $stmt = $db->prepare("
            UPDATE subscriptions
            SET status = ?,
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $subscription['id']]);

        // Update business card status
        // When cancel_immediately: revoke access immediately
        // When cancel_at_period_end (Stripe): user keeps access until period end; Stripe webhook (customer.subscription.deleted) will revoke when period ends
        // When no Stripe (e.g. bank transfer): revoke immediately as we cannot schedule cancel_at_period_end
        $shouldRevokeNow = $cancelImmediately || empty($subscription['stripe_subscription_id']);
        if ($shouldRevokeNow) {
            $stmt = $db->prepare("
                UPDATE business_cards
                SET card_status = 'canceled',
                    is_published = FALSE,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$subscription['business_card_id']]);
        }

        // Log the cancellation
        // Only log to admin_change_logs if operation is performed by admin
        // Otherwise, use regular error_log for user-initiated cancellations
        if (!empty($_SESSION['admin_id'])) {
            // Admin-initiated cancellation
        logAdminChange(
            $db,
                $_SESSION['admin_id'],
                $_SESSION['admin_email'] ?? 'unknown',
            'subscription_canceled',
            'subscription',
            $subscription['id'],
                "サブスクリプションキャンセル（管理者操作）: ユーザー {$subscription['email']} (ビジネスカードID: {$subscription['business_card_id']}, URL: {$businessCard['url_slug']})"
        );
        } else {
            // User-initiated cancellation - log to error log instead
            error_log("Subscription canceled by user: user_id={$userId}, email={$subscription['email']}, subscription_id={$subscription['id']}, business_card_id={$subscription['business_card_id']}, url_slug={$businessCard['url_slug']}, cancel_immediately=" . ($cancelImmediately ? 'yes' : 'no'));
        }

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

