<?php
/**
 * User Login Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

// 既にログイン済みの場合はダッシュボードへ
// if (!empty($_SESSION['user_id'])) {
//     header('Location: index.php');
//     exit();
// }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>ログイン - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
        }
        .login-box {
            background: white;
            padding: 3rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        .login-box h1 {
            text-align: center;
            margin-bottom: 2rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
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
        .login-link {
            text-align: center;
            margin-top: 1rem;
        }
        .login-link a {
            color: #0066cc;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>ログイン</h1>
            <form id="login-form">
                <div class="form-group">
                    <label>メールアドレス</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>パスワード</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" id="login-password" class="form-control" required>
                        <button type="button" class="password-toggle" id="toggle-login-password" aria-label="パスワードを表示">
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
                <button type="submit" class="btn-primary btn-block">ログイン</button>
            </form>
            <div class="login-link" style="margin-top: 1rem;">
                <a href="index.php">ホームページへ戻る</a>
            </div>
            <div class="login-link">
                <a href="new_register.php">アカウントをお持ちでない方はこちら</a>
            </div>
            <div class="login-link" style="margin-top: 0.5rem;">
                <a href="auth/forgot-password.php">パスワードをお忘れですか？</a>
            </div>
            <div class="login-link" style="margin-top: 0.5rem;">
                <a href="#" id="resend-verification-link" style="font-size: 0.9rem; display: none;">認証メールを再送信する</a>
            </div>
            <div id="error-message" style="color: red; margin-top: 1rem; display: none;"></div>
            <div id="success-message" style="color: green; margin-top: 1rem; display: none;"></div>
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
        
        // Setup password toggle
        setupPasswordToggle('toggle-login-password', 'login-password');
        
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData);
            
            try {
                const response = await fetch('../backend/api/auth/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 管理者の場合は管理者ダッシュボードへ、通常ユーザーは編集ページへ
                    if (result.data.is_admin && result.data.redirect) {
                        window.location.href = result.data.redirect;
                    } else {
                        window.location.href = 'edit.php';
                    }
                } else {
                    const errorMsg = result.message || 'ログインに失敗しました';
                    document.getElementById('error-message').textContent = errorMsg;
                    document.getElementById('error-message').style.display = 'block';
                    document.getElementById('success-message').style.display = 'none';
                    
                    // メール未認証のエラーの場合、再送信リンクを表示
                    if (errorMsg.includes('認証') || errorMsg.includes('メール')) {
                        document.getElementById('resend-verification-link').style.display = 'block';
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('error-message').textContent = 'エラーが発生しました';
                document.getElementById('error-message').style.display = 'block';
            }
        });
        
        // メール再送信機能
        document.getElementById('resend-verification-link').addEventListener('click', async (e) => {
            e.preventDefault();
            const email = document.querySelector('input[name="email"]').value;
            
            if (!email) {
                showWarning('メールアドレスを入力してください');
                return;
            }
            
            try {
                const response = await fetch('../backend/api/auth/resend-verification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ email: email })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('success-message').textContent = result.message || '認証メールを再送信しました';
                    document.getElementById('success-message').style.display = 'block';
                    document.getElementById('error-message').style.display = 'none';
                } else {
                    document.getElementById('error-message').textContent = result.message || 'メール送信に失敗しました';
                    document.getElementById('error-message').style.display = 'block';
                }
            } catch (error) {
                console.error('Error:', error);
                showError('エラーが発生しました');
            }
        });
    </script>
    <script src="assets/js/modal.js"></script>
</body>
</html>

