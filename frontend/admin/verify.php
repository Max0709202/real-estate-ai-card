<?php
/**
 * Admin Email Verification Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

$token = $_GET['token'] ?? '';
$message = '';
$success = false;

if (empty($token)) {
    $message = '認証トークンが必要です';
} else {
    try {
        $database = new Database();
        $db = $database->getConnection();

        $stmt = $db->prepare("SELECT id, email, verification_token_expires_at FROM admins WHERE verification_token = ? AND email_verified = 0");
        $stmt->execute([$token]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $message = '無効な認証トークンです';
        } else {
            $now = date('Y-m-d H:i:s');
            if ($admin['verification_token_expires_at'] && $admin['verification_token_expires_at'] < $now) {
                $stmt = $db->prepare("UPDATE admins SET verification_token = NULL, verification_token_expires_at = NULL WHERE id = ?");
                $stmt->execute([$admin['id']]);
                $message = '認証トークンの有効期限が切れています。';
            } else {
                $stmt = $db->prepare("UPDATE admins SET email_verified = 1, verification_token = NULL, verification_token_expires_at = NULL WHERE id = ?");
                $stmt->execute([$admin['id']]);
                $message = 'メール認証が完了しました。ログインページからログインしてください。';
                $success = true;
            }
        }
    } catch (Exception $e) {
        error_log("Admin verification error: " . $e->getMessage());
        $message = 'エラーが発生しました';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>メール認証 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .verify-container {
            max-width: 500px;
            margin: 100px auto;
            padding: 2rem;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        .btn-primary {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.75rem 2rem;
            background: #0066cc;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <h1>メール認証</h1>
        <p class="<?php echo $success ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </p>
        <?php if ($success): ?>
            <a href="login.php" class="btn-primary">ログインページへ</a>
        <?php endif; ?>
    </div>
</body>
</html>



