<?php
/**
 * Reset Password Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

$token = $_GET['token'] ?? '';
$validToken = false;
$error = '';
$email = '';

// トークンの検証
if (!empty($token)) {
    try {
        $database = new Database();
        $db = $database->getConnection();

        // トークン検証（有効期限もチェック）
        $stmt = $db->prepare("SELECT id, email, password_reset_token_expires_at FROM users WHERE password_reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // トークンの有効期限チェック
            $now = date('Y-m-d H:i:s');
            if ($user['password_reset_token_expires_at'] && $user['password_reset_token_expires_at'] < $now) {
                // トークンが期限切れの場合、トークンをクリア
                $stmt = $db->prepare("UPDATE users SET password_reset_token = NULL, password_reset_token_expires_at = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
                $error = 'パスワードリセットリンクの有効期限が切れています。再度パスワードリセットをリクエストしてください。';
            } else {
                $validToken = true;
                $email = $user['email'];
            }
        } else {
            $error = '無効なパスワードリセットリンクです。';
        }
    } catch (Exception $e) {
        error_log("Reset Password Token Verification Error: " . $e->getMessage());
        $error = 'サーバーエラーが発生しました。しばらくしてから再度お試しください。';
    }
} else {
    $error = 'パスワードリセットトークンが指定されていません。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>パスワードリセット - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/auth-mobile.css">
    <style>
        .reset-password-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
        }
        .reset-password-box {
            background: white;
            padding: 3rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 450px;
        }
        .reset-password-box h1 {
            text-align: center;
            margin-bottom: 1rem;
            color: #333;
        }
        .reset-password-box p {
            text-align: center;
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        .password-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-input-wrapper .form-control {
            padding-right: 3rem;
        }
        .password-toggle {
            position: absolute;
            right: 0.75rem;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: color 0.3s;
            z-index: 1;
        }
        .password-toggle:hover {
            color: #0066cc;
        }
        .password-toggle:focus {
            outline: none;
            color: #0066cc;
        }
        .eye-icon {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
        }
        .btn-primary {
            width: 100%;
            padding: 0.75rem;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-primary:hover {
            background: #0052a3;
        }
        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .back-link a {
            color: #0066cc;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            text-align: center;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .password-requirements {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }
        .email-display {
            background: #f9f9f9;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="reset-password-container">
        <div class="reset-password-box">
            <h1>パスワードリセット</h1>
            
            <div id="message-container">
                <?php if ($error): ?>
                    <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
            
            <?php if ($validToken): ?>
                <div class="email-display">
                    <strong>メールアドレス:</strong> <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <p>新しいパスワードを入力してください。</p>
                
                <form id="reset-password-form">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="form-group">
                        <label for="password">新しいパスワード</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="password" name="password" class="form-control" required minlength="8" placeholder="8文字以上">
                            <button type="button" class="password-toggle" id="toggle-password" aria-label="パスワードを表示">
                                <svg class="eye-icon eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <svg class="eye-icon eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
                                    <path d="M17.94 17.94C16.2306 19.243 14.1491 19.9649 12 20C5 20 1 12 1 12C2.24389 9.68192 3.96914 7.65663 6.06 6.06M9.9 4.24C10.5883 4.0789 11.2931 3.99836 12 4C19 4 23 12 23 12C22.393 13.1356 21.6691 14.2048 20.84 15.19M14.12 14.12C13.8454 14.4148 13.5141 14.6512 13.1462 14.8151C12.7782 14.9791 12.3809 15.0673 11.9781 15.0744C11.5753 15.0815 11.1751 15.0074 10.8016 14.8565C10.4281 14.7056 10.0887 14.4811 9.80385 14.1962C9.51897 13.9113 9.29439 13.5719 9.14351 13.1984C8.99262 12.8249 8.91853 12.4247 8.92563 12.0219C8.93274 11.6191 9.02091 11.2218 9.18488 10.8538C9.34884 10.4859 9.58525 10.1546 9.88 9.88M1 1L23 23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                        <div class="password-requirements">パスワードは8文字以上で入力してください</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password_confirm">パスワード（確認）</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control" required minlength="8" placeholder="パスワードを再入力">
                            <button type="button" class="password-toggle" id="toggle-password-confirm" aria-label="パスワードを表示">
                                <svg class="eye-icon eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <svg class="eye-icon eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
                                    <path d="M17.94 17.94C16.2306 19.243 14.1491 19.9649 12 20C5 20 1 12 1 12C2.24389 9.68192 3.96914 7.65663 6.06 6.06M9.9 4.24C10.5883 4.0789 11.2931 3.99836 12 4C19 4 23 12 23 12C22.393 13.1356 21.6691 14.2048 20.84 15.19M14.12 14.12C13.8454 14.4148 13.5141 14.6512 13.1462 14.8151C12.7782 14.9791 12.3809 15.0673 11.9781 15.0744C11.5753 15.0815 11.1751 15.0074 10.8016 14.8565C10.4281 14.7056 10.0887 14.4811 9.80385 14.1962C9.51897 13.9113 9.29439 13.5719 9.14351 13.1984C8.99262 12.8249 8.91853 12.4247 8.92563 12.0219C8.93274 11.6191 9.02091 11.2218 9.18488 10.8538C9.34884 10.4859 9.58525 10.1546 9.88 9.88M1 1L23 23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-primary" id="submit-btn">パスワードをリセット</button>
                </form>
            <?php else: ?>
                <div class="back-link">
                    <a href="forgot-password.php">パスワードリセットを再度リクエスト</a>
                </div>
            <?php endif; ?>
            
            <div class="back-link">
                <a href="../login.php">ログインページに戻る</a>
            </div>
        </div>
    </div>
    
    <script>
        // Password toggle functionality
        function setupPasswordToggle(toggleId, inputId) {
            const toggle = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
            if (!toggle || !input) return;
            
            const eyeOpen = toggle.querySelector('.eye-open');
            const eyeClosed = toggle.querySelector('.eye-closed');
            
            toggle.addEventListener('click', function() {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                
                if (isPassword) {
                    eyeOpen.style.display = 'none';
                    eyeClosed.style.display = 'block';
                } else {
                    eyeOpen.style.display = 'block';
                    eyeClosed.style.display = 'none';
                }
            });
        }
        
        // Setup password toggles
        setupPasswordToggle('toggle-password', 'password');
        setupPasswordToggle('toggle-password-confirm', 'password_confirm');
        
        const form = document.getElementById('reset-password-form');
        const messageContainer = document.getElementById('message-container');
        const submitBtn = document.getElementById('submit-btn');
        
        if (form) {
            function showMessage(message, type) {
                messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
                messageContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const password = document.getElementById('password').value;
                const passwordConfirm = document.getElementById('password_confirm').value;
                
                if (password.length < 8) {
                    showMessage('パスワードは8文字以上で入力してください', 'error');
                    return;
                }
                
                if (password !== passwordConfirm) {
                    showMessage('パスワードが一致しません', 'error');
                    return;
                }
                
                submitBtn.disabled = true;
                submitBtn.textContent = 'リセット中...';
                
                try {
                    const formData = new FormData(form);
                    const data = Object.fromEntries(formData);
                    
                    const response = await fetch('../../backend/api/auth/reset-password.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        showMessage(result.message || 'パスワードが正常にリセットされました。ログインページに移動します。', 'success');
                        setTimeout(() => {
                            window.location.href = '../login.php';
                        }, 2000);
                    } else {
                        showMessage(result.message || 'エラーが発生しました。もう一度お試しください。', 'error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'パスワードをリセット';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showMessage('エラーが発生しました。しばらくしてから再度お試しください。', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'パスワードをリセット';
                }
            });
        }
    </script>
</body>
</html>

