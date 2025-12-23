<?php
/**
 * Admin/Client Password Reset
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_once __DIR__ . '/../../backend/config/database.php';

startSessionIfNotStarted();

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    $error = '無効なトークンです';
} else {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $stmt = $db->prepare("
            SELECT * FROM admins 
            WHERE password_reset_token = ? 
            AND password_reset_token_expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            $error = '無効または期限切れのトークンです';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($password) || empty($confirmPassword)) {
                $error = 'パスワードを入力してください';
            } elseif ($password !== $confirmPassword) {
                $error = 'パスワードが一致しません';
            } elseif (strlen($password) < 8) {
                $error = 'パスワードは8文字以上で入力してください';
            } else {
                // Update password
                $passwordHash = hashPassword($password);
                $updateStmt = $db->prepare("
                    UPDATE admins 
                    SET password_hash = ?,
                        password_reset_token = NULL,
                        password_reset_token_expires_at = NULL,
                        last_password_change = NOW(),
                        last_password_changed_by = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$passwordHash, $admin['id'], $admin['id']]);
                
                $success = 'パスワードをリセットしました。ログインページからログインしてください。';
            }
        }
    } catch (Exception $e) {
        error_log("Password reset error: " . $e->getMessage());
        $error = 'エラーが発生しました';
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
    </style>
</head>
<body>
    <div class="login-container">
        <h1>パスワードリセット</h1>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <div style="text-align: center; margin-top: 1rem;">
                <a href="login.php">ログインページへ</a>
            </div>
        <?php elseif (!$error): ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label>新しいパスワード</label>
                    <input type="password" name="password" class="form-control" required minlength="8">
                </div>
                
                <div class="form-group">
                    <label>パスワード（確認）</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8">
                </div>
                
                <button type="submit" class="btn-primary">パスワードをリセット</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>







