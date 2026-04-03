<?php
/**
 * Stripe Payment Page
 * Displays Stripe payment form using Stripe Elements
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/includes/functions.php';

startSessionIfNotStarted();

$paymentId = $_GET['payment_id'] ?? '';
$clientSecret = $_GET['client_secret'] ?? '';
$userType = $_GET['type'] ?? ($_SESSION['user_type'] ?? 'new');
$urlTypeParam = ($userType === 'existing') ? '&type=existing' : '';

$from = isset($_GET['from']) ? preg_replace('/[^a-z_]/i', '', (string) $_GET['from']) : '';
if ($from === 'mypage' || $from === 'edit') {
    $backUrl = 'edit.php' . ($userType === 'existing' ? '?type=existing' : '');
} else {
    $backUrl = 'register.php?step=6' . ($userType === 'existing' ? '&type=existing' : '');
}

// If no payment_id, redirect back to register
if (empty($paymentId)) {
    header('Location: register.php');
    exit;
}

// Get payment info
$paymentInfo = null;
if ($paymentId) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $stmt = $db->prepare("
            SELECT p.*, u.email, bc.url_slug
            FROM payments p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN business_cards bc ON p.business_card_id = bc.id
            WHERE p.id = ? AND p.user_id = ?
        ");
        $stmt->execute([$paymentId, $_SESSION['user_id'] ?? 0]);
        $paymentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$paymentInfo) {
            header('Location: register.php');
            exit;
        }
        
        // If payment already completed, redirect to success page
        if ($paymentInfo['payment_status'] === 'completed') {
            header('Location: payment-success.php?payment_id=' . $paymentId);
            exit;
        }
        
        // Get client_secret from payment if not provided
        if (empty($clientSecret) && !empty($paymentInfo['stripe_payment_intent_id'])) {
            // We'll fetch it via API call from frontend
        }
    } catch (Exception $e) {
        error_log("Payment page error: " . $e->getMessage());
        header('Location: register.php');
        exit;
    }
}

$paymentTypeForSummary = $paymentInfo['payment_type'] ?? 'new_user';
if (!in_array($paymentTypeForSummary, ['new_user', 'existing_user', 'renewal'], true)) {
    $paymentTypeForSummary = 'new_user';
}
$monthlyExTaxYen = (int) (defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500);
$monthlyTaxYen = (int) round($monthlyExTaxYen * (defined('TAX_RATE') ? (float) TAX_RATE : 0.1));
$monthlyIncTaxYen = pricing_amount_inc_tax_yen($monthlyExTaxYen);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>お支払い - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/register.css">
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        .payment-container {
            max-width: 600px;
            margin: 2rem auto;
            padding: 2rem;
        }
        
        .payment-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            padding: 2rem;
        }
        
        .payment-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .payment-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .payment-header p {
            color: #666;
        }
        
        .payment-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.75rem;
            padding: 0.35rem 0;
            flex-wrap: wrap;
        }

        @media (max-width: 480px) {
            .summary-row.summary-row-amount {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.35rem;
            }
            .summary-row.summary-row-amount .summary-value {
                align-self: flex-end;
            }
        }

        .summary-row-amount {
            font-weight: 600;
            font-size: 1.05rem;
            padding-top: 0.25rem;
        }
        
        .summary-label {
            color: #666;
        }
        
        .summary-value {
            color: #333;
            flex-shrink: 0;
        }

        .payment-summary-block {
            margin-top: 1.15rem;
            padding-top: 1.15rem;
            border-top: 1px solid #dee2e6;
        }

        .payment-summary-block:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }

        .summary-breakdown {
            margin: 0.5rem 0 0;
            font-size: 0.85rem;
            color: #666;
            line-height: 1.6;
        }

        .summary-note-monthly {
            margin: 1rem 0 0;
            font-size: 0.85rem;
            color: #444;
            line-height: 1.65;
        }
        
        #payment-form {
            margin-top: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        #card-element {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
        }
        
        #card-element:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        #card-errors {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            min-height: 1.5rem;
        }

        
        .submit-button {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 1rem;
        }
        
        .submit-button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .submit-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 1rem;
        }
        
        .loading.active {
            display: block;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: none;
        }
        
        .error-message.active {
            display: block;
        }

        .payment-page-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .payment-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.95rem;
            font-weight: 600;
            color: #444;
            text-decoration: none;
            padding: 0.5rem 0.85rem;
            border-radius: 8px;
            border: 1px solid #ddd;
            background: #fff;
            flex-shrink: 0;
            line-height: 1.2;
            margin-top: 20px;
        }

        .payment-back-btn:hover {
            background: #f5f5f5;
            color: #222;
        }

        @media (max-width: 420px) {
            #card-element {
                padding-inline: 6px !important;
            }
            .payment-card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header payment-page-header">
            
            <a href="index.php" class="logo-link">
                <img src="assets/images/logo.png" alt="不動産AI名刺">
            </a>
        </div>

        <div class="payment-container">
            <div class="payment-card">
                <div class="payment-header">
                    <h1>お支払い</h1>
                    <p>クレジットカード情報を入力してください</p>
                </div>

                <?php if ($paymentInfo): ?>
                <div class="payment-summary">
                    <?php if ($paymentTypeForSummary === 'new_user'): ?>
                    <div class="payment-summary-block">
                        <div class="summary-row summary-row-amount">
                            <span class="summary-label">初期費用　お支払金額（税込）</span>
                            <span class="summary-value">￥<?php echo number_format($paymentInfo['total_amount']); ?></span>
                        </div>
                        <p class="summary-breakdown">
                            内訳：税抜￥<?php echo number_format($paymentInfo['amount']); ?>＋消費税（10％）￥<?php echo number_format($paymentInfo['tax_amount']); ?>
                        </p>
                    </div>
                    <div class="payment-summary-block">
                        <div class="summary-row summary-row-amount">
                            <span class="summary-label">月額費用　お支払金額（税込）</span>
                            <span class="summary-value">￥<?php echo number_format($monthlyIncTaxYen); ?></span>
                        </div>
                        <p class="summary-breakdown">
                            内訳：税抜￥<?php echo number_format($monthlyExTaxYen); ?>＋消費税（10％）￥<?php echo number_format($monthlyTaxYen); ?>
                        </p>
                        <p class="summary-note-monthly">
                            月額費用は、ご登録いただいた決済手段より、ご利用期間中は毎月自動的に利用料金が決済されます。契約が有効である限り、手続きは自動で更新されます。自動更新の停止やキャンセルはマイページからお手続きできます。
                        </p>
                    </div>
                    <?php elseif ($paymentTypeForSummary === 'existing_user'): ?>
                    <div class="payment-summary-block">
                        <div class="summary-row summary-row-amount">
                            <span class="summary-label">初期費用　お支払金額（税込）</span>
                            <span class="summary-value">￥<?php echo number_format($paymentInfo['total_amount']); ?></span>
                        </div>
                        <p class="summary-breakdown">
                            内訳：税抜￥<?php echo number_format($paymentInfo['amount']); ?>＋消費税（10％）￥<?php echo number_format($paymentInfo['tax_amount']); ?>
                        </p>
                    </div>
                    <div class="payment-summary-block">
                        <div class="summary-row summary-row-amount">
                            <span class="summary-label">月額費用</span>
                            <span class="summary-value">無料</span>
                        </div>
                        <p class="summary-note-monthly">
                            キャンペーン期間中は月額費用はかかりません。自動更新の停止やキャンセルはマイページからお手続きできます。
                        </p>
                    </div>
                    <?php elseif ($paymentTypeForSummary === 'renewal'): ?>
                    <div class="payment-summary-block">
                        <div class="summary-row summary-row-amount">
                            <span class="summary-label">今回のお支払金額（税込）</span>
                            <span class="summary-value">￥<?php echo number_format($paymentInfo['total_amount']); ?></span>
                        </div>
                        <p class="summary-breakdown">
                            内訳：税抜￥<?php echo number_format($paymentInfo['amount']); ?>＋消費税（10％）￥<?php echo number_format($paymentInfo['tax_amount']); ?>
                        </p>
                    </div>
                    <div class="payment-summary-block">
                        <div class="summary-row summary-row-amount">
                            <span class="summary-label">月額費用　お支払金額（税込）</span>
                            <span class="summary-value">￥<?php echo number_format($monthlyIncTaxYen); ?></span>
                        </div>
                        <p class="summary-breakdown">
                            内訳：税抜￥<?php echo number_format($monthlyExTaxYen); ?>＋消費税（10％）￥<?php echo number_format($monthlyTaxYen); ?>
                        </p>
                        <p class="summary-note-monthly">
                            月額費用は、ご登録いただいた決済手段より、ご利用期間中は毎月自動的に利用料金が決済されます。契約が有効である限り、手続きは自動で更新されます。自動更新の停止やキャンセルはマイページからお手続きできます。
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="error-message" id="error-message"></div>

                <form id="payment-form">
                    <div class="form-group">
                        <label for="card-element">カード情報</label>
                        <div id="card-element">
                            <!-- Stripe Elements will create form elements here -->
                        </div>
                        <div id="card-errors" role="alert"></div>
                    </div>


                    <button type="submit" class="submit-button" id="submit-button">
                        支払いを完了する
                    </button>

                    <div class="loading" id="loading">
                        <div class="spinner"></div>
                        <p style="margin-top: 1rem;">処理中...</p>
                    </div>
                </form>
                <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="payment-back-btn">← 戻る</a>
            </div>
        </div>
    </div>

    <script>
        // Initialize Stripe
        const stripePublishableKey = '<?php echo STRIPE_PUBLISHABLE_KEY; ?>';
        
        if (!stripePublishableKey) {
            console.error('Stripe publishable key is missing!');
            document.getElementById('card-element').innerHTML = '<p style="color: #dc3545;">Stripeの設定が正しくありません。管理者にお問い合わせください。</p>';
        }

        const stripe = Stripe(stripePublishableKey);
        const paymentId = '<?php echo $paymentId; ?>';
        let clientSecret = '<?php echo htmlspecialchars($clientSecret); ?>';

        let cardElement = null;

        // Wait for DOM to be ready
        document.addEventListener('DOMContentLoaded', function() {
            // If client_secret is not provided, fetch it from the payment
            if (!clientSecret) {
                fetch(`backend/api/payment/get-client-secret.php?payment_id=${paymentId}`, {
                    credentials: 'include'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.client_secret) {
                        clientSecret = data.client_secret;
                        initializeStripe();
                    } else {
                        showError('決済情報の取得に失敗しました。');
                        console.error('Failed to get client secret:', data);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('エラーが発生しました。');
                });
            } else {
                initializeStripe();
            }
        });

        function initializeStripe() {
            try {
                // Check if Stripe is loaded
                if (typeof Stripe === 'undefined') {
                    console.error('Stripe.js is not loaded!');
                    document.getElementById('card-element').innerHTML = '<p style="color: #dc3545;">Stripe.jsの読み込みに失敗しました。ページを再読み込みしてください。</p>';
                    return;
                }

                // Check if publishable key is valid
                if (!stripePublishableKey || stripePublishableKey.length < 10) {
                    console.error('Invalid Stripe publishable key!');
                    document.getElementById('card-element').innerHTML = '<p style="color: #dc3545;">Stripeの設定が正しくありません。</p>';
                    return;
                }

                // Check if card element container exists
                const cardElementContainer = document.getElementById('card-element');
                if (!cardElementContainer) {
                    console.error('Card element container not found!');
                    return;
                }

                // Create Stripe Elements with Japanese locale
                const elements = stripe.elements({
                    locale: 'ja' // Set locale to Japanese for better postal code handling
                });

                // Create card element with postal code included
                // Stripe Elements will automatically detect Japanese cards and show 7-digit postal code
                cardElement = elements.create('card', {
                    style: {
                        base: {
                            fontSize: '16px',
                            color: '#333',
                            '::placeholder': {
                                color: '#aab7c4',
                            },
                        },
                        invalid: {
                            color: '#dc3545',
                        },
                    },
                    hidePostalCode: false // Include postal code in card element - Stripe will auto-detect Japanese format
                });

                // Mount card element
                cardElement.mount('#card-element');
                
                console.log('Stripe card element mounted successfully');

                // Handle real-time validation errors from the card Element
                cardElement.on('change', function(event) {
                    const displayError = document.getElementById('card-errors');
                    if (displayError) {
                        if (event.error) {
                            displayError.textContent = event.error.message;
                        } else {
                            displayError.textContent = '';
                        }
                    }
                });

                // Handle card element ready event
                cardElement.on('ready', function() {
                    console.log('Card element is ready');
                });

                // Setup form submission handler
                setupFormSubmission();

            } catch (error) {
                console.error('Error initializing Stripe:', error);
                const cardElementContainer = document.getElementById('card-element');
                if (cardElementContainer) {
                    cardElementContainer.innerHTML = '<p style="color: #dc3545;">カード情報の読み込みに失敗しました。ページを再読み込みしてください。</p>';
                }
                showError('Stripeの初期化に失敗しました: ' + error.message);
            }
        }

        function setupFormSubmission() {
            // Handle form submission
            const form = document.getElementById('payment-form');
            if (!form) {
                console.error('Payment form not found!');
                return;
            }

            form.addEventListener('submit', async function(event) {
                event.preventDefault();

                const submitButton = document.getElementById('submit-button');
                const loading = document.getElementById('loading');
                const errorMessage = document.getElementById('error-message');

                // Disable submit button and show loading
                submitButton.disabled = true;
                loading.classList.add('active');
                hideError();

                try {
                    // Payment method data - postal code is included in cardElement
                    // Stripe will automatically handle Japanese postal codes (7 digits) when locale is 'ja'
                    const {error, paymentIntent} = await stripe.confirmCardPayment(clientSecret, {
                        payment_method: {
                            card: cardElement,
                        }
                    });

                    if (error) {
                        // Show error to customer
                        showError(error.message);
                        submitButton.disabled = false;
                        loading.classList.remove('active');
                    } else if (paymentIntent && paymentIntent.status === 'succeeded') {
                        // Payment succeeded - verify with backend before redirecting
                        verifyPaymentStatus(paymentIntent.id);
                    } else if (paymentIntent && paymentIntent.status === 'requires_action') {
                        // Payment requires additional action (3D Secure, etc.)
                        try {
                            const {error: actionError} = await stripe.handleCardAction(clientSecret);
                            if (actionError) {
                                showError(actionError.message);
                                submitButton.disabled = false;
                                loading.classList.remove('active');
                            } else {
                                // Retry confirmation after action
                                const {error: retryError, paymentIntent: retryPaymentIntent} = await stripe.confirmCardPayment(clientSecret);
                                if (retryError) {
                                    showError(retryError.message);
                                    submitButton.disabled = false;
                                    loading.classList.remove('active');
                                } else if (retryPaymentIntent && retryPaymentIntent.status === 'succeeded') {
                                    verifyPaymentStatus(retryPaymentIntent.id);
                                } else {
                                    showError('追加の確認が必要です。');
                                    submitButton.disabled = false;
                                    loading.classList.remove('active');
                                }
                            }
                        } catch (actionErr) {
                            console.error('Action handling error:', actionErr);
                            showError('追加の確認処理中にエラーが発生しました。');
                            submitButton.disabled = false;
                            loading.classList.remove('active');
                        }
                    } else {
                        // Payment requires additional action
                        showError('追加の確認が必要です。');
                        submitButton.disabled = false;
                        loading.classList.remove('active');
                    }
                } catch (err) {
                    console.error('Payment error:', err);
                    showError('決済処理中にエラーが発生しました。');
                    submitButton.disabled = false;
                    loading.classList.remove('active');
                }
            });
        }

        function showError(message) {
            const errorElement = document.getElementById('error-message');
            errorElement.textContent = message;
            errorElement.classList.add('active');
        }

        function hideError() {
            const errorElement = document.getElementById('error-message');
            errorElement.classList.remove('active');
        }

        async function verifyPaymentStatus(paymentIntentId) {
            // Verify payment status with backend
            try {
                const response = await fetch(`backend/api/payment/verify.php?payment_id=${paymentId}&payment_intent_id=${paymentIntentId}`, {
                    credentials: 'include'
                });
                const data = await response.json();

                if (data.success && data.data.payment_status === 'completed') {
                    // Payment verified, redirect to success page
                    window.location.href = `payment-success.php?payment_id=${paymentId}`;
                } else {
                    // Payment not yet confirmed, wait a moment and retry
                    setTimeout(() => {
                        verifyPaymentStatus(paymentIntentId);
                    }, 2000);
                }
            } catch (error) {
                console.error('Verification error:', error);
                // Still redirect to success page - webhook will update status
                window.location.href = `payment-success.php?payment_id=${paymentId}`;
            }
        }
    </script>
</body>
</html>

