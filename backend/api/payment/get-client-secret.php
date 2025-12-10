<?php
/**
 * Get Client Secret API
 * Returns the client_secret for a payment intent
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

// Stripe SDK読み込み
require_once __DIR__ . '/../../vendor/autoload.php';
use Stripe\Stripe;
use Stripe\PaymentIntent;

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();
    $paymentId = $_GET['payment_id'] ?? '';

    if (empty($paymentId)) {
        sendErrorResponse('Payment ID is required', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Get payment info
    $stmt = $db->prepare("
        SELECT stripe_payment_intent_id, payment_status
        FROM payments
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$paymentId, $userId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        sendErrorResponse('Payment not found', 404);
    }

    if ($payment['payment_status'] === 'completed') {
        sendErrorResponse('Payment already completed', 400);
    }

    if (empty($payment['stripe_payment_intent_id'])) {
        sendErrorResponse('Payment intent not found', 404);
    }

    // Get client secret from Stripe
    if (empty(STRIPE_SECRET_KEY)) {
        sendErrorResponse('Stripe not configured', 500);
    }

    Stripe::setApiKey(STRIPE_SECRET_KEY);

    try {
        $paymentIntent = PaymentIntent::retrieve($payment['stripe_payment_intent_id']);
        sendSuccessResponse([
            'client_secret' => $paymentIntent->client_secret,
            'payment_intent_id' => $paymentIntent->id,
            'status' => $paymentIntent->status
        ]);
    } catch (\Exception $e) {
        error_log("Get client secret error: " . $e->getMessage());
        sendErrorResponse('Failed to retrieve payment intent', 500);
    }

} catch (Exception $e) {
    error_log("Get Client Secret Error: " . $e->getMessage());
    sendErrorResponse('Server error occurred', 500);
}

