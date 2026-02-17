<?php
/**
 * Manual Bank Transfer Information Page
 * Used when Stripe is not configured
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/includes/functions.php';

startSessionIfNotStarted();

$paymentId = $_GET['payment_id'] ?? '';
$paymentInfo = null;

if ($paymentId) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $stmt = $db->prepare("
            SELECT p.*, u.email
            FROM payments p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$paymentId]);
        $paymentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Bank transfer manual page error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
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
        
        .transfer-body {
            padding: 2rem;
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
        
        .bank-info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        
        .bank-info-table tr {
            border-bottom: 1px solid #e9ecef;
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
        
        .notice-box {
            background: #e7f5ff;
            border-left: 4px solid #339af0;
            padding: 1rem;
            margin-top: 1.5rem;
        }
        
        .notice-box h4 {
            color: #1971c2;
            margin: 0 0 0.5rem;
        }
        
        .notice-box ul {
            margin: 0;
            padding-left: 1.25rem;
        }
        
        .notice-box li {
            margin-bottom: 0.5rem;
            color: #333;
        }
        
        .payment-id-box {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            margin: 1.5rem 0;
        }
        
        .payment-id-label {
            font-size: 0.8rem;
            color: #868e96;
            margin-bottom: 0.25rem;
        }
        
        .payment-id-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #333;
            font-family: monospace;
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
            <div class="transfer-card">
                <div class="transfer-header">
                    <h1>お振込み情報</h1>
                    <p>以下の口座にお振込みをお願いいたします</p>
                </div>
                
                <div class="transfer-body">
                    <?php if ($paymentInfo): ?>
                    <div class="amount-display">
                        <div class="amount-label">お振込み金額</div>
                        <div class="amount-value">¥<?php echo number_format($paymentInfo['total_amount']); ?></div>
                    </div>
                    
                    <table class="bank-info-table">
                        <tr>
                            <th>銀行名</th>
                            <td>三菱UFJ銀行</td>
                        </tr>
                        <tr>
                            <th>支店名</th>
                            <td>渋谷支店（002）</td>
                        </tr>
                        <tr>
                            <th>口座種別</th>
                            <td>普通</td>
                        </tr>
                        <tr>
                            <th>口座番号</th>
                            <td>1234567</td>
                        </tr>
                        <tr>
                            <th>口座名義</th>
                            <td>カ）アールチュウカイ</td>
                        </tr>
                    </table>
                    
                    <div class="payment-id-box">
                        <div class="payment-id-label">お振込み時に振込人名義の後に以下の番号を追加してください</div>
                        <div class="payment-id-value"><?php echo str_pad($paymentInfo['id'], 6, '0', STR_PAD_LEFT); ?></div>
                    </div>
                    
                    <p style="text-align: center; color: #666; font-size: 0.9rem;">
                        例）ヤマダタロウ <?php echo str_pad($paymentInfo['id'], 6, '0', STR_PAD_LEFT); ?>
                    </p>
                    
                    <?php endif; ?>
                    
                    <div class="notice-box">
                        <h4>ご注意事項</h4>
                        <ul>
                            <li>お振込み手数料はお客様負担となります</li>
                            <li>振込確認後、1〜2営業日以内にサービスが有効になります</li>
                            <li>振込人名義に上記番号を追加いただくと、照合がスムーズです</li>
                            <li>ご不明な点がございましたら、お問い合わせください</li>
                        </ul>
                    </div>
                    
                    <div style="text-align: center; margin-top: 1.5rem;">
                        <span class="status-pending">⏳ お振込み待ち</span>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="edit.php" class="btn btn-primary">マイページへ</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

