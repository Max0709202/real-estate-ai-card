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
$tokenExpired = false;
$errorMessage = '';
$invitationData = null;

if (!empty($token)) {
    try {
        $database = new Database();
        $db = $database->getConnection();

        // Validate token
        $stmt = $db->prepare("
            SELECT id, email, role_type, invitation_token_expires_at
            FROM email_invitations
            WHERE invitation_token = ?
        ");
        $stmt->execute([$token]);
        $invitationData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invitationData) {
            // Check expiration
            if (!empty($invitationData['invitation_token_expires_at'])) {
                $expiresAt = strtotime($invitationData['invitation_token_expires_at']);
                if (time() > $expiresAt) {
                    $tokenExpired = true;
                    $errorMessage = '招待リンクの有効期限が切れています。管理者に再送信を依頼してください。';
                } else {
                    $tokenValid = true;
                }
            } else {
                $tokenValid = true;
            }
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

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: white;
            padding: 32px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin-bottom: 24px;
            text-align: center;
        }
        .modal-body {
            margin-bottom: 24px;
        }
        .era-option {
            display: block;
            padding: 16px 20px;
            margin-bottom: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .era-option:hover {
            border-color: #0066cc;
            background: #f8f9fa;
        }
        .era-option.selected {
            border-color: #0066cc;
            background: #e8f4fd;
        }
        .era-option input[type="radio"] {
            margin-right: 12px;
            transform: scale(1.2);
        }
        .era-option-label {
            font-size: 15px;
            color: #333;
            font-weight: 500;
        }
        .modal-footer {
            text-align: center;
        }
        .btn-modal-continue {
            display: inline-block;
            padding: 14px 50px;
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-modal-continue:hover {
            background: linear-gradient(135deg, #1e7e34 0%, #155724 100%);
            transform: translateY(-2px);
        }
        .btn-modal-continue:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
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
            .modal-content {
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
                下のボタンをクリックして、名刺編集画面に進んでください。
            </p>
            <button type="button" class="btn-continue" id="continue-btn">
                編集画面へ進む
            </button>
        <?php elseif ($tokenExpired): ?>
            <div class="verification-icon expired">⏰</div>
            <h1 class="verification-title">招待リンクの有効期限切れ</h1>
            <p class="verification-message">
                <?php echo htmlspecialchars($errorMessage); ?>
            </p>
            <div class="home-link">
                <a href="../index.php">ホームページへ戻る</a>
            </div>
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

    <!-- ERA Membership Modal -->
    <div class="modal-overlay" id="era-modal">
        <div class="modal-content">
            <h2 class="modal-title">会員情報の確認</h2>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: #666; font-size: 14px; text-align: center;">
                    サービスをご利用いただく前に、以下をご確認ください。
                </p>
                
                <label class="era-option" id="era-yes-option">
                    <input type="radio" name="era_membership" value="1" id="era-yes">
                    <span class="era-option-label">ERA会員です</span>
                </label>
                
                <label class="era-option" id="era-no-option">
                    <input type="radio" name="era_membership" value="0" id="era-no">
                    <span class="era-option-label">ERA会員ではありません</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-continue" id="modal-continue-btn" disabled>
                    続ける
                </button>
            </div>
        </div>
    </div>

    <script>
        const token = <?php echo json_encode($token); ?>;
        const userType = 'existing';
        
        // Continue button opens the modal
        const continueBtn = document.getElementById('continue-btn');
        const modal = document.getElementById('era-modal');
        const modalContinueBtn = document.getElementById('modal-continue-btn');
        const eraYesOption = document.getElementById('era-yes-option');
        const eraNoOption = document.getElementById('era-no-option');
        const eraYes = document.getElementById('era-yes');
        const eraNo = document.getElementById('era-no');
        
        if (continueBtn) {
            continueBtn.addEventListener('click', function() {
                modal.classList.add('active');
            });
        }
        
        // Handle option selection styling
        function updateOptionStyles() {
            eraYesOption.classList.toggle('selected', eraYes.checked);
            eraNoOption.classList.toggle('selected', eraNo.checked);
            modalContinueBtn.disabled = !(eraYes.checked || eraNo.checked);
        }
        
        eraYes.addEventListener('change', updateOptionStyles);
        eraNo.addEventListener('change', updateOptionStyles);
        eraYesOption.addEventListener('click', function() {
            eraYes.checked = true;
            updateOptionStyles();
        });
        eraNoOption.addEventListener('click', function() {
            eraNo.checked = true;
            updateOptionStyles();
        });
        
        // Modal continue button - save ERA membership and navigate to edit.php
        modalContinueBtn.addEventListener('click', async function() {
            const isEraMember = eraYes.checked;
            
            modalContinueBtn.disabled = true;
            modalContinueBtn.textContent = '処理中...';
            
            try {
                // Save ERA membership status
                const response = await fetch('../backend/api/auth/update-era-membership.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        token: token,
                        is_era_member: isEraMember
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Go directly to edit.php with token-based guest access
                    window.location.href = '../edit.php?type=' + userType + '&token=' + encodeURIComponent(token);
                } else {
                    alert(result.message || 'エラーが発生しました。');
                    modalContinueBtn.disabled = false;
                    modalContinueBtn.textContent = '続ける';
                }
            } catch (error) {
                console.error('Error:', error);
                alert('エラーが発生しました。しばらくしてから再度お試しください。');
                modalContinueBtn.disabled = false;
                modalContinueBtn.textContent = '続ける';
            }
        });
        
        // Close modal when clicking outside
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    </script>
</body>
</html>
