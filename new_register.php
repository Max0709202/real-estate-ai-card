<?php
/**
 * New User Registration Page (Step 1: Account Registration)
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/includes/functions.php';

startSessionIfNotStarted();

$existingNavSuffix = existing_user_nav_suffix(false);

// Get initial userType and token from URL (token はセッションからも復元)
$userType = $_GET['type'] ?? 'new'; // new, existing
$invitationToken = trim((string) ($_GET['token'] ?? ''));
if ($invitationToken === '' && !empty($_SESSION['existing_invite_token'])) {
    $invitationToken = trim((string) $_SESSION['existing_invite_token']);
}
$isTokenBased = !empty($invitationToken);
$tokenValid = false;
$tokenData = null;

// Validate token if provided and ensure type matches token's role_type（DB 直参照、cURL 不使用）
if ($isTokenBased) {
    try {
        require_once __DIR__ . '/backend/config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        $invCheck = validateInvitationTokenInDatabase($db, $invitationToken);
        if ($invCheck['ok']) {
            $tokenValid = true;
            $tokenData = $invCheck['data'];
            $tokenRoleType = $tokenData['role_type'] ?? null;
            if ($tokenRoleType === 'existing') {
                $userType = $tokenRoleType;
            }
        }
    } catch (Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
    }
}

// Ensure that if type is existing, token is also present (security check)
// If type is existing but no token, log warning but allow registration to proceed
if ($userType === 'existing' && empty($invitationToken)) {
    error_log("Warning: Registration with type={$userType} but no token provided");
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($isTokenBased): ?>
    <!-- Prevent search engine indexing for token-based pages -->
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow">
    <?php endif; ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>アカウント作成 - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/register.css">
    <link rel="stylesheet" href="assets/css/modal.css">
</head>
<body>
    <div class="register-container">
        <!-- <div class="register-header">
            <a href="index.php<?php echo ($isTokenBased && !empty($invitationToken)) ? '?token=' . urlencode($invitationToken) . ($userType === 'existing' ? '&type=' . $userType : '') : ''; ?>" class="logo-link">
                <img src="assets/images/logo.png" alt="不動産AI名刺">
            </a>
        </div> -->

        <div class="new_register-content">

            <!-- Step 1: Account Registration -->
            <div id="step-1" class="register-step active">
                <h1>アカウント作成</h1>
                <p class="step-description">初めてご利用の方はアカウント作成から始めてください。</p>

                <form id="register-form" class="register-form">
                    <input type="hidden" name="user_type" value="<?php echo htmlspecialchars($userType); ?>">
                    <?php if ($isTokenBased && !empty($invitationToken)): ?>
                    <input type="hidden" name="invitation_token" id="invitation_token" value="<?php echo htmlspecialchars($invitationToken); ?>">
                    <?php endif; ?>

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

                    <?php if ($userType === 'existing'): ?>
                    <div class="form-group new-register-era-block">
                        <label>会員情報の確認 <span class="required">*</span></label>
                        <p class="new-register-era-hint">サービスをご利用いただく前に、以下をご確認ください。</p>
                        <label class="new-register-era-option" id="new-reg-era-yes-wrap">
                            <input type="radio" name="era_membership" value="1" id="new-reg-era-yes">
                            <span>ERA会員です</span>
                        </label>
                        <label class="new-register-era-option" id="new-reg-era-no-wrap">
                            <input type="radio" name="era_membership" value="0" id="new-reg-era-no">
                            <span>ERA会員ではありません</span>
                        </label>
                    </div>
                    <?php endif; ?>

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
                            既にアカウントをお持ちの方は<a href="login.php<?php echo htmlspecialchars($existingNavSuffix); ?>" style="color: #0066cc; text-decoration: underline;">こちらからログイン</a>してください
                        </p>
                    </div>
                    <div class="login-link" style="margin-top: 1rem; display: flex; justify-content: center;">
                        <a href="index.php">ホームページへ戻る</a>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary btn-large">次へ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        window.registerUserType = <?php echo json_encode($userType, JSON_UNESCAPED_UNICODE); ?>;
        window.existingNavSuffixPhp = <?php echo json_encode($existingNavSuffix ?? '', JSON_UNESCAPED_UNICODE); ?>;

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

        (function () {
            const yes = document.getElementById('new-reg-era-yes');
            const no = document.getElementById('new-reg-era-no');
            const wrapYes = document.getElementById('new-reg-era-yes-wrap');
            const wrapNo = document.getElementById('new-reg-era-no-wrap');
            if (!yes || !no || !wrapYes || !wrapNo) {
                return;
            }
            function syncEraStyles() {
                wrapYes.classList.toggle('selected', yes.checked);
                wrapNo.classList.toggle('selected', no.checked);
            }
            yes.addEventListener('change', syncEraStyles);
            no.addEventListener('change', syncEraStyles);
            wrapYes.addEventListener('click', function () {
                yes.checked = true;
                syncEraStyles();
            });
            wrapNo.addEventListener('click', function () {
                no.checked = true;
                syncEraStyles();
            });
        })();

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

        // Show email verification modal
        function showEmailVerificationModal() {
            // Create HTML message with proper structure to prevent line breaks in mobile/tablet
            const message = '<div style="line-height: 35px;">' + [
                '・登録されたメールアドレスに認証メールをお送りしました。',
                '・メールに表示されたURLより作成・編集を始めてください。',
                '・なお、表示されるURLは15分間の有効期限があります。',
                '・迷惑メールに保存されてしまう場合がございますので、そちらもあわせてご確認ください。'
            ].join('<br>') + '</div>';

            // Use innerHTML to set the message directly
            showSuccess('', {
                title: '認証メール送信完了',
                autoClose: 0,
                customMessage: message
            });
        }

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

            if (window.registerUserType === 'existing') {
                const era = data.era_membership;
                if (era !== '0' && era !== '1') {
                    isSubmitting = false;
                    submitButton.disabled = false;
                    submitButton.textContent = originalButtonText;
                    if (typeof showWarning === 'function') {
                        showWarning('ERA会員かどうかを選択してください。');
                    } else {
                        alert('ERA会員かどうかを選択してください。');
                    }
                    return;
                }
                data.is_era_member = era === '1';
                delete data.era_membership;
            }

            try {
                const response = await fetch('backend/api/auth/register.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (!result.success && result.redirect_login && window.registerUserType === 'existing') {
                    const emailVal = (data.email || '').trim();
                    let loginUrl = 'login.php' + (window.existingNavSuffixPhp || '');
                    loginUrl += (loginUrl.indexOf('?') !== -1 ? '&' : '?') + 'email=' + encodeURIComponent(emailVal);
                    window.location.href = loginUrl;
                    return;
                }

                if (result.success) {
                    // Clear auto-save drafts on success
                    // if (window.autoSave) {
                    //     await window.autoSave.clearDraftsOnSuccess();
                    // }
                    // Prevent further submissions
                    submitButton.textContent = '登録完了';
                    // Show success modal with email verification message
                    showEmailVerificationModal();
                } else {
                    // Mark submission as failed (keep drafts)
                    if (window.autoSave) {
                        window.autoSave.markSubmissionFailed();
                    }
                    // Re-enable button on error
                    isSubmitting = false;
                    submitButton.disabled = false;
                    submitButton.textContent = originalButtonText;

                    // Display all validation errors using the error handler
                    showApiError(result, '登録に失敗しました');
                }
            } catch (error) {
                console.error('Error:', error);
                // Mark submission as failed (keep drafts)
                if (window.autoSave) {
                    window.autoSave.markSubmissionFailed();
                }
                // Re-enable button on error
                isSubmitting = false;
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
                showError('エラーが発生しました');
            }
        });
    </script>
    <!-- <script src="assets/js/auto-save.js"></script> -->
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/error-handler.js"></script>
</body>
</html>

