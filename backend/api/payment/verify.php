<?php
/**
 * Payment Verification API
 * Verifies payment status after frontend confirmation
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/qr-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

// Stripe SDK読み込み
require_once __DIR__ . '/../../vendor/autoload.php';
use Stripe\Stripe;
use Stripe\PaymentIntent;

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET' && $method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();
    $paymentId = $_GET['payment_id'] ?? ($_POST['payment_id'] ?? '');
    $paymentIntentId = $_GET['payment_intent_id'] ?? ($_POST['payment_intent_id'] ?? '');

    if (empty($paymentId) && empty($paymentIntentId)) {
        sendErrorResponse('Payment ID or Payment Intent ID is required', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Get payment info
    if ($paymentId) {
        $stmt = $db->prepare("
            SELECT p.*, u.email
            FROM payments p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ? AND p.user_id = ?
        ");
        $stmt->execute([$paymentId, $userId]);
        $payment = $stmt->fetch();
    } else {
        $stmt = $db->prepare("
            SELECT p.*, u.email
            FROM payments p
            JOIN users u ON p.user_id = u.id
            WHERE p.stripe_payment_intent_id = ? AND p.user_id = ?
        ");
        $stmt->execute([$paymentIntentId, $userId]);
        $payment = $stmt->fetch();
    }

    if (!$payment) {
        sendErrorResponse('Payment not found', 404);
    }

    // Verify with Stripe if payment intent exists
    $stripeStatus = null;
    $stripePaymentIntent = null;
    
    if (!empty($payment['stripe_payment_intent_id'])) {
        if (empty(STRIPE_SECRET_KEY)) {
            sendErrorResponse('Stripe not configured', 500);
        }

        Stripe::setApiKey(STRIPE_SECRET_KEY);

        try {
            $stripePaymentIntent = PaymentIntent::retrieve($payment['stripe_payment_intent_id']);
            $stripeStatus = $stripePaymentIntent->status;

            // If Stripe says succeeded but DB says pending, update DB
            if ($stripeStatus === 'succeeded' && $payment['payment_status'] === 'pending') {
                $stmt = $db->prepare("
                    UPDATE payments 
                    SET payment_status = 'completed', paid_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$payment['id']]);
                $payment['payment_status'] = 'completed';
                
                // Generate QR code for business card after payment completion
                if (!empty($payment['business_card_id'])) {
                    $qrResult = generateBusinessCardQRCode($payment['business_card_id'], $db);
                    if (!$qrResult['success']) {
                        error_log("Failed to generate QR code after payment: " . ($qrResult['message'] ?? 'Unknown error'));
                    }
                }
            } elseif ($stripeStatus === 'requires_payment_method' || 
                      $stripeStatus === 'canceled' || 
                      $stripeStatus === 'requires_capture') {
                // Payment failed or requires action
                if ($payment['payment_status'] === 'pending') {
                    $stmt = $db->prepare("
                        UPDATE payments 
                        SET payment_status = 'failed'
                        WHERE id = ?
                    ");
                    $stmt->execute([$payment['id']]);
                    $payment['payment_status'] = 'failed';
                }
            }
        } catch (\Exception $e) {
            error_log("Payment verification error: " . $e->getMessage());
            // Continue with database status if Stripe check fails
        }
    }

    sendSuccessResponse([
        'payment_id' => $payment['id'],
        'payment_status' => $payment['payment_status'],
        'stripe_status' => $stripeStatus,
        'stripe_payment_intent_id' => $payment['stripe_payment_intent_id'],
        'paid_at' => $payment['paid_at'],
        'total_amount' => $payment['total_amount']
    ]);

} catch (Exception $e) {
    error_log("Payment Verify Error: " . $e->getMessage());
    sendErrorResponse('Server error occurred', 500);
}




