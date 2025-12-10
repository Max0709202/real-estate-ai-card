<?php
/**
 * Payment Success Page
 * Displays confirmation after successful payment
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';

// Stripe SDK読み込み
require_once __DIR__ . '/../backend/vendor/autoload.php';
use Stripe\Stripe;
use Stripe\PaymentIntent;

startSessionIfNotStarted();

$sessionId = $_GET['session_id'] ?? '';
$paymentId = $_GET['payment_id'] ?? '';

// Verify payment status
$paymentInfo = null;
if ($paymentId) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        startSessionIfNotStarted();
        $userId = $_SESSION['user_id'] ?? 0;

        $stmt = $db->prepare("
            SELECT p.*, u.email, bc.url_slug
            FROM payments p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN business_cards bc ON p.business_card_id = bc.id
            WHERE p.id = ? AND p.user_id = ?
        ");
        $stmt->execute([$paymentId, $userId]);
        $paymentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        // If payment not found or not completed, redirect
        if (!$paymentInfo) {
            header('Location: register.php');
            exit;
        }
         // If payment is still pending, verify with Stripe
         if ($paymentInfo['payment_status'] === 'pending' && !empty($paymentInfo['stripe_payment_intent_id'])) {
             if (!empty(STRIPE_SECRET_KEY)) {
                Stripe::setApiKey(STRIPE_SECRET_KEY);
                try {
                    $paymentIntent = PaymentIntent::retrieve($paymentInfo['stripe_payment_intent_id']);
                    if ($paymentIntent->status === 'succeeded') {
                        // Update payment status
                        $stmt = $db->prepare("
                            UPDATE payments
                            SET payment_status = 'completed', paid_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$paymentId]);
                        $paymentInfo['payment_status'] = 'completed';
                    } elseif ($paymentIntent->status === 'requires_payment_method' ||
                              $paymentIntent->status === 'canceled') {
                        // Payment failed
                        header('Location: payment.php?payment_id=' . $paymentId);
                        exit;
                    }
                } catch (Exception $e) {
                    error_log("Payment verification error: " . $e->getMessage());
                }
            }
        }

        // If payment is not completed, redirect to payment page
        if ($paymentInfo['payment_status'] !== 'completed') {
            header('Location: payment.php?payment_id=' . $paymentId);
            exit;
        }
    } catch (Exception $e) {
        error_log("Payment success page error: " . $e->getMessage());
        header('Location: register.php');
        exit;
    }
} else {
    header('Location: register.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お支払い完了 - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/register.css">
    <style>
        .success-container {
            max-width: 600px;
            margin: 4rem auto;
            padding: 2rem;
            text-align: center;
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #51cf66 0%, #37b24d 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
        }
        
        .success-icon svg {
            width: 50px;
            height: 50px;
            color: #fff;
        }
        
        .success-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1rem;
        }
        
        .success-message {
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .payment-details {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: left;
        }
        
        .payment-detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .payment-detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #666;
        }
        
        .detail-value {
            font-weight: 600;
            color: #333;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-block;
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #333;
            border: 1px solid #e9ecef;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <a href="index.php" class="logo-link">
                <img src="assets/images/logo.png" alt="不動産AI名刺">
            </a>
        </div>

        <div class="success-container">
            <div class="success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            
            <h1 class="success-title">お支払いが完了しました</h1>
            
            <p class="success-message">
                不動産AI名刺へのご登録ありがとうございます。<br>
                お支払いが正常に完了しました。サービスをご利用いただけます。
            </p>
            
            <?php if ($paymentInfo): ?>
            <div class="payment-details">
                <div class="payment-detail-row">
                    <span class="detail-label">お支払い金額</span>
                    <span class="detail-value">¥<?php echo number_format($paymentInfo['total_amount']); ?></span>
                </div>
                <div class="payment-detail-row">
                    <span class="detail-label">お支払い方法</span>
                    <span class="detail-value"><?php echo $paymentInfo['payment_method'] === 'credit_card' ? 'クレジットカード' : '銀行振込'; ?></span>
                </div>
                <div class="payment-detail-row">
                    <span class="detail-label">ステータス</span>
                    <span class="detail-value" style="color: #37b24d;">完了</span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <a href="edit.php" class="btn btn-primary">マイページへ</a>
                <?php if ($paymentInfo && $paymentInfo['url_slug']): ?>
                <a href="card.php?slug=<?php echo htmlspecialchars($paymentInfo['url_slug']); ?>" class="btn btn-secondary" target="_blank">名刺を見る</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

