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
                        SELECT p.user_id, p.business_card_id, p.payment_type, p.payment_method, u.user_type, u.stripe_customer_id
                FROM payments p
                        JOIN users u ON p.user_id = u.id
                WHERE p.stripe_payment_intent_id = ? AND p.payment_status = 'completed'
            ");
            $stmt->execute([$paymentIntentId]);
            $payment = $stmt->fetch();
            
            if ($payment) {
                        $newPaymentStatus = ($payment['payment_method'] === 'credit_card') ? 'CR' : 'BANK_PAID';

                        $stmt = $db->prepare("
                            UPDATE business_cards
                            SET payment_status = ?,
                                card_status = 'active',
                                updated_at = NOW()
                            WHERE id = ? AND user_id = ?
                        ");
                        $stmt->execute([$newPaymentStatus, $payment['business_card_id'], $payment['user_id']]);

                        enforceOpenPaymentStatusRule($db, $payment['business_card_id'], $newPaymentStatus);

                        // For new users, ensure subscription exists after payment
                        if ($payment['user_type'] === 'new' && $payment['stripe_customer_id']) {
                            // Check if subscription already exists
                            $stmt = $db->prepare("
                                SELECT id FROM subscriptions
                                WHERE user_id = ? AND business_card_id = ?
                            ");
                            $stmt->execute([$payment['user_id'], $payment['business_card_id']]);
                            $existingSub = $stmt->fetch();

                            if (!$existingSub) {
                                // Create subscription record for new user (even if Stripe subscription doesn't exist yet)
                                // This allows the cancel button to appear
                                try {
                                    $stmt = $db->prepare("
                                        INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, stripe_customer_id, status, amount, billing_cycle, next_billing_date)
                                        VALUES (?, ?, NULL, ?, 'active', ?, 'monthly', DATE_ADD(NOW(), INTERVAL 1 MONTH))
                                    ");
                                    $stmt->execute([
                                        $payment['user_id'],
                                        $payment['business_card_id'],
                                        $payment['stripe_customer_id'],
                                        500 // Monthly amount for new users
                                    ]);
                                    error_log("Created subscription record for new user after payment: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}");
                                } catch (Exception $e) {
                                    error_log("Error creating subscription record after payment: " . $e->getMessage());
                                }
                            }
                        }

                        if (in_array($newPaymentStatus, ['CR', 'BANK_PAID'])) {
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
                    SELECT p.user_id, p.business_card_id, p.payment_type, p.payment_method, u.user_type, u.stripe_customer_id
                    FROM payments p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.stripe_payment_intent_id = ? AND p.payment_status = 'completed'
                ");
                $stmt->execute([$paymentIntentId]);
                $payment = $stmt->fetch();

                if ($payment) {
                    $newPaymentStatus = ($payment['payment_method'] === 'credit_card') ? 'CR' : 'BANK_PAID';

                    $stmt = $db->prepare("
                        UPDATE business_cards
                        SET payment_status = ?,
                            card_status = 'active',
                            updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$newPaymentStatus, $payment['business_card_id'], $payment['user_id']]);

                    enforceOpenPaymentStatusRule($db, $payment['business_card_id'], $newPaymentStatus);

                    // For new users, ensure subscription exists after payment
                    if ($payment['user_type'] === 'new' && $payment['stripe_customer_id']) {
                        // Check if subscription already exists
                        $stmt = $db->prepare("
                            SELECT id FROM subscriptions
                            WHERE user_id = ? AND business_card_id = ?
                        ");
                        $stmt->execute([$payment['user_id'], $payment['business_card_id']]);
                        $existingSub = $stmt->fetch();

                        if (!$existingSub) {
                            // Create subscription record for new user (even if Stripe subscription doesn't exist yet)
                            // This allows the cancel button to appear
                            try {
                                $stmt = $db->prepare("
                                    INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, stripe_customer_id, status, amount, billing_cycle, next_billing_date)
                                    VALUES (?, ?, NULL, ?, 'active', ?, 'monthly', DATE_ADD(NOW(), INTERVAL 1 MONTH))
                                ");
                                $stmt->execute([
                                    $payment['user_id'],
                                    $payment['business_card_id'],
                                    $payment['stripe_customer_id'],
                                    500 // Monthly amount for new users
                                ]);
                                error_log("Created subscription record for new user after payment_intent.succeeded: user_id={$payment['user_id']}, bc_id={$payment['business_card_id']}");
                            } catch (Exception $e) {
                                error_log("Error creating subscription record after payment_intent.succeeded: " . $e->getMessage());
                            }
                        }
                    }

                    if (in_array($newPaymentStatus, ['CR', 'BANK_PAID'])) {
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
