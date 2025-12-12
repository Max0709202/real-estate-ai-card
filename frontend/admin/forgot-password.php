<?php
/**
 * Admin/Client Password Reset Request
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_once __DIR__ . '/../../backend/config/database.php';

startSessionIfNotStarted();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        $error = 'メールアドレスを入力してください';
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
            $stmt->execute([$email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin) {
                // Generate reset token
                $resetToken = generateToken(32);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Save token
                $updateStmt = $db->prepare("
                    UPDATE admins 
                    SET password_reset_token = ?, 
                        password_reset_token_expires_at = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$resetToken, $expiresAt, $admin['id']]);
                
                // Send email
                $resetLink = BASE_URL . "/frontend/admin/reset-password.php?token=" . urlencode($resetToken);
                
                $emailSubject = '【不動産AI名刺】パスワードリセット';
                $emailBody = "
                    <html>
                    <head><meta charset='UTF-8'></head>
                    <body>
                        <p>パスワードリセットのリクエストを受け付けました。</p>
                        <p>以下のリンクをクリックしてパスワードをリセットしてください：</p>
                        <p><a href='{$resetLink}'>パスワードをリセット</a></p>
                        <p>このリンクは1時間有効です。</p>
                        <p>このリクエストに心当たりがない場合は、このメールを無視してください。</p>
                    </body>
                    </html>
                ";
                
                if (sendEmail($admin['email'], $emailSubject, $emailBody, strip_tags($emailBody))) {
                    $success = 'パスワードリセット用のメールを送信しました。メールをご確認ください。';
                } else {
                    $error = 'メール送信に失敗しました。';
                }
            } else {
                // Don't reveal if email exists
                $success = 'パスワードリセット用のメールを送信しました。メールをご確認ください。';
            }
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = 'エラーが発生しました';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワードリセット - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 2rem;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .btn-primary {
            width: 100%;
            padding: 0.75rem;
            background: #0066cc;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .error-message, .success-message {
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .error-message {
            background: #fee;
            color: #c33;
        }
        .success-message {
            background: #efe;
            color: #3c3;
        }
        .back-link {
            text-align: center;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>パスワードリセット</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label>メールアドレス</label>
                <input type="email" name="email" class="form-control" required autofocus>
            </div>
            
            <button type="submit" class="btn-primary">リセットメールを送信</button>
        </form>
        
        <div class="back-link">
            <a href="login.php">ログインに戻る</a>
        </div>
    </div>
</body>
</html>



