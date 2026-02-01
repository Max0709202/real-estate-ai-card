<?php
/**
 * Verify Email Reset Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';

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
        $stmt = $db->prepare("SELECT id, email, email_reset_new_email, email_reset_token_expires_at FROM users WHERE email_reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // トークンの有効期限チェック
            $now = date('Y-m-d H:i:s');
            if ($user['email_reset_token_expires_at'] && $user['email_reset_token_expires_at'] < $now) {
                // トークンが期限切れの場合、トークンをクリア
                $stmt = $db->prepare("UPDATE users SET email_reset_token = NULL, email_reset_token_expires_at = NULL, email_reset_new_email = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
                $error = 'メールアドレス変更リンクの有効期限が切れています。再度メールアドレス変更をリクエストしてください。';
            } else {
                // メールアドレスを更新
                $newEmail = $user['email_reset_new_email'];
                
                // 新しいメールアドレスが既に使用されていないか再チェック
                $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $checkStmt->execute([$newEmail, $user['id']]);
                if ($checkStmt->fetch()) {
                    $error = 'このメールアドレスは既に使用されています。';
                } else {
                    // メールアドレスを更新し、トークンをクリア
                    $stmt = $db->prepare("UPDATE users SET email = ?, email_reset_token = NULL, email_reset_token_expires_at = NULL, email_reset_new_email = NULL WHERE id = ?");
                    $stmt->execute([$newEmail, $user['id']]);

                    // セッションを更新（ログインしている場合）
                    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id']) {
                        $_SESSION['user_email'] = $newEmail;
                    }

                    $success = true;
                    $message = 'メールアドレスが正常に変更されました。';
                }
            }
        } else {
            $error = '無効なメールアドレス変更リンクです。';
        }
    } catch (Exception $e) {
        error_log("Verify Email Reset Error: " . $e->getMessage());
        $error = 'サーバーエラーが発生しました。しばらくしてから再度お試しください。';
    }
} else {
    $error = 'メールアドレス変更トークンが指定されていません。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>メールアドレス変更確認 - 不動産AI名刺</title>
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
            <h1 class="verification-title">変更完了</h1>
            <p class="verification-message success">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?><br>
                新しいメールアドレスでログインできます。
            </p>
            <div>
                <a href="../edit.php" class="btn-primary">マイページへ</a>
                <a href="../login.php" class="btn-secondary">ログインページへ</a>
            </div>
        <?php else: ?>
            <div class="verification-icon error">✗</div>
            <h1 class="verification-title">エラー</h1>
            <p class="verification-message error">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <div>
                <a href="reset-email.php" class="btn-primary">メールアドレス変更を再度リクエスト</a>
                <a href="../login.php" class="btn-secondary">ログインページへ</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

