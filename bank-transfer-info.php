<?php
/**
 * Bank Transfer Information Page
 * Displays bank transfer details after selecting bank transfer payment
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/includes/functions.php';
require_once __DIR__ . '/backend/vendor/autoload.php';

use Stripe\Stripe;
use Stripe\PaymentIntent;

startSessionIfNotStarted();

$paymentId = $_GET['payment_id'] ?? '';
$paymentIntentId = $_GET['pi'] ?? '';
$userType = $_GET['type'] ?? ($_SESSION['user_type'] ?? 'new');
$urlTypeParam = ($userType === 'existing') ? '&type=existing' : '';

$paymentInfo = null;
$bankTransferInfo = null;
$error = null;

if ($paymentId && $paymentIntentId) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get payment info
        $stmt = $db->prepare("
            SELECT p.*, u.email
            FROM payments p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$paymentId]);
        $paymentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get bank transfer details from Stripe
        if (!empty(STRIPE_SECRET_KEY)) {
            Stripe::setApiKey(STRIPE_SECRET_KEY);
            
            try {
                $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
                
                // Log PaymentIntent status for debugging
                error_log("PaymentIntent Status: " . $paymentIntent->status);
                error_log("PaymentIntent Data: " . json_encode($paymentIntent, JSON_PRETTY_PRINT));
                
                // Check if payment is already completed
                if ($paymentIntent->status === 'succeeded') {
                    header('Location: payment-success.php?payment_id=' . $paymentId);
                    exit;
                }
                
                // Try multiple ways to get bank transfer information
                $instructions = null;
                
                // Method 1: Check next_action (for requires_action status)
                if (isset($paymentIntent->next_action->display_bank_transfer_instructions)) {
                    $instructions = $paymentIntent->next_action->display_bank_transfer_instructions;
                }
                // Method 2: Check next_action type (alternative structure)
                elseif (isset($paymentIntent->next_action) && 
                        $paymentIntent->next_action->type === 'display_bank_transfer_instructions') {
                    $instructions = $paymentIntent->next_action;
                }
                // Method 3: Try to get from payment method options
                elseif (isset($paymentIntent->payment_method_options->customer_balance)) {
                    // Try to retrieve funding instructions separately
                    try {
                        $fundingInstructions = PaymentIntent::retrieve(
                            $paymentIntentId,
                            ['expand' => ['payment_method_options.customer_balance']]
                        );
                        if (isset($fundingInstructions->next_action->display_bank_transfer_instructions)) {
                            $instructions = $fundingInstructions->next_action->display_bank_transfer_instructions;
                        }
                    } catch (Exception $e) {
                        error_log("Failed to retrieve funding instructions: " . $e->getMessage());
                    }
                }
                
                // Extract bank transfer details from instructions
                if ($instructions) {
                    error_log("Instructions found: " . json_encode($instructions, JSON_PRETTY_PRINT));
                    
                    // Get Japanese bank transfer details from financial_addresses
                    if (isset($instructions->financial_addresses) && count($instructions->financial_addresses) > 0) {
                        foreach ($instructions->financial_addresses as $address) {
                            if ($address->type === 'zengin' && isset($address->zengin)) {
                                $amountRemaining = $instructions->amount_remaining ?? ($paymentInfo['total_amount'] * 100);
                                // Convert from cents to yen if needed
                                if ($amountRemaining >= 1000) {
                                    $amountRemaining = $amountRemaining / 100;
                                }
                                
                                $bankTransferInfo = [
                                    'bank_name' => $address->zengin->bank_name ?? '',
                                    'bank_code' => $address->zengin->bank_code ?? '',
                                    'branch_name' => $address->zengin->branch_name ?? '',
                                    'branch_code' => $address->zengin->branch_code ?? '',
                                    'account_type' => $address->zengin->account_type ?? '普通',
                                    'account_number' => $address->zengin->account_number ?? '',
                                    'account_holder_name' => $address->zengin->account_holder_name ?? '',
                                    'reference' => $instructions->reference ?? (string)$paymentId,
                                    'amount_remaining' => $amountRemaining
                                ];
                                
                                error_log("Bank transfer info extracted: " . json_encode($bankTransferInfo, JSON_PRETTY_PRINT));
                                break;
                            }
                        }
                    }
                } else {
                    error_log("No bank transfer instructions found in PaymentIntent");
                    error_log("PaymentIntent status: " . $paymentIntent->status);
                    error_log("Full PaymentIntent: " . json_encode($paymentIntent, JSON_PRETTY_PRINT));

                    // Provide helpful error message
                    $error = "振込情報の取得に失敗しました。Stripe Dashboardで銀行振込機能が有効化されているか確認してください。";
                }

            } catch (\Stripe\Exception\ApiErrorException $e) {
                error_log("Stripe API Error: " . $e->getMessage());
                $error = "Stripe APIエラーが発生しました: " . $e->getMessage();
            } catch (Exception $e) {
                error_log("Error retrieving PaymentIntent: " . $e->getMessage());
                $error = "決済情報の取得中にエラーが発生しました。";
            }
        }
    } catch (Exception $e) {
        error_log("Bank transfer info page error: " . $e->getMessage());
        $error = "振込情報の取得中にエラーが発生しました。";
    }
} else {
    $error = "無効なリクエストです。";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お振込み情報 - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/register.css">
    <style>
        .transfer-container {
            max-width: 700px;
            margin: 2rem auto;
            padding: 2rem;
        }
        
        .transfer-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        
        .transfer-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 2rem;
            text-align: center;
        }
        
        .transfer-header h1 {
            margin: 0 0 0.5rem;
            font-size: 1.5rem;
        }
        
        .transfer-header p {
            margin: 0;
            opacity: 0.9;
        }
        
        .transfer-body {
            padding: 2rem;
        }
        
        .info-section {
            margin-bottom: 2rem;
        }
        
        .info-section h3 {
            font-size: 1rem;
            color: #666;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .bank-info-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .bank-info-table tr {
            border-bottom: 1px solid #e9ecef;
        }
        
        .bank-info-table tr:last-child {
            border-bottom: none;
        }
        
        .bank-info-table th {
            text-align: left;
            padding: 1rem;
            background: #f8f9fa;
            font-weight: 500;
            color: #666;
            width: 35%;
        }
        
        .bank-info-table td {
            padding: 1rem;
            font-weight: 600;
            color: #333;
        }
        
        .amount-display {
            background: linear-gradient(135deg, #fff9db 0%, #fff3bf 100%);
            border: 2px solid #fcc419;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .amount-label {
            font-size: 0.9rem;
            color: #e67700;
            margin-bottom: 0.5rem;
        }
        
        .amount-value {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
        }
        
        .reference-box {
            background: #e7f5ff;
            border: 2px solid #339af0;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .reference-label {
            font-size: 0.8rem;
            color: #1971c2;
            margin-bottom: 0.25rem;
        }
        
        .reference-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1864ab;
            font-family: monospace;
        }
        
        .notice-box {
            background: #fff9db;
            border-left: 4px solid #fcc419;
            padding: 1rem;
            margin-top: 1.5rem;
        }
        
        .notice-box h4 {
            color: #e67700;
            margin: 0 0 0.5rem;
            font-size: 0.95rem;
        }
        
        .notice-box ul {
            margin: 0;
            padding-left: 1.25rem;
            color: #333;
        }
        
        .notice-box li {
            margin-bottom: 0.5rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
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
        
        .status-pending {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #fff3bf;
            color: #e67700;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-top: 1rem;
        }
        
        .status-pending::before {
            content: '';
            width: 10px;
            height: 10px;
            background: #fcc419;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .error-message {
            background: #ffe3e3;
            border: 1px solid #fa5252;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            color: #c92a2a;
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

        <div class="transfer-container">
            <?php if ($error): ?>
            <div class="error-message">
                <p><?php echo htmlspecialchars($error); ?></p>
                <a href="register.php" class="btn btn-primary" style="margin-top: 1rem;">登録画面に戻る</a>
            </div>
            <?php elseif ($bankTransferInfo): ?>
            <div class="transfer-card">
                <div class="transfer-header">
                    <h1>お振込み情報</h1>
                    <p>以下の口座にお振込みをお願いいたします</p>
                </div>
                
                <div class="transfer-body">
                    <div class="amount-display">
                        <div class="amount-label">お振込み金額</div>
                        <div class="amount-value">¥<?php echo number_format($bankTransferInfo['amount_remaining']); ?></div>
                    </div>
                    
                    <div class="info-section">
                        <h3>振込先銀行口座</h3>
                        <table class="bank-info-table">
                            <tr>
                                <th>銀行名</th>
                                <td><?php echo htmlspecialchars($bankTransferInfo['bank_name']); ?></td>
                            </tr>
                            <tr>
                                <th>支店名</th>
                                <td><?php echo htmlspecialchars($bankTransferInfo['branch_name']); ?>（<?php echo htmlspecialchars($bankTransferInfo['branch_code']); ?>）</td>
                            </tr>
                            <tr>
                                <th>口座種別</th>
                                <td><?php echo htmlspecialchars($bankTransferInfo['account_type']); ?></td>
                            </tr>
                            <tr>
                                <th>口座番号</th>
                                <td><?php echo htmlspecialchars($bankTransferInfo['account_number']); ?></td>
                            </tr>
                            <tr>
                                <th>口座名義</th>
                                <td><?php echo htmlspecialchars($bankTransferInfo['account_holder_name']); ?></td>
                            </tr>
                        </table>
                        
                        <?php if (!empty($bankTransferInfo['reference'])): ?>
                        <div class="reference-box">
                            <div class="reference-label">※お振込み時に以下の参照番号を振込人名義に追加してください</div>
                            <div class="reference-value"><?php echo htmlspecialchars($bankTransferInfo['reference']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notice-box">
                        <h4>ご注意事項</h4>
                        <ul>
                            <li>お振込み手数料はお客様負担となります</li>
                            <li>振込確認後、自動的にサービスが有効になります</li>
                            <li>振込確認には1〜2営業日かかる場合があります</li>
                            <li>参照番号を振込人名義に追加いただくと、照合がスムーズです</li>
                        </ul>
                    </div>
                    
                    <div style="text-align: center;">
                        <span class="status-pending">お振込み待ち</span>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="edit.php<?php echo ($userType === 'existing') ? '?type=existing' : ''; ?>" class="btn btn-primary">マイページへ</a>
                    </div>
                </div>
            </div>
            <?php elseif ($paymentInfo && !$error): ?>
            <div class="transfer-card">
                <div class="transfer-header">
                    <h1>振込情報を準備中...</h1>
                    <p>しばらくお待ちください</p>
                </div>
                <div class="transfer-body" style="text-align: center;">
                    <p>振込先情報を取得しています。</p>
                    <p>この画面が続く場合は、しばらく待ってからページを更新してください。</p>
                    <div class="notice-box" style="margin-top: 2rem; text-align: left;">
                        <h4>トラブルシューティング</h4>
                        <ul>
                            <li>Stripe Dashboardで「Japanese bank transfers」が有効化されているか確認してください</li>
                            <li>「Customer balance」が有効化されているか確認してください</li>
                            <li>テストモードを使用している場合、テスト用の設定を確認してください</li>
                            <li>エラーログを確認してください: <code>error_log</code>ファイル</li>
                        </ul>
                    </div>
                    <div class="action-buttons">
                        <a href="<?php echo $_SERVER['REQUEST_URI']; ?>" class="btn btn-primary">更新する</a>
                        <a href="edit.php<?php echo ($userType === 'existing') ? '?type=existing' : ''; ?>" class="btn btn-primary">マイページへ戻る</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($paymentInfo && $paymentInfo['payment_status'] === 'pending'): ?>
    <script>
        // Auto-refresh to check payment status
        setTimeout(function() {
            location.reload();
        }, 30000); // Refresh every 30 seconds
    </script>
    <?php endif; ?>
</body>
</html>

