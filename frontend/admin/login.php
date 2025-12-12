<?php
/**
 * Admin Login Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

// 既にログイン済みの場合はダッシュボードへ
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>管理者ログイン - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
</head>
<body>
    <div class="admin-login-container">
        <div class="admin-login-box">
            <div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0;">
                <button type="button" id="tab-login" class="tab-button active" style="flex: 1; padding: 10px; background: none; border: none; border-bottom: 2px solid #0066cc; color: #0066cc; cursor: pointer; font-size: 16px;">ログイン</button>
                <button type="button" id="tab-register" class="tab-button" style="flex: 1; padding: 10px; background: none; border: none; border-bottom: 2px solid transparent; color: #666; cursor: pointer; font-size: 16px;">新規登録</button>
            </div>
            
            <div id="login-section">
                <h1>管理者ログイン</h1>
                <form id="admin-login-form">
                <div class="form-group">
                    <label>メールアドレス</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>パスワード</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" id="admin-login-password" class="form-control" required>
                        <button type="button" class="password-toggle" id="toggle-admin-login-password" aria-label="パスワードを表示">
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
                <div id="error-message" class="error-message" style="display: none;"></div>
            </div>
            
            <div id="register-section" style="display: none;">
                <h1>管理者新規登録</h1>
                <form id="admin-register-form">
                    <div class="form-group">
                        <label>メールアドレス</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>パスワード</label>
                        <div class="password-input-wrapper">
                            <input type="password" name="password" id="admin-register-password" class="form-control" required minlength="8">
                            <button type="button" class="password-toggle" id="toggle-admin-register-password" aria-label="パスワードを表示">
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
                    <div class="form-group">
                        <label>パスワード（確認）</label>
                        <div class="password-input-wrapper">
                            <input type="password" name="password_confirm" id="admin-register-password-confirm" class="form-control" required minlength="8">
                            <button type="button" class="password-toggle" id="toggle-admin-register-password-confirm" aria-label="パスワードを表示">
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
                    <div class="form-group">
                        <label>ロール</label>
                        <select name="role" class="form-control" required>
                            <option value="client">クライアント</option>
                            <option value="admin">管理者</option>
                        </select>
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">※管理者ロールを付与するには、既存の管理者権限が必要です</small>
                    </div>
                    <button type="submit" class="btn-primary btn-block">登録</button>
                </form>
                <div id="register-error-message" class="error-message" style="display: none;"></div>
                <div id="register-success-message" class="success-message" style="display: none;"></div>
            </div>
            
            <div style="margin-top: 20px; text-align: center;">
                <a href="../index.php" class="btn-secondary" style="display: inline-block; padding: 10px 20px; text-decoration: none; border-radius: 4px; background-color: #6c757d; color: white; transition: background-color 0.3s;">ホームページへ戻る</a>
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
        setupPasswordToggle('toggle-admin-login-password', 'admin-login-password');
        setupPasswordToggle('toggle-admin-register-password', 'admin-register-password');
        setupPasswordToggle('toggle-admin-register-password-confirm', 'admin-register-password-confirm');
        
        // Tab switching
        document.getElementById('tab-login').addEventListener('click', function() {
            document.getElementById('login-section').style.display = 'block';
            document.getElementById('register-section').style.display = 'none';
            document.getElementById('tab-login').classList.add('active');
            document.getElementById('tab-login').style.borderBottomColor = '#0066cc';
            document.getElementById('tab-login').style.color = '#0066cc';
            document.getElementById('tab-register').classList.remove('active');
            document.getElementById('tab-register').style.borderBottomColor = 'transparent';
            document.getElementById('tab-register').style.color = '#666';
        });
        
        document.getElementById('tab-register').addEventListener('click', function() {
            document.getElementById('login-section').style.display = 'none';
            document.getElementById('register-section').style.display = 'block';
            document.getElementById('tab-register').classList.add('active');
            document.getElementById('tab-register').style.borderBottomColor = '#0066cc';
            document.getElementById('tab-register').style.color = '#0066cc';
            document.getElementById('tab-login').classList.remove('active');
            document.getElementById('tab-login').style.borderBottomColor = 'transparent';
            document.getElementById('tab-login').style.color = '#666';
        });
        
        // Login form
        document.getElementById('admin-login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData);
            
            try {
                const response = await fetch('../../backend/api/admin/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = 'dashboard.php';
                } else {
                    document.getElementById('error-message').textContent = result.message || 'ログインに失敗しました';
                    document.getElementById('error-message').style.display = 'block';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('error-message').textContent = 'エラーが発生しました';
                document.getElementById('error-message').style.display = 'block';
            }
        });
        
        // Register form
        document.getElementById('admin-register-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData);
            
            // Hide previous messages
            document.getElementById('register-error-message').style.display = 'none';
            document.getElementById('register-success-message').style.display = 'none';
            
            // Client-side password confirmation validation
            if (data.password !== data.password_confirm) {
                document.getElementById('register-error-message').textContent = 'パスワードが一致しません';
                document.getElementById('register-error-message').style.display = 'block';
                return;
            }
            
            // Remove password_confirm from data before sending
            delete data.password_confirm;
            
            try {
                const response = await fetch('../../backend/api/admin/register.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('register-success-message').textContent = result.message || '登録が完了しました。メール認証を行ってください。';
                    document.getElementById('register-success-message').style.display = 'block';
                    document.getElementById('admin-register-form').reset();
                } else {
                    const errorMsg = result.message || '登録に失敗しました';
                    const errors = result.errors || {};
                    let errorText = errorMsg;
                    if (Object.keys(errors).length > 0) {
                        errorText += '\n' + Object.values(errors).join('\n');
                    }
                    document.getElementById('register-error-message').textContent = errorText;
                    document.getElementById('register-error-message').style.display = 'block';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('register-error-message').textContent = 'エラーが発生しました';
                document.getElementById('register-error-message').style.display = 'block';
            }
        });
    </script>
    <style>
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-top: 15px;
            border: 1px solid #c3e6cb;
        }
    </style>
</body>
</html>

