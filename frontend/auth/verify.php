<?php
/**
 * Email Verification Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

$token = $_GET['token'] ?? '';
$message = '';
$success = false;
$error = '';

if (!empty($token)) {
    try {
        $database = new Database();
        $db = $database->getConnection();

        // トークン検証（有効期限もチェック）
        $stmt = $db->prepare("SELECT id, email, status, verification_token_expires_at FROM users WHERE verification_token = ? AND email_verified = 0");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // トークンの有効期限チェック
            $now = date('Y-m-d H:i:s');
            if ($user['verification_token_expires_at'] && $user['verification_token_expires_at'] < $now) {
                // トークンが期限切れの場合、認証を無効化
                $stmt = $db->prepare("UPDATE users SET verification_token = NULL, verification_token_expires_at = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
                $error = '認証トークンの有効期限が切れています。メール認証を再度リクエストしてください。';
            } else {
                // メール認証完了
                $stmt = $db->prepare("UPDATE users SET email_verified = 1, verification_token = NULL, verification_token_expires_at = NULL, status = 'active' WHERE id = ?");
                $stmt->execute([$user['id']]);

                // セッションを更新（ログインしている場合）
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id']) {
                    $_SESSION['email_verified'] = true;
                }

                $success = true;
                $message = 'メール認証が完了しました。';
            }
        } else {
            // 既に認証済みか、無効なトークン
            $stmt = $db->prepare("SELECT id, email_verified FROM users WHERE verification_token = ?");
            $stmt->execute([$token]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingUser && $existingUser['email_verified'] == 1) {
                $error = 'このメールアドレスは既に認証済みです。';
            } else {
                $error = '無効な認証トークンです。リンクが期限切れか、既に使用されています。';
            }
        }
    } catch (Exception $e) {
        error_log("Verification Error: " . $e->getMessage());
        $error = 'サーバーエラーが発生しました。しばらくしてから再度お試しください。';
    }
} else {
    $error = '認証トークンが指定されていません。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>メール認証 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/auth-mobile.css">
    <style>
        .verification-container {
            max-width: 600px;
            margin: 100px auto;
            padding: 40px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .verification-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }
        .verification-icon.success {
            background: #d4edda;
            color: #155724;
        }
        .verification-icon.error {
            background: #f8d7da;
            color: #721c24;
        }
        .verification-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #333;
        }
        .verification-message {
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
            color: #666;
        }
        .verification-message.success {
            color: #155724;
        }
        .verification-message.error {
            color: #721c24;
        }
        .btn-primary {
            display: inline-block;
            padding: 12px 30px;
            background: #0066cc;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            transition: background 0.3s;
        }
        .btn-primary:hover {
            background: #0052a3;
        }
        .btn-secondary {
            display: inline-block;
            padding: 12px 30px;
            background: #6c757d;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            transition: background 0.3s;
            margin-left: 10px;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <?php if ($success): ?>
            <div class="verification-icon success">✓</div>
            <h1 class="verification-title">認証完了</h1>
            <p class="verification-message success">
                メール認証が完了しました。<br>
                不動産AI名刺の作成・編集を進めてください。
            </p>
            <div>
                <a href="../register.php" class="btn-primary">作成・編集ページへ</a>
            </div>
        <?php else: ?>
            <div class="verification-icon error">✗</div>
            <h1 class="verification-title">認証エラー</h1>
            <p class="verification-message error">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <div>
                <a href="../login.php" class="btn-primary">ログインページへ</a>
                <a href="../index.php" class="btn-secondary">トップページへ</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

