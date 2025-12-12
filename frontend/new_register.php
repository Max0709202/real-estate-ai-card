<?php
/**
 * New User Registration Page (Step 1: Account Registration)
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

$userType = $_GET['type'] ?? 'new'; // new, existing, free
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アカウント作成 - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/register.css">
    <link rel="stylesheet" href="assets/css/modal.css">
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <a href="index.php" class="logo-link">
                <img src="assets/images/logo.png" alt="不動産AI名刺">
            </a>
        </div>

        <div class="new_register-content">

            <!-- Step 1: Account Registration -->
            <div id="step-1" class="register-step active">
                <h1>アカウント作成</h1>
                <p class="step-description">初めてご利用の方は、アカウント作成から始めましょう</p>

                <form id="register-form" class="register-form">
                    <input type="hidden" name="user_type" value="<?php echo htmlspecialchars($userType); ?>">

                    <?php if ($userType === 'existing'): ?>
                    <div class="form-group">
                        <label>既存URL（既存利用者のみ）</label>
                        <input type="text" name="existing_url" class="form-control" placeholder="既存のサービスURLを入力">
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>メールアドレス <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>パスワード <span class="required">*</span></label>
                        <div class="password-input-wrapper">
                            <input type="password" name="password" id="password" class="form-control" minlength="8" required>
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
                        <small>8文字以上で入力してください</small>
                    </div>

                    <div class="form-group">
                        <label>パスワード（確認） <span class="required">*</span></label>
                        <div class="password-input-wrapper">
                            <input type="password" name="password_confirm" id="password_confirm" class="form-control" minlength="8" required>
                            <button type="button" class="password-toggle" id="toggle-password-confirm" aria-label="パスワード確認を表示">
                                <svg class="eye-icon eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <svg class="eye-icon eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
                                    <path d="M17.94 17.94C16.2306 19.243 14.1491 19.9649 12 20C5 20 1 12 1 12C2.24389 9.68192 3.96914 7.65663 6.06 6.06M9.9 4.24C10.5883 4.0789 11.2931 3.99836 12 4C19 4 23 12 23 12C22.393 13.1356 21.6691 14.2048 20.84 15.19M14.12 14.12C13.8454 14.4148 13.5141 14.6512 13.1462 14.8151C12.7782 14.9791 12.3809 15.0673 11.9781 15.0744C11.5753 15.0815 11.1751 15.0074 10.8016 14.8565C10.4281 14.7056 10.0887 14.4811 9.80385 14.1962C9.51897 13.9113 9.29439 13.5719 9.14351 13.1984C8.99262 12.8249 8.91853 12.4247 8.92563 12.0219C8.93274 11.6191 9.02091 11.2218 9.18488 10.8538C9.34884 10.4859 9.58525 10.1546 9.88 9.88M1 1L23 23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                        <small id="password-error" style="color: #e74c3c; display: none;">パスワードが一致しません</small>
                    </div>

                    <div class="form-group">
                        <label>携帯電話番号 <span class="required">*</span></label>
                        <input type="tel" name="phone_number" class="form-control" required>
                    </div>

                    <div class="form-group checkbox-group">
                        <label>
                            <input type="checkbox" name="agree_terms" required>
                            <a href="terms.php" target="_blank">利用規約</a>に同意する
                        </label>
                    </div>

                    <div class="form-group checkbox-group">
                        <label>
                            <input type="checkbox" name="agree_privacy" required>
                            <a href="privacy.php" target="_blank">プライバシーポリシー</a>に同意する
                        </label>
                    </div>

                    <div class="form-group" style="text-align: center; margin-top: 1rem;">
                        <p style="color: #666; font-size: 0.9rem;">
                            既にアカウントをお持ちの方は<a href="login.php" style="color: #0066cc; text-decoration: underline;">こちらからログイン</a>してください
                        </p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary btn-large">次へ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Password toggle functionality
        function setupPasswordToggle(toggleId, inputId) {
            const toggle = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
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
        
        // Password confirmation validation
        const passwordField = document.getElementById('password');
        const passwordConfirmField = document.getElementById('password_confirm');
        const passwordError = document.getElementById('password-error');
        
        function validatePasswordMatch() {
            const password = passwordField.value;
            const passwordConfirm = passwordConfirmField.value;
            
            if (passwordConfirm && password !== passwordConfirm) {
                passwordError.style.display = 'block';
                passwordConfirmField.setCustomValidity('パスワードが一致しません');
                return false;
            } else {
                passwordError.style.display = 'none';
                passwordConfirmField.setCustomValidity('');
                return true;
            }
        }
        
        passwordField.addEventListener('input', validatePasswordMatch);
        passwordConfirmField.addEventListener('input', validatePasswordMatch);
        
        // Step 1: Account Registration
        let isSubmitting = false; // Flag to prevent duplicate submissions
        
        document.getElementById('register-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Prevent duplicate submissions
            if (isSubmitting) {
                console.log('Registration already in progress, ignoring duplicate submission');
                return;
            }
            
            // Validate password match
            if (!validatePasswordMatch()) {
                passwordConfirmField.focus();
                return;
            }
            
            // Disable submit button and set flag
            const submitButton = e.target.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.textContent;
            isSubmitting = true;
            submitButton.disabled = true;
            submitButton.textContent = '登録中...';
            
            const formDataObj = new FormData(e.target);
            const data = Object.fromEntries(formDataObj);
            
            // Remove password_confirm from data before sending
            delete data.password_confirm;
            
            try {
                const response = await fetch('../backend/api/auth/register.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Prevent further submissions
                    submitButton.textContent = '登録完了';
                    // Redirect after a short delay to ensure email is sent
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 500);
                } else {
                    // Re-enable button on error
                    isSubmitting = false;
                    submitButton.disabled = false;
                    submitButton.textContent = originalButtonText;
                    showError(result.message || '登録に失敗しました');
                }
            } catch (error) {
                console.error('Error:', error);
                // Re-enable button on error
                isSubmitting = false;
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
                showError('エラーが発生しました');
            }
        });
    </script>
    <script src="assets/js/modal.js"></script>
</body>
</html>

