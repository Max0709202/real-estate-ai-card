<?php
/**
 * Common Header Component
 * Include this file in all pages that need the header navigation
 *
 * Usage:
 *   $showNavLinks = true; // Set to false to hide navigation links (default: true)
 *   include __DIR__ . '/includes/header.php';
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

$isLoggedIn = !empty($_SESSION['user_id']);
$showNavLinks = isset($showNavLinks) ? $showNavLinks : true;

// Check if user has completed payment and has QR code
$showMyCard = false;
$cardSlug = '';
$isEmailVerified = false;
$registerPageUrl = 'new_register.php?type=new'; // Default for non-logged-in users

// Check for token-based access (existing/free users from email invitation)
// Get token and type from current URL to preserve them in the registration link
$invitationToken = $_GET['token'] ?? '';
$urlUserType = $_GET['type'] ?? null;
$isTokenBased = !empty($invitationToken);
$tokenValid = false;
$tokenUserType = null;

if ($isTokenBased && !$isLoggedIn) {
    // Validate token for non-logged-in users
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, BASE_URL . '/backend/api/auth/validate-invitation-token.php?token=' . urlencode($invitationToken));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result && $result['success']) {
                $tokenValid = true;
                $tokenUserType = $result['data']['role_type'] ?? null;

                // Set registration URL based on token type (use token's role_type, fallback to URL type)
                if (in_array($tokenUserType, ['existing', 'free'])) {
                    $registerPageUrl = 'new_register.php?type=' . $tokenUserType . '&token=' . urlencode($invitationToken);
                } elseif (in_array($urlUserType, ['existing', 'free'])) {
                    // If token validation didn't return type but URL has it, use URL type
                    $registerPageUrl = 'new_register.php?type=' . $urlUserType . '&token=' . urlencode($invitationToken);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Header token validation error: " . $e->getMessage());
    }
} elseif ($urlUserType && in_array($urlUserType, ['existing', 'free']) && !$isLoggedIn) {
    // If type is in URL but no token, still set the URL (though token should be present)
    $registerPageUrl = 'new_register.php?type=' . $urlUserType;
    if ($invitationToken) {
        $registerPageUrl .= '&token=' . urlencode($invitationToken);
    }
}

// Subscription status for mobile menu
$hasActiveSubscriptionForMobile = false;

if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../../backend/config/database.php';
        $database = new Database();
        $db = $database->getConnection();

        // Check email verification status
        $stmt = $db->prepare("SELECT email_verified FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData && $userData['email_verified'] == 1) {
            $isEmailVerified = true;
            // If email is verified, redirect to register.php
            $registerPageUrl = 'register.php';
        }

        // Check if user has completed payment and has QR code
        $stmt = $db->prepare("
            SELECT bc.url_slug, bc.qr_code_issued, bc.payment_status, bc.card_status, bc.is_published, p.payment_status as payment_status_from_payments
            FROM business_cards bc
            LEFT JOIN payments p ON bc.id = p.business_card_id AND p.payment_status = 'completed'
            WHERE bc.user_id = ?
            ORDER BY p.paid_at DESC
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $cardData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cardData && $cardData['qr_code_issued'] && ($cardData['payment_status'] === 'completed' || in_array($cardData['payment_status'], ['CR', 'BANK_PAID']))) {
            $showMyCard = true;
            $cardSlug = $cardData['url_slug'];
        }
        
        // Check payment status for mobile payment button
        // Payment is completed when: payment_status is CR or BANK_PAID AND admin checked OK (is_published == 1)
        $isPaymentCompleted = false;
        if ($cardData) {
            $paymentStatus = $cardData['payment_status'] ?? null;
            $isPublished = $cardData['is_published'] ?? 0;
            
            // Payment is completed if:
            // 1. Payment status is CR or BANK_PAID AND admin checked OK (is_published == 1)
            // 2. OR payment_status_from_payments is 'completed' (for direct payment confirmations)
            $isPaymentCompleted = (
                (in_array($paymentStatus, ['CR', 'BANK_PAID']) && $isPublished == 1) ||
                ($cardData['payment_status_from_payments'] === 'completed')
            );
        }
        
        // Check subscription status for mobile menu
        $stmt = $db->prepare("
            SELECT s.id, s.stripe_subscription_id, s.status, s.next_billing_date, s.cancelled_at,
                   bc.payment_status, u.user_type
            FROM subscriptions s
            JOIN business_cards bc ON s.business_card_id = bc.id
            JOIN users u ON bc.user_id = u.id
            WHERE s.user_id = ?
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $subscriptionInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($subscriptionInfo && in_array($subscriptionInfo['status'], ['active', 'trialing', 'past_due', 'incomplete'])) {
            $hasActiveSubscriptionForMobile = true;
        } elseif (!$subscriptionInfo) {
            // Check if user has completed payment (for new users who should have subscription)
            $stmt = $db->prepare("
                SELECT bc.payment_status, u.user_type
                FROM business_cards bc
                JOIN users u ON bc.user_id = u.id
                WHERE bc.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $cardInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cardInfo && in_array($cardInfo['payment_status'], ['CR', 'BANK_PAID']) && $cardInfo['user_type'] === 'new') {
                $hasActiveSubscriptionForMobile = true;
            }
        }
    } catch (Exception $e) {
        error_log("Header card check error: " . $e->getMessage());
    }
}
?>
<!-- Mobile CSS -->
<link rel="stylesheet" href="assets/css/mobile.css">
<!-- Modal Notification CSS -->
<link rel="stylesheet" href="assets/css/modal.css">

<!-- Header -->
<header class="header">
    <div class="container">
        <div class="header-content">
            <div class="logo">
                <a href="index.php">
                    <img src="assets/images/logo.png" alt="不動産AI名刺">
                </a>
            </div>
            <nav class="nav">
                <?php if ($showNavLinks): ?>
                <a href="index.php#features">機能</a>
                <a href="index.php#pricing">動画</a>
                <a href="index.php#howto">使い方</a>
                <a href="index.php#tools">ツール</a>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                <!-- User Menu (Person Icon with Dropdown) -->
                <div class="user-menu">
                    <div class="user-icon" id="user-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <!-- Mobile Payment Button (visible only on mobile, next to user icon) -->
                    <button type="button" id="mobile-payment-btn" class="mobile-payment-btn" style="display: none; background: <?php echo $isPaymentCompleted ? '#0066cc' : '#dc3545'; ?>; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; font-weight: 500; font-size: 0.875rem; cursor: pointer; margin-left: 0.5rem; transition: background 0.3s;">
                        <?php echo $isPaymentCompleted ? '利用可能' : 'お支払いへ進む'; ?>
                    </button>
                    <div class="user-dropdown" id="user-dropdown">
                        <?php if ($showMyCard): ?>
                        <a href="card.php?slug=<?php echo htmlspecialchars($cardSlug); ?>" class="dropdown-item" target="_blank">
                            <span>マイ名刺</span>
                        </a>
                        <?php endif; ?>
                        <a href="edit.php" class="dropdown-item">
                            <span>マイページ</span>
                        </a>
<<<<<<< HEAD
                        <?php
                        // Get subscription info for header dropdown
                        $headerSubscriptionInfo = null;
                        $headerHasActiveSubscription = false;
                        if ($isLoggedIn) {
                            try {
                                require_once __DIR__ . '/../../backend/config/database.php';
                                $database = new Database();
                                $db = $database->getConnection();
                                $stmt = $db->prepare("
                                    SELECT s.id, s.stripe_subscription_id, s.status, s.next_billing_date, s.cancelled_at,
                                           bc.payment_status, u.user_type
                                    FROM subscriptions s
                                    JOIN business_cards bc ON s.business_card_id = bc.id
                                    JOIN users u ON bc.user_id = u.id
                                    WHERE s.user_id = ?
                                    ORDER BY s.created_at DESC
                                    LIMIT 1
                                ");
                                $stmt->execute([$_SESSION['user_id']]);
                                $headerSubscriptionInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                                // Calculate end date for header
                                $headerEndDate = null;
                                if ($headerSubscriptionInfo) {
                                    if ($headerSubscriptionInfo['next_billing_date']) {
                                        $nextBilling = new DateTime($headerSubscriptionInfo['next_billing_date']);
                                        $nextBilling->modify('-1 day');
                                        $headerEndDate = $nextBilling->format('Y年n月j日');
                                    } elseif ($headerSubscriptionInfo['cancelled_at']) {
                                        $cancelled = new DateTime($headerSubscriptionInfo['cancelled_at']);
                                        $headerEndDate = $cancelled->format('Y年n月j日');
                                    }
                                }

                                if ($headerSubscriptionInfo && in_array($headerSubscriptionInfo['status'], ['active', 'trialing', 'past_due', 'incomplete'])) {
                                    $headerHasActiveSubscription = true;
                                } elseif (!$headerSubscriptionInfo) {
                                    $stmt = $db->prepare("
                                        SELECT bc.payment_status, u.user_type
                                        FROM business_cards bc
                                        JOIN users u ON bc.user_id = u.id
                                        WHERE bc.user_id = ?
                                        LIMIT 1
                                    ");
                                    $stmt->execute([$_SESSION['user_id']]);
                                    $cardInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                                    if ($cardInfo && in_array($cardInfo['payment_status'], ['CR', 'BANK_PAID']) && $cardInfo['user_type'] === 'new') {
                                        $headerHasActiveSubscription = true;
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("Header subscription check error: " . $e->getMessage());
                            }
                        }
                        ?>
                        <?php if ($headerHasActiveSubscription): ?>
                        <!-- <div class="dropdown-divider"></div> -->
                        <div class="dropdown-section-header">サブスクリプション</div>
                        <?php if ($headerSubscriptionInfo): ?>
                        <div class="dropdown-subscription-info">
                            <div class="subscription-status">
                                ステータス: <span class="status-badge status-<?php echo htmlspecialchars($headerSubscriptionInfo['status']); ?>">
                                    <?php
                                    $statusLabels = [
                                        'active' => 'アクティブ',
                                        'trialing' => 'トライアル中',
                                        'past_due' => '延滞中',
                                        'incomplete' => '未完了',
                                        'canceled' => 'キャンセル済み'
                                    ];
                                    echo htmlspecialchars($statusLabels[$headerSubscriptionInfo['status']] ?? $headerSubscriptionInfo['status']);
                                    ?>
                                </span>
                            </div>
                            <?php if ($headerSubscriptionInfo['next_billing_date']): ?>
                            <div class="subscription-next-billing">
                                次回請求日: <?php echo htmlspecialchars($headerSubscriptionInfo['next_billing_date']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <button type="button" id="header-cancel-subscription-btn" class="dropdown-item dropdown-button" style="color: #dc3545; cursor: pointer; width: 100%; text-align: left; background: none; border: none; padding: 0.75rem 1.25rem;">
                            <span>サブスクリプションをキャンセル</span>
                        </button>
                        <?php endif; ?>
                        <!-- <div class="dropdown-divider"></div> -->
                        <a href="auth/reset-email.php" class="dropdown-item">
                            <span>メールアドレスリセット</span>
                        </a>
                        <a href="auth/forgot-password.php" class="dropdown-item">
                            <span>パスワードリセット</span>
=======
                        <a href="register.php?step=6" class="dropdown-item" id="payment-list-link">
                            <span>お支払い画面</span>
>>>>>>> 60afd757e00e94a0bfb3d0271272027ccd5fcb33
                        </a>
                        <a href="#" id="logout-link" class="dropdown-item">
                            <span>ログアウト</span>
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <!-- Login Button (when not logged in) -->
                <a href="login.php" class="btn-primary">ログイン</a>
                <?php endif; ?>

                <?php if ($showNavLinks): ?>
                <a href="<?php echo htmlspecialchars($registerPageUrl); ?>" class="btn-primary">不動産AI名刺を作る</a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</header>

<script>
// User menu functionality
(function() {
    const userIcon = document.getElementById('user-icon');
    const userMenu = document.querySelector('.user-menu');
    const logoutLink = document.getElementById('logout-link');

    // Toggle dropdown on click (for mobile/touch devices)
    if (userIcon && userMenu) {
        userIcon.addEventListener('click', function(e) {
            e.stopPropagation();
            userMenu.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userMenu.contains(e.target)) {
                userMenu.classList.remove('active');
            }
        });
    }

<<<<<<< HEAD
    // Subscription cancellation handler for header dropdown
    const headerCancelBtn = document.getElementById('header-cancel-subscription-btn');
    if (headerCancelBtn) {
        headerCancelBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();

            // Build detailed confirmation message
            const headerEndDateText = <?php echo json_encode($headerEndDate ?? '未設定'); ?>;
            const headerEndDateDisplay = headerEndDateText !== '未設定' ? headerEndDateText : '（未設定）';

            const headerConfirmMessage =
                '・停止されても、マイページで作って頂いたAI名刺はアカウントに残っています。\n\n' +
                '・マイページからお支払い手続きを行っていただければ、再びご利用いただけます。\n\n' +
                '・不動産DXツールをご利用いただいているお客様からの反響は配信されなくなります。\n\n' +
                '・期間終了時（' + headerEndDateDisplay + '）に不動産AI名刺がご利用いただけなくなります。\n\n' +
                '・即座に停止する場合は「OK」ボタンを押した後、確認画面で選択してください。\n\n\n' +
                '利用を停止しますか？（次回のご請求はございません。）';

            // Show modal confirmation
            showConfirm(headerConfirmMessage, async () => {
                // Second confirmation for immediate cancellation
                showConfirm('即座にキャンセルしますか？\n\n「OK」: 即座にキャンセル\n「キャンセル」: 期間終了時にキャンセル', async () => {
                    // User chose immediate cancellation
                    await processHeaderCancellation(true);
                }, async () => {
                    // User chose cancel at period end
                    await processHeaderCancellation(false);
                }, '即座にキャンセル');
            }, null, '利用を停止しますか？');

            // Process cancellation
            async function processHeaderCancellation(cancelImmediately) {
                headerCancelBtn.disabled = true;
                const originalText = headerCancelBtn.querySelector('span')?.textContent || 'サブスクリプションをキャンセル';
                if (headerCancelBtn.querySelector('span')) {
                    headerCancelBtn.querySelector('span').textContent = '処理中...';
                } else {
                    headerCancelBtn.textContent = '処理中...';
                }

                try {
                    const response = await fetch('../backend/api/mypage/cancel.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            cancel_immediately: cancelImmediately
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        if (typeof showSuccess === 'function') {
                            showSuccess(result.message || 'サブスクリプションをキャンセルしました', { autoClose: 5000 });
                        } else {
                            alert(result.message || 'サブスクリプションをキャンセルしました');
                        }
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        if (typeof showError === 'function') {
                            showError(result.message || 'サブスクリプションのキャンセルに失敗しました');
                        } else {
                            alert(result.message || 'サブスクリプションのキャンセルに失敗しました');
                        }
                        headerCancelBtn.disabled = false;
                        if (headerCancelBtn.querySelector('span')) {
                            headerCancelBtn.querySelector('span').textContent = originalText;
                        } else {
                            headerCancelBtn.textContent = originalText;
                        }
                    }
                } catch (error) {
                    console.error('Error canceling subscription:', error);
                    if (typeof showError === 'function') {
                        showError('エラーが発生しました');
                    } else {
                        alert('エラーが発生しました');
                    }
                    headerCancelBtn.disabled = false;
                    if (headerCancelBtn.querySelector('span')) {
                        headerCancelBtn.querySelector('span').textContent = originalText;
                    } else {
                        headerCancelBtn.textContent = originalText;
                    }
                }
            }
        });
    }

=======
>>>>>>> 60afd757e00e94a0bfb3d0271272027ccd5fcb33
    // Logout functionality
    if (logoutLink) {
        logoutLink.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                const response = await fetch('../backend/api/auth/logout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include'
                });
                const result = await response.json();
                window.location.href = 'index.php';
            } catch (error) {
                console.error('Error:', error);
                window.location.href = 'index.php';
            }
        });
    }
    
    // Mobile payment button functionality
    const mobilePaymentBtn = document.getElementById('mobile-payment-btn');
    if (mobilePaymentBtn) {
        // Show button only on mobile
        function updateMobilePaymentButtonVisibility() {
            if (window.innerWidth <= 768) {
                mobilePaymentBtn.style.display = 'inline-block';
            } else {
                mobilePaymentBtn.style.display = 'none';
            }
        }
        
        // Initial check
        updateMobilePaymentButtonVisibility();
        
        // Update on resize
        window.addEventListener('resize', updateMobilePaymentButtonVisibility);
        
        // Button click handler
        mobilePaymentBtn.addEventListener('click', function() {
            if (window.isPaymentCompleted) {
                // If payment is completed, show message or navigate to card
                if (typeof showSuccess === 'function') {
                    showSuccess('利用可能です');
                } else {
                    alert('利用可能です');
                }
            } else {
                // Navigate to payment section
                if (window.location.pathname.includes('edit.php')) {
                    // If on edit page, navigate to payment section
                    if (typeof goToEditSection === 'function') {
                        goToEditSection('payment-section');
                    } else {
                        window.location.href = 'edit.php#payment-section';
                    }
                } else {
                    // Otherwise, go to register page payment step
                    window.location.href = 'register.php?step=6';
                }
            }
        });
        
        // Function to update button appearance based on payment status
        function updateMobilePaymentButton() {
            // Check payment status - use window.isPaymentCompleted or check again
            const isCompleted = window.isPaymentCompleted === true;
            
            if (isCompleted) {
                mobilePaymentBtn.textContent = '利用可能';
                mobilePaymentBtn.classList.add('available');
                mobilePaymentBtn.style.background = '#0066cc';
                mobilePaymentBtn.style.color = '#fff';
            } else {
                mobilePaymentBtn.textContent = 'お支払いへ進む';
                mobilePaymentBtn.classList.remove('available');
                mobilePaymentBtn.style.background = '#dc3545';
                mobilePaymentBtn.style.color = '#fff';
            }
        }

        // Initial update
        updateMobilePaymentButton();

        // Also update when window.isPaymentCompleted changes (if set later)
        // Use a MutationObserver or check periodically
        let checkCount = 0;
        const checkInterval = setInterval(function() {
            if (window.isPaymentCompleted !== undefined) {
                updateMobilePaymentButton();
                checkCount++;
                // Stop checking after 5 attempts (2.5 seconds)
                if (checkCount >= 5) {
                    clearInterval(checkInterval);
                }
            }
        }, 500);
    }
})();
</script>

<!-- Mobile Menu Script -->
<script>
    // Pass subscription status to mobile menu
    window.hasActiveSubscription = <?php echo json_encode($hasActiveSubscriptionForMobile); ?>;
    // Pass payment status to JavaScript
    window.isPaymentCompleted = <?php echo json_encode($isPaymentCompleted ?? false); ?>;

    // Update mobile payment button after window.isPaymentCompleted is set
    // Use DOMContentLoaded to ensure button exists
    document.addEventListener('DOMContentLoaded', function() {
        const mobilePaymentBtn = document.getElementById('mobile-payment-btn');
        if (mobilePaymentBtn) {
            // Force update button appearance
            const isCompleted = window.isPaymentCompleted === true;
            console.log('Updating mobile payment button. isPaymentCompleted:', window.isPaymentCompleted, 'isCompleted:', isCompleted);
            
            if (isCompleted) {
                mobilePaymentBtn.textContent = '利用可能';
                mobilePaymentBtn.classList.add('available');
                mobilePaymentBtn.style.background = '#0066cc';
                mobilePaymentBtn.style.color = '#fff';
                console.log('Button updated to: 利用可能 (blue)');
            } else {
                mobilePaymentBtn.textContent = 'お支払いへ進む';
                mobilePaymentBtn.classList.remove('available');
                mobilePaymentBtn.style.background = '#dc3545';
                mobilePaymentBtn.style.color = '#fff';
                console.log('Button updated to: お支払いへ進む (red)');
            }
        }
    });
</script>
<script src="assets/js/mobile-menu.js"></script>
<!-- Modal Notification Script -->
<script src="assets/js/modal.js"></script>


