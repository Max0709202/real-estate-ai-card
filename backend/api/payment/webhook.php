<?php
/**
 * Stripe Webhook Handler
 * Handles subscription and payment events from Stripe
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/qr-helper.php';

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
        error_log("Stripe Webhook: Invalid payload - " . $e->getMessage());
        http_response_code(400);
        sendErrorResponse('Invalid payload', 400);
        exit;
    } catch (SignatureVerificationException $e) {
        error_log("Stripe Webhook: Invalid signature - " . $e->getMessage());
        http_response_code(400);
        sendErrorResponse('Invalid signature', 400);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    // Idempotency: Check if event was already processed
    $eventId = $event['id'];
    $stmt = $db->prepare("SELECT id, processed FROM webhook_event_log WHERE stripe_event_id = ?");
    $stmt->execute([$eventId]);
    $existingEvent = $stmt->fetch();

    if ($existingEvent && $existingEvent['processed']) {
        error_log("Stripe Webhook: Event {$eventId} already processed, skipping");
        sendSuccessResponse([], 'Event already processed');
        exit;
    }

    // Log event (if not exists)
    if (!$existingEvent) {
        $stmt = $db->prepare("
            INSERT INTO webhook_event_log (stripe_event_id, event_type, processed)
            VALUES (?, ?, FALSE)
        ");
        $stmt->execute([$eventId, $event['type']]);
    }

    // Start transaction
    $db->beginTransaction();

    try {
    switch ($event['type']) {
            case 'checkout.session.completed':
                $session = $event['data']['object'];
                $customerId = $session['customer'] ?? null;
                
                if ($customerId) {
                    // Find user by customer ID
                    $stmt = $db->prepare("
                        SELECT u.id as user_id, bc.id as business_card_id
                        FROM users u
                        LEFT JOIN business_cards bc ON u.id = bc.user_id
                        WHERE u.stripe_customer_id = ?
                    ");
                    $stmt->execute([$customerId]);
                    $user = $stmt->fetch();

                    if ($user && $user['business_card_id']) {
                        // Update card status from draft to active
                        $stmt = $db->prepare("
                            UPDATE business_cards
                            SET card_status = 'active',
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$user['business_card_id']]);
                    }
                }
                break;

            case 'invoice.payment_succeeded':
                $invoice = $event['data']['object'];
                $customerId = $invoice['customer'];
                $subscriptionId = $invoice['subscription'] ?? null;

                if ($subscriptionId) {
                    // Update subscription status to active
                    $stmt = $db->prepare("
                        UPDATE subscriptions
                        SET status = 'active',
                            updated_at = NOW()
                        WHERE stripe_subscription_id = ?
                    ");
                    $stmt->execute([$subscriptionId]);

                    // Find business card and ensure it's active
                    $stmt = $db->prepare("
                        SELECT s.business_card_id
                        FROM subscriptions s
                        WHERE s.stripe_subscription_id = ?
                    ");
                    $stmt->execute([$subscriptionId]);
                    $sub = $stmt->fetch();

                    if ($sub) {
                        // Update card status (but don't auto-open)
                        $stmt = $db->prepare("
                            UPDATE business_cards
                            SET card_status = 'active',
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$sub['business_card_id']]);
                    }
                }

                // Also handle one-time payment intents
                $paymentIntentId = $invoice['payment_intent'] ?? null;
                if ($paymentIntentId) {
            $stmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'completed', paid_at = NOW()
                WHERE stripe_payment_intent_id = ? AND payment_status != 'completed'
            ");
            $stmt->execute([$paymentIntentId]);
            
            $stmt = $db->prepare("
                        SELECT p.id as payment_id, p.user_id, p.business_card_id, p.payment_type, p.payment_method, u.user_type, u.stripe_customer_id
                FROM payments p
                        JOIN users u ON p.user_id = u.id
                WHERE p.stripe_payment_intent_id = ? AND p.payment_status = 'completed'
            ");
            $stmt->execute([$paymentIntentId]);
            $payment = $stmt->fetch();
            
            if ($payment) {
                        // Check if this is a Stripe bank transfer (customer_balance)
                        $isStripeBankTransfer = false;
                        if (isset($invoice['payment_intent'])) {
                            try {
                                if (class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                                    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                                    $pi = \Stripe\PaymentIntent::retrieve($invoice['payment_intent']);
                                    if (isset($pi['payment_method_types']) && in_array('customer_balance', $pi['payment_method_types'])) {
                                        $isStripeBankTransfer = true;
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("Error checking payment intent for Stripe bank transfer: " . $e->getMessage());
                            }
                        }
                        
                        // Determine payment status
                        if ($payment['payment_method'] === 'credit_card') {
                            $newPaymentStatus = 'CR';
                        } elseif ($isStripeBankTransfer) {
                            // Stripe bank transfer (Stripe口座への送金)
                            $newPaymentStatus = 'ST';
                        } else {
                            // Regular bank transfer (manual)
                            $newPaymentStatus = 'BANK_PAID';
                        }

                        // 停止されたアカウントの復活処理を確認
                        $stmt = $db->prepare("
                            SELECT card_status, payment_status
                            FROM business_cards
                            WHERE id = ?
                        ");
                        $stmt->execute([$payment['business_card_id']]);
                        $bcStatus = $stmt->fetch();
                        $isReactivation = ($bcStatus && ($bcStatus['card_status'] === 'canceled'));

                        // ST送金の場合は、クレジット決済と同じようにis_publishedを1に設定
                        // 復活の場合もis_publishedを1に設定
                        $isPublished = ($newPaymentStatus === 'ST' || $isReactivation) ? 1 : 0;

                        $stmt = $db->prepare("
                            UPDATE business_cards
                            SET payment_status = ?,
                                card_status = 'active',
                                is_published = ?,
                                updated_at = NOW()
                            WHERE id = ? AND user_id = ?
                        ");
                        $stmt->execute([$newPaymentStatus, $isPublished, $payment['business_card_id'], $payment['user_id']]);

                        enforceOpenPaymentStatusRule($db, $payment['business_card_id'], $newPaymentStatus);
                        
                        // 停止されたアカウントの復活：サブスクリプションの状態も更新
                        if ($isReactivation) {
                            $stmt = $db->prepare("
                                UPDATE subscriptions
                                SET status = 'active',
                                    cancelled_at = NULL,
                                    updated_at = NOW()
                                WHERE user_id = ? AND business_card_id = ?
                            ");
                            $stmt->execute([$payment['user_id'], $payment['business_card_id']]);
                        }

                        // For all users with completed payment, ensure subscription exists after payment
                        // This handles cases where subscription creation failed in create-intent.php or was skipped
                        if ($payment['stripe_customer_id']) {
                            // Check if subscription already exists
                            $stmt = $db->prepare("
                                SELECT id, stripe_subscription_id FROM subscriptions
                                WHERE user_id = ? AND business_card_id = ?
                            ");
                            $stmt->execute([$payment['user_id'], $payment['business_card_id']]);
                            $existingSub = $stmt->fetch();

                            if (!$existingSub) {
                                // Determine monthly amount based on payment_type
                                $monthlyAmount = 0;
                                if ($payment['payment_type'] === 'new_user' || $payment['user_type'] === 'new') {
                                    $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
                                } elseif ($payment['payment_type'] === 'existing_user' || $payment['user_type'] === 'existing') {
                                    // Existing users typically don't have monthly fees, but we'll create subscription anyway for consistency
                                    $monthlyAmount = 0;
                                } else {
                                    // Default: treat as new user if payment_type is unclear
                                    $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
                                }

                                // Try to find existing Stripe subscription for this customer
                                $stripeSubscriptionId = null;
                                try {
                                    if (class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                                        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                                        $stripeSubscriptions = \Stripe\Subscription::all([
                                            'customer' => $payment['stripe_customer_id'],
                                            'limit' => 1,
                                            'status' => 'all'
                                        ]);
                                        
                                        if ($stripeSubscriptions && count($stripeSubscriptions->data) > 0) {
                                            $stripeSubscriptionId = $stripeSubscriptions->data[0]->id;
                                            error_log("Found existing Stripe subscription: {$stripeSubscriptionId} for customer: {$payment['stripe_customer_id']}");
                                        }
                                    }
                                } catch (Exception $stripeError) {
                                    error_log("Error checking for existing Stripe subscription: " . $stripeError->getMessage());
                                    // Continue without Stripe subscription ID
                                }

                                // Create subscription record (even if Stripe subscription doesn't exist yet)
                                // This allows the cancel button to appear and subscription management to work
                                try {
                                    $stmt = $db->prepare("
                                        INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, stripe_customer_id, status, amount, billing_cycle, next_billing_date)
                                        VALUES (?, ?, ?, ?, 'active', ?, 'monthly', DATE_ADD(NOW(), INTERVAL 1 MONTH))
                                    ");
                                    $stmt->execute([
                                        $payment['user_id'],
                                        $payment['business_card_id'],
                                        $stripeSubscriptionId, // NULL if not found, which is acceptable
                                        $payment['stripe_customer_id'],
                                        $monthlyAmount
                                    ]);
                                    error_log("Created subscription record after invoice.payment_succeeded: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, monthly_amount={$monthlyAmount}");
                                    
                                    // Update payment record with subscription ID if found
                                    if ($stripeSubscriptionId) {
                                        $stmt = $db->prepare("UPDATE payments SET stripe_subscription_id = ? WHERE id = ?");
                                        $stmt->execute([$stripeSubscriptionId, $payment['payment_id']]);
                                    }
                            } catch (PDOException $dbError) {
                                // Check if error is due to NOT NULL constraint on stripe_subscription_id
                                if (strpos($dbError->getMessage(), 'cannot be null') !== false || 
                                    strpos($dbError->getMessage(), '1048') !== false ||
                                    strpos($dbError->getMessage(), 'Column \'stripe_subscription_id\' cannot be null') !== false) {
                                    error_log("Database schema error in webhook: stripe_subscription_id column does not allow NULL. Please run migration: backend/database/migrations/make_stripe_subscription_id_nullable.sql");
                                    error_log("Payment details: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, user_type={$payment['user_type']}, stripe_subscription_id was NULL");
                                    // Continue processing - subscription will be created later via cancel.php auto-creation
                                } else {
                                    error_log("Error creating subscription record after invoice.payment_succeeded: " . $dbError->getMessage());
                                    error_log("Payment details: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, user_type={$payment['user_type']}");
                                }
                                } catch (Exception $e) {
                                error_log("Error creating subscription record after invoice.payment_succeeded: " . $e->getMessage());
                                error_log("Payment details: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, user_type={$payment['user_type']}");
                            }
                            } else {
                                // Subscription exists, but check if stripe_subscription_id is missing and try to find it
                                if (empty($existingSub['stripe_subscription_id']) && !empty($payment['stripe_customer_id'])) {
                                    try {
                                        if (class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                                            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                                            $stripeSubscriptions = \Stripe\Subscription::all([
                                                'customer' => $payment['stripe_customer_id'],
                                                'limit' => 1,
                                                'status' => 'all'
                                            ]);
                                            
                                            if ($stripeSubscriptions && count($stripeSubscriptions->data) > 0) {
                                                $stripeSubscriptionId = $stripeSubscriptions->data[0]->id;
                                                $stmt = $db->prepare("UPDATE subscriptions SET stripe_subscription_id = ? WHERE id = ?");
                                                $stmt->execute([$stripeSubscriptionId, $existingSub['id']]);
                                                error_log("Updated subscription with Stripe subscription ID: {$stripeSubscriptionId} for subscription_id: {$existingSub['id']}");
                                            }
                                        }
                                    } catch (Exception $stripeError) {
                                        error_log("Error updating subscription with Stripe ID: " . $stripeError->getMessage());
                                    }
                                }
                            }
                        }

                        if (in_array($newPaymentStatus, ['CR', 'BANK_PAID', 'ST'])) {
                            $stmt = $db->prepare("SELECT qr_code_issued FROM business_cards WHERE id = ?");
                            $stmt->execute([$payment['business_card_id']]);
                            $bc = $stmt->fetch();

                            if ($bc && !$bc['qr_code_issued']) {
                                $qrResult = generateBusinessCardQRCode($payment['business_card_id'], $db);
                                if ($qrResult['success']) {
                                    error_log("QR code generated for business_card_id: " . $payment['business_card_id']);
                                }
                            }
                        }
                    }
                }
                break;

            case 'invoice.payment_failed':
                $invoice = $event['data']['object'];
                $customerId = $invoice['customer'];
                $subscriptionId = $invoice['subscription'] ?? null;

                if ($subscriptionId) {
                    // Update subscription status
                    $stmt = $db->prepare("
                        UPDATE subscriptions
                        SET status = 'past_due',
                            updated_at = NOW()
                        WHERE stripe_subscription_id = ?
                    ");
                    $stmt->execute([$subscriptionId]);

                    // Suspend business card and ensure it's unpublished
                    $stmt = $db->prepare("
                        SELECT s.business_card_id, s.user_id, u.email, bc.url_slug, bc.is_published
                        FROM subscriptions s
                        JOIN users u ON s.user_id = u.id
                        JOIN business_cards bc ON s.business_card_id = bc.id
                        WHERE s.stripe_subscription_id = ?
                    ");
                    $stmt->execute([$subscriptionId]);
                    $sub = $stmt->fetch();

                    if ($sub) {
                        // Always set is_published to FALSE on payment failure
                        $stmt = $db->prepare("
                            UPDATE business_cards
                            SET card_status = 'suspended',
                                is_published = FALSE,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$sub['business_card_id']]);

                        // Log suspension (only if it was previously published)
                        if ($sub['is_published']) {
                            logAdminChange(
                                $db,
                                null,
                                'system',
                                'subscription_suspended',
                                'business_card',
                                $sub['business_card_id'],
                                "サブスクリプション支払い失敗により自動非公開: ユーザー {$sub['email']} (URL: {$sub['url_slug']})"
                            );
                        }
                    }
                }
                break;

            case 'customer.subscription.updated':
                $subscription = $event['data']['object'];
                $subscriptionId = $subscription['id'];
                $customerId = $subscription['customer'];
                $status = $subscription['status'];
                $currentPeriodEnd = $subscription['current_period_end'] ?? null;

                // Map Stripe status to local status
                $localStatus = $status;
                if ($status === 'trialing') {
                    $localStatus = 'active';
                } elseif (in_array($status, ['past_due', 'unpaid'])) {
                    $localStatus = $status;
                } elseif ($status === 'canceled') {
                    $localStatus = 'canceled';
                } elseif ($status === 'incomplete' || $status === 'incomplete_expired') {
                    $localStatus = $status;
                }

                // Update subscription in database
                $nextBillingDate = $currentPeriodEnd ? date('Y-m-d', $currentPeriodEnd) : null;

                $stmt = $db->prepare("
                    SELECT user_id, business_card_id
                    FROM subscriptions
                    WHERE stripe_subscription_id = ?
                ");
                $stmt->execute([$subscriptionId]);
                $existingSub = $stmt->fetch();

                if ($existingSub) {
                    $stmt = $db->prepare("
                        UPDATE subscriptions
                        SET status = ?,
                            next_billing_date = ?,
                            updated_at = NOW()
                        WHERE stripe_subscription_id = ?
                    ");
                    $stmt->execute([$localStatus, $nextBillingDate, $subscriptionId]);

                    // Handle business card status based on subscription status
                    if (in_array($localStatus, ['past_due', 'unpaid', 'canceled', 'incomplete_expired'])) {
                        // Suspend or cancel business card
                        $cardStatus = ($localStatus === 'canceled') ? 'canceled' : 'suspended';
                        
                        $stmt = $db->prepare("
                            UPDATE business_cards
                            SET card_status = ?,
                                is_published = FALSE,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$cardStatus, $existingSub['business_card_id']]);
                    } elseif ($localStatus === 'active') {
                        // Restore to active (but don't auto-open)
                        $stmt = $db->prepare("
                            UPDATE business_cards
                            SET card_status = 'active',
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$existingSub['business_card_id']]);
                    }
                } else {
                    // Subscription not found in DB, try to find by customer ID
                    $stmt = $db->prepare("
                        SELECT u.id as user_id, bc.id as business_card_id
                        FROM users u
                        LEFT JOIN business_cards bc ON u.id = bc.user_id
                        WHERE u.stripe_customer_id = ?
                    ");
                    $stmt->execute([$customerId]);
                    $user = $stmt->fetch();

                    if ($user && $user['business_card_id']) {
                        $stmt = $db->prepare("
                            INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, stripe_customer_id, status, billing_cycle, next_billing_date)
                            VALUES (?, ?, ?, ?, ?, 'monthly', ?)
                            ON DUPLICATE KEY UPDATE
                                status = VALUES(status),
                                next_billing_date = VALUES(next_billing_date),
                                updated_at = NOW()
                        ");
                        $stmt->execute([
                            $user['user_id'],
                            $user['business_card_id'],
                            $subscriptionId,
                            $customerId,
                            $localStatus,
                            $nextBillingDate
                        ]);
                    }
                }
                break;

            case 'customer.subscription.deleted':
                $subscription = $event['data']['object'];
                $subscriptionId = $subscription['id'];
                
                $stmt = $db->prepare("
                    SELECT business_card_id, user_id
                    FROM subscriptions
                    WHERE stripe_subscription_id = ?
                ");
                $stmt->execute([$subscriptionId]);
                $sub = $stmt->fetch();

                if ($sub) {
                    $stmt = $db->prepare("
                        UPDATE subscriptions 
                        SET status = 'canceled',
                            cancelled_at = NOW(),
                            updated_at = NOW()
                        WHERE stripe_subscription_id = ?
                    ");
                    $stmt->execute([$subscriptionId]);

                    // Cancel business card
                $stmt = $db->prepare("
                    UPDATE business_cards
                        SET card_status = 'canceled',
                            is_published = FALSE,
                            updated_at = NOW()
                        WHERE id = ?
                ");
                    $stmt->execute([$sub['business_card_id']]);
                }
                break;

            case 'payment_intent.succeeded':
                $paymentIntent = $event['data']['object'];
                $paymentIntentId = $paymentIntent['id'];
                
                $stmt = $db->prepare("
                    UPDATE payments 
                    SET payment_status = 'completed', paid_at = NOW()
                    WHERE stripe_payment_intent_id = ? AND payment_status != 'completed'
                ");
                $stmt->execute([$paymentIntentId]);
                
                $stmt = $db->prepare("
                    SELECT p.id as payment_id, p.user_id, p.business_card_id, p.payment_type, p.payment_method, u.user_type, u.stripe_customer_id
                    FROM payments p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.stripe_payment_intent_id = ? AND p.payment_status = 'completed'
                ");
                $stmt->execute([$paymentIntentId]);
                $payment = $stmt->fetch();

                if ($payment) {
                    // Check if this is a Stripe bank transfer (customer_balance)
                    // Stripe bank transfers use customer_balance payment method type
                    $isStripeBankTransfer = false;
                    if (isset($paymentIntent['payment_method_types']) && in_array('customer_balance', $paymentIntent['payment_method_types'])) {
                        $isStripeBankTransfer = true;
                    } elseif (isset($paymentIntent['payment_method'])) {
                        // If payment_method is an object, check its type
                        try {
                            if (is_string($paymentIntent['payment_method'])) {
                                // Fetch payment method details from Stripe
                                if (class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                                    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                                    $pm = \Stripe\PaymentMethod::retrieve($paymentIntent['payment_method']);
                                    if ($pm->type === 'customer_balance') {
                                        $isStripeBankTransfer = true;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Error checking payment method type: " . $e->getMessage());
                        }
                    }
                    
                    // Determine payment status
                    if ($payment['payment_method'] === 'credit_card') {
                        $newPaymentStatus = 'CR';
                    } elseif ($isStripeBankTransfer) {
                        // Stripe bank transfer (Stripe口座への送金)
                        $newPaymentStatus = 'ST';
                    } else {
                        // Regular bank transfer (manual)
                        $newPaymentStatus = 'BANK_PAID';
                    }

                    // 停止されたアカウントの復活処理を確認
                    $stmt = $db->prepare("
                        SELECT card_status, payment_status
                        FROM business_cards
                        WHERE id = ?
                    ");
                    $stmt->execute([$payment['business_card_id']]);
                    $bcStatus = $stmt->fetch();
                    $isReactivation = ($bcStatus && ($bcStatus['card_status'] === 'canceled'));

                    // ST送金の場合は、クレジット決済と同じようにis_publishedを1に設定
                    // 復活の場合もis_publishedを1に設定
                    $isPublished = ($newPaymentStatus === 'ST' || $isReactivation) ? 1 : 0;

                    $stmt = $db->prepare("
                        UPDATE business_cards
                        SET payment_status = ?,
                            card_status = 'active',
                            is_published = ?,
                            updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$newPaymentStatus, $isPublished, $payment['business_card_id'], $payment['user_id']]);

                    enforceOpenPaymentStatusRule($db, $payment['business_card_id'], $newPaymentStatus);
                    
                    // 停止されたアカウントの復活：サブスクリプションの状態も更新
                    if ($isReactivation) {
                        $stmt = $db->prepare("
                            UPDATE subscriptions
                            SET status = 'active',
                                cancelled_at = NULL,
                                updated_at = NOW()
                            WHERE user_id = ? AND business_card_id = ?
                        ");
                        $stmt->execute([$payment['user_id'], $payment['business_card_id']]);
                    }

                    // For all users with completed payment, ensure subscription exists after payment
                    // This handles cases where subscription creation failed in create-intent.php or was skipped
                    if ($payment['stripe_customer_id']) {
                        // Check if subscription already exists
                        $stmt = $db->prepare("
                            SELECT id, stripe_subscription_id FROM subscriptions
                            WHERE user_id = ? AND business_card_id = ?
                        ");
                        $stmt->execute([$payment['user_id'], $payment['business_card_id']]);
                        $existingSub = $stmt->fetch();

                        if (!$existingSub) {
                            // Determine monthly amount based on payment_type
                            $monthlyAmount = 0;
                            if ($payment['payment_type'] === 'new_user' || $payment['user_type'] === 'new') {
                                $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
                            } elseif ($payment['payment_type'] === 'existing_user' || $payment['user_type'] === 'existing') {
                                // Existing users typically don't have monthly fees, but we'll create subscription anyway for consistency
                                $monthlyAmount = 0;
                            } else {
                                // Default: treat as new user if payment_type is unclear
                                $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
                            }

                            // Try to find existing Stripe subscription for this customer
                            $stripeSubscriptionId = null;
                            try {
                                if (class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                                    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                                    $stripeSubscriptions = \Stripe\Subscription::all([
                                        'customer' => $payment['stripe_customer_id'],
                                        'limit' => 1,
                                        'status' => 'all'
                                    ]);
                                    
                                    if ($stripeSubscriptions && count($stripeSubscriptions->data) > 0) {
                                        $stripeSubscriptionId = $stripeSubscriptions->data[0]->id;
                                        error_log("Found existing Stripe subscription: {$stripeSubscriptionId} for customer: {$payment['stripe_customer_id']}");
                                    }
                                }
                            } catch (Exception $stripeError) {
                                error_log("Error checking for existing Stripe subscription: " . $stripeError->getMessage());
                                // Continue without Stripe subscription ID
                            }

                            // Create subscription record (even if Stripe subscription doesn't exist yet)
                            // This allows the cancel button to appear and subscription management to work
                            try {
                                $stmt = $db->prepare("
                                    INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, stripe_customer_id, status, amount, billing_cycle, next_billing_date)
                                    VALUES (?, ?, ?, ?, 'active', ?, 'monthly', DATE_ADD(NOW(), INTERVAL 1 MONTH))
                                ");
                                $stmt->execute([
                                    $payment['user_id'],
                                    $payment['business_card_id'],
                                    $stripeSubscriptionId, // NULL if not found, which is acceptable
                                    $payment['stripe_customer_id'],
                                    $monthlyAmount
                                ]);
                                error_log("Created subscription record after payment_intent.succeeded: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, monthly_amount={$monthlyAmount}");
                                
                                // Update payment record with subscription ID if found
                                if ($stripeSubscriptionId && isset($payment['payment_id']) && $payment['payment_id']) {
                                    $stmt = $db->prepare("UPDATE payments SET stripe_subscription_id = ? WHERE id = ?");
                                    $stmt->execute([$stripeSubscriptionId, $payment['payment_id']]);
                                }
                            } catch (PDOException $dbError) {
                                // Check if error is due to NOT NULL constraint on stripe_subscription_id
                                if (strpos($dbError->getMessage(), 'cannot be null') !== false || 
                                    strpos($dbError->getMessage(), '1048') !== false ||
                                    strpos($dbError->getMessage(), 'Column \'stripe_subscription_id\' cannot be null') !== false) {
                                    error_log("Database schema error in webhook: stripe_subscription_id column does not allow NULL. Please run migration: backend/database/migrations/make_stripe_subscription_id_nullable.sql");
                                    error_log("Payment details: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, user_type={$payment['user_type']}, stripe_subscription_id was NULL");
                                    // Continue processing - subscription will be created later via cancel.php auto-creation
                                } else {
                                    error_log("Error creating subscription record after payment_intent.succeeded: " . $dbError->getMessage());
                                    error_log("Payment details: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, user_type={$payment['user_type']}");
                                }
                            } catch (Exception $e) {
                                error_log("Error creating subscription record after payment_intent.succeeded: " . $e->getMessage());
                                error_log("Payment details: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}, payment_type={$payment['payment_type']}, user_type={$payment['user_type']}");
                        }
                        } else {
                            // Subscription exists, but check if stripe_subscription_id is missing and try to find it
                            if (empty($existingSub['stripe_subscription_id']) && !empty($payment['stripe_customer_id'])) {
                                try {
                                    if (class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                                        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                                        $stripeSubscriptions = \Stripe\Subscription::all([
                                            'customer' => $payment['stripe_customer_id'],
                                            'limit' => 1,
                                            'status' => 'all'
                                        ]);
                                        
                                        if ($stripeSubscriptions && count($stripeSubscriptions->data) > 0) {
                                            $stripeSubscriptionId = $stripeSubscriptions->data[0]->id;
                                            $stmt = $db->prepare("UPDATE subscriptions SET stripe_subscription_id = ? WHERE id = ?");
                                            $stmt->execute([$stripeSubscriptionId, $existingSub['id']]);
                                            error_log("Updated subscription with Stripe subscription ID: {$stripeSubscriptionId} for subscription_id: {$existingSub['id']}");
                                        }
                                    }
                                } catch (Exception $stripeError) {
                                    error_log("Error updating subscription with Stripe ID: " . $stripeError->getMessage());
                                }
                            }
                        }
                    }

                    if (in_array($newPaymentStatus, ['CR', 'BANK_PAID', 'ST'])) {
                        $stmt = $db->prepare("SELECT qr_code_issued FROM business_cards WHERE id = ?");
                $stmt->execute([$payment['business_card_id']]);
                $bc = $stmt->fetch();
                
                if ($bc && !$bc['qr_code_issued']) {
                    $qrResult = generateBusinessCardQRCode($payment['business_card_id'], $db);
                    if ($qrResult['success']) {
                                error_log("QR code generated for business_card_id: " . $payment['business_card_id']);
                            }
                    }
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
        }

        // Mark event as processed
            $stmt = $db->prepare("
            UPDATE webhook_event_log
            SET processed = TRUE, processed_at = NOW()
            WHERE stripe_event_id = ?
            ");
        $stmt->execute([$eventId]);

        $db->commit();
        sendSuccessResponse([], 'Webhook processed');

    } catch (Exception $e) {
        $db->rollBack();
        
        // Log error
                $stmt = $db->prepare("
            UPDATE webhook_event_log
            SET error_message = ?
            WHERE stripe_event_id = ?
            ");
        $stmt->execute([$e->getMessage(), $eventId]);
        
        throw $e;
    }

} catch (Exception $e) {
    error_log("Stripe Webhook Error: " . $e->getMessage());
    error_log("Stripe Webhook Event: " . json_encode($event ?? []));
    http_response_code(400);
    sendErrorResponse('Webhook processing failed: ' . $e->getMessage(), 400);
}
