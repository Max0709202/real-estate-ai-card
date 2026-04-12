<?php
/**
 * Existing User Verification Page
 * Landing page for existing users who receive invitation emails
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

$token = $_GET['token'] ?? '';
$userType = 'existing'; // Always existing for this page
$tokenValid = false;
$errorMessage = '';
$invitationData = null;

if (!empty($token)) {
    try {
        $database = new Database();
        $db = $database->getConnection();

        // Validate token (no expiration for existing invitation links)
        $stmt = $db->prepare("
            SELECT id, email, role_type, invitation_token_expires_at
            FROM email_invitations
            WHERE invitation_token = ?
        ");
        $stmt->execute([$token]);
        $invitationData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invitationData) {
            $tokenValid = true;
        } else {
            $errorMessage = '無効な招待リンクです。';
        }
    } catch (Exception $e) {
        error_log("Existing User Verify Error: " . $e->getMessage());
        $errorMessage = 'エラーが発生しました。しばらくしてから再度お試しください。';
    }
} else {
    $errorMessage = '招待トークンが指定されていません。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>招待リンクの確認 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .verification-container {
            max-width: 600px;
            margin: 80px auto;
            padding: 40px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
        }
        .verification-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
        }
        .verification-icon.success {
            background: #d4edda;
            color: #28a745;
        }
        .verification-icon.error {
            background: #f8d7da;
            color: #dc3545;
        }
        .verification-icon.expired {
            background: #fff3cd;
            color: #856404;
        }
        .verification-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 16px;
            color: #333;
        }
        .verification-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .btn-continue {
            display: inline-block;
            padding: 14px 40px;
            background: linear-gradient(135deg, #0066cc 0%, #0052a3 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        a.btn-continue {
            text-decoration: none;
            color: #fff;
        }

        .btn-continue:hover {
            background: linear-gradient(135deg, #0052a3 0%, #003d7a 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 102, 204, 0.3);
        }
        .btn-continue:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .home-link {
            margin-top: 20px;
        }
        .home-link a {
            color: #0066cc;
            text-decoration: none;
        }
        .home-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .verification-container {
                margin: 40px 16px;
                padding: 24px;
            }
        }
    </style>
</head>
<body style="background: #f5f5f5; min-height: 100vh;">
    <div class="verification-container">
        <?php if ($tokenValid): ?>
            <div class="verification-icon success">✓</div>
            <h1 class="verification-title">招待リンクが確認されました</h1>
            <p class="verification-message">
                不動産AI名刺サービスへようこそ。<br>
                下のボタンからアカウント登録へ進み、会員情報（ERA会員の有無）の確認と登録を行ってください。
            </p>
            <a class="btn-continue" href="../new_register.php?type=existing&amp;token=<?php echo urlencode($token); ?>">
                アカウント登録へ進む
            </a>
        <?php else: ?>
            <div class="verification-icon error">✕</div>
            <h1 class="verification-title">エラー</h1>
            <p class="verification-message">
                <?php echo htmlspecialchars($errorMessage); ?>
            </p>
            <div class="home-link">
                <a href="../index.php">ホームページへ戻る</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
