<?php
/**
 * Admin Email Verification Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    $error = '認証トークンが必要です';
} else {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Verify token (check expiration)
        $stmt = $db->prepare("
            SELECT id, email, verification_token_expires_at, email_verified, role 
            FROM admins 
            WHERE verification_token = ? AND email_verified = 0
        ");
        $stmt->execute([$token]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            $error = '無効な認証トークンです。既に認証済みか、トークンが無効です。';
        } else {
            // Check token expiration
            $now = date('Y-m-d H:i:s');
            if ($admin['verification_token_expires_at'] && $admin['verification_token_expires_at'] < $now) {
                // Token expired
                $stmt = $db->prepare("UPDATE admins SET verification_token = NULL, verification_token_expires_at = NULL WHERE id = ?");
                $stmt->execute([$admin['id']]);
                $error = '認証トークンの有効期限が切れています。管理者に連絡して再発行を依頼してください。';
            } else {
                // Verify email
                $stmt = $db->prepare("
                    UPDATE admins 
                    SET email_verified = 1, 
                        verification_token = NULL, 
                        verification_token_expires_at = NULL 
                    WHERE id = ?
                ");
                $stmt->execute([$admin['id']]);
                
                $success = 'メール認証が完了しました。ログインページからログインしてください。';
            }
        }
    } catch (Exception $e) {
        error_log("Admin Verification Error: " . $e->getMessage());
        $error = 'エラーが発生しました';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>メール認証 - 不動産AI名刺管理システム</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
    <style>
        .verification-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .verification-box {
            background: #fff;
            border-radius: 8px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .btn-primary {
            display: inline-block;
            padding: 12px 30px;
            background: #0066cc;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            text-align: center;
            margin-top: 20px;
        }
        .btn-primary:hover {
            background: #0052a3;
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="verification-box">
            <h1 style="text-align: center; margin-bottom: 30px;">メール認証</h1>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <div style="text-align: center;">
                    <a href="login.php" class="btn-primary">ログインページへ戻る</a>
                </div>
            <?php elseif ($success): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <div style="text-align: center;">
                    <a href="login.php" class="btn-primary">ログインページへ</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
