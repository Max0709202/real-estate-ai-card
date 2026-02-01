<?php
/**
 * Common Header Component
 * Include this file in all pages that need the header navigation
 *
 * Usage:
 *   $showNavLinks = true; // Set to false to hide navigation links (default: true)
 *   include __DIR__ . '/includes/header.php';
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/includes/functions.php';

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

if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../backend/config/database.php';
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
            SELECT bc.url_slug, bc.qr_code_issued, p.payment_status
            FROM business_cards bc
            LEFT JOIN payments p ON bc.id = p.business_card_id AND p.payment_status = 'completed'
            WHERE bc.user_id = ?
            ORDER BY p.paid_at DESC
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $cardData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cardData && $cardData['qr_code_issued'] && $cardData['payment_status'] === 'completed') {
            $showMyCard = true;
            $cardSlug = $cardData['url_slug'];
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
<!-- Modal Notification Script (load early so functions are available) -->
<script src="assets/js/modal.js"></script>

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

                <?php if ($isLoggedIn): ?>
                        <?php
                // Initialize header user info variables
                $headerUserName = null;
                $headerProfilePhoto = null;
                $headerUsagePeriodDisplay = null;
                        $headerSubscriptionInfo = null;
                        $headerHasActiveSubscription = false;

                // Get user info for header display
                            try {
                                require_once __DIR__ . '/../backend/config/database.php';
                                $database = new Database();
                                $db = $database->getConnection();

                    // Get user name and profile photo from business_cards
                    $stmt = $db->prepare("
                        SELECT name, profile_photo FROM business_cards WHERE user_id = ? LIMIT 1
                    ");
                    $stmt->execute([$_SESSION['user_id']]);
                    $bcData = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($bcData && !empty($bcData['name'])) {
                        $headerUserName = $bcData['name'];
                    }
                    $headerProfilePhoto = $bcData['profile_photo'] ?? null;

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
                            // End date is the day before next billing date (last day of current period)
                                        $nextBilling = new DateTime($headerSubscriptionInfo['next_billing_date']);
                                        $nextBilling->modify('-1 day');
                                        $headerEndDate = $nextBilling->format('Y年n月j日');
                                    } elseif ($headerSubscriptionInfo['cancelled_at']) {
                            // If already cancelled, use cancelled_at date
                                        $cancelled = new DateTime($headerSubscriptionInfo['cancelled_at']);
                                        $headerEndDate = $cancelled->format('Y年n月j日');
                                    }
                                }

                    // If subscription exists but no end date calculated yet, or subscription doesn't exist, try to get from payment date
                    if (!$headerEndDate) {
                        // Get business card info and payment status
                        $stmt = $db->prepare("
                            SELECT bc.payment_status, bc.updated_at, bc.created_at
                            FROM business_cards bc
                            WHERE bc.user_id = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$_SESSION['user_id']]);
                        $bcInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($bcInfo && in_array($bcInfo['payment_status'], ['CR', 'BANK_PAID', 'ST'])) {
                            // Get the most recent completed payment date
                            $stmt = $db->prepare("
                                SELECT MAX(p.paid_at) as last_paid_at
                                FROM payments p
                                INNER JOIN business_cards bc ON p.business_card_id = bc.id
                                WHERE bc.user_id = ? AND p.payment_status = 'completed' AND p.paid_at IS NOT NULL
                            ");
                            $stmt->execute([$_SESSION['user_id']]);
                            $paymentInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($paymentInfo && $paymentInfo['last_paid_at']) {
                                // Calculate end date: 1 month from payment date, minus 1 day (last day of period)
                                $paidDate = new DateTime($paymentInfo['last_paid_at']);
                                $paidDate->modify('+1 month');
                                $paidDate->modify('-1 day');
                                $headerEndDate = $paidDate->format('Y年n月j日');
                            } elseif (isset($bcInfo['updated_at']) && !empty($bcInfo['updated_at'])) {
                                // If no paid_at but payment_status is CR/BANK_PAID, use business_card updated_at
                                // This handles cases where payment was completed but paid_at wasn't set
                                try {
                                    $updatedDate = new DateTime($bcInfo['updated_at']);
                                    $updatedDate->modify('+1 month');
                                    $updatedDate->modify('-1 day');
                                    $headerEndDate = $updatedDate->format('Y年n月j日');
                                } catch (Exception $dateException) {
                                    error_log("Error parsing updated_at date: " . $dateException->getMessage());
                                }
                            }
                        }

                        // If still no date and subscription exists, try subscription created_at
                        if (!$headerEndDate && $headerSubscriptionInfo && isset($headerSubscriptionInfo['status']) && in_array($headerSubscriptionInfo['status'], ['active', 'trialing'])) {
                            if (isset($headerSubscriptionInfo['id'])) {
                                $stmt = $db->prepare("SELECT created_at FROM subscriptions WHERE id = ?");
                                $stmt->execute([$headerSubscriptionInfo['id']]);
                                $subData = $stmt->fetch(PDO::FETCH_ASSOC);

                                if ($subData && $subData['created_at']) {
                                    $subCreated = new DateTime($subData['created_at']);
                                    $subCreated->modify('+1 month');
                                    $subCreated->modify('-1 day');
                                    $headerEndDate = $subCreated->format('Y年n月j日');
                                }
                            }
                        }

                        // Fallback if still no date: current date + 1 month - 1 day (only if payment is completed)
                        if (!$headerEndDate && isset($bcInfo) && $bcInfo && in_array($bcInfo['payment_status'], ['CR', 'BANK_PAID'])) {
                            $now = new DateTime();
                            $now->modify('+1 month');
                            $now->modify('-1 day');
                            $headerEndDate = $now->format('Y年n月j日');
                        }
                    }

                    // Get payment method and calculate usage period display for header
                    $headerPaymentStatus = $headerSubscriptionInfo['payment_status'] ?? null;
                    if (!$headerPaymentStatus) {
                        $stmt = $db->prepare("
                            SELECT payment_status FROM business_cards WHERE user_id = ? LIMIT 1
                        ");
                        $stmt->execute([$_SESSION['user_id']]);
                        $bcPaymentStatus = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($bcPaymentStatus) {
                            $headerPaymentStatus = $bcPaymentStatus['payment_status'];
                        }
                    }

                    if (in_array($headerPaymentStatus, ['CR', 'BANK_PAID', 'ST'])) {
                        // Get most recent payment method
                        $stmt = $db->prepare("
                            SELECT payment_method
                            FROM payments
                            WHERE user_id = ? AND payment_status = 'completed'
                            ORDER BY paid_at DESC, created_at DESC
                            LIMIT 1
                        ");
                        $stmt->execute([$_SESSION['user_id']]);
                        $paymentMethodData = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($paymentMethodData) {
                            $headerPaymentMethod = $paymentMethodData['payment_method'];

                            if ($headerPaymentMethod === 'bank_transfer') {
                                // Bank transfer: show expiration date
                                if ($headerEndDate) {
                                    $headerUsagePeriodDisplay = $headerEndDate . '迄';
                                } else {
                                    $headerUsagePeriodDisplay = '期限未設定';
                                }
                            } else {
                                // Credit card: show "クレジットお支払い"
                                $headerUsagePeriodDisplay = 'クレジットお支払い';
                            }
                        } else {
                            // Payment status is CR/BANK_PAID but no payment record found
                            if ($headerPaymentStatus === 'BANK_PAID') {
                                if ($headerEndDate) {
                                    $headerUsagePeriodDisplay = $headerEndDate . '迄';
                                } else {
                                    $headerUsagePeriodDisplay = '期限未設定';
                                }
                            } else {
                                // Credit card
                                $headerUsagePeriodDisplay = 'クレジットお支払い';
                            }
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
                        if ($cardInfo && in_array($cardInfo['payment_status'], ['CR', 'BANK_PAID', 'ST']) && $cardInfo['user_type'] === 'new') {
                                        $headerHasActiveSubscription = true;
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("Header subscription check error: " . $e->getMessage());
                            }
                ?>
                <!-- User Menu (Person Icon with Dropdown) -->
                <div class="user-menu">
                    <div class="user-info-container">
                        <div class="user-icon" id="user-icon">
                            <?php if (!empty($headerProfilePhoto)): ?>
                                <?php
                                $photoPath = trim($headerProfilePhoto);
                                // Add BASE_URL if path doesn't start with http
                                if (!empty($photoPath) && !preg_match('/^https?:\/\//', $photoPath)) {
                                    // Remove BASE_URL if already included to avoid duplication
                                    $baseUrlPattern = preg_quote(BASE_URL, '/');
                                    if (preg_match('/^' . $baseUrlPattern . '/i', $photoPath)) {
                                        // Already contains BASE_URL, use as is
                                    } else {
                                        $photoPath = BASE_URL . '/' . ltrim($photoPath, '/');
                                    }
                                }
                                ?>
                                <img src="<?php echo htmlspecialchars($photoPath); ?>" alt="プロフィール写真" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;" onerror="this.style.display='none'; this.parentElement.innerHTML='<svg width=\'24\' height=\'24\' viewBox=\'0 0 24 24\' fill=\'none\' xmlns=\'http://www.w3.org/2000/svg\'><path d=\'M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z\' stroke=\'#1976d2\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' fill=\'white\'/><path d=\'M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22\' stroke=\'#1976d2\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' fill=\'white\'/></svg>';">
                            <?php else: ?>
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="#1976d2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="white"/>
                                    <path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="#1976d2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="white"/>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <?php if ($headerUserName): ?>
                        <div class="user-info-text">
                            <div class="user-name">
                                <?php echo htmlspecialchars($headerUserName); ?>様
                            </div>
                            <?php if ($headerUsagePeriodDisplay): ?>
                            <div class="user-period">
                                <?php echo htmlspecialchars($headerUsagePeriodDisplay); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="user-dropdown" id="user-dropdown">
                        <?php if ($showMyCard): ?>
                        <a href="card.php?slug=<?php echo htmlspecialchars($cardSlug); ?>" class="dropdown-item" target="_blank">
                            <span>マイ名刺</span>
                        </a>
                        <?php endif; ?>
                        <a href="edit.php" class="dropdown-item">
                            <span>マイページ</span>
                        </a>
                        <?php if ($headerHasActiveSubscription): ?>
                        <!-- <div class="dropdown-divider"></div> -->
                        <!-- <div class="dropdown-section-header">サブスクリプション</div> -->
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
                        <button type="button" class="dropdown-item dropdown-button cancel-subscription-btn" style="font-weight: normal;">
                            <span>利用停止</span>
                        </button>
                        <?php endif; ?>
                        <!-- <div class="dropdown-divider"></div> -->
                        <a href="register.php?step=6" class="dropdown-item" id="payment-list-link">
                            <span>お支払い画面</span>
                        </a>
                        <a href="auth/reset-email.php" class="dropdown-item">
                            <span>メールアドレスリセット</span>
                        </a>
                        <a href="auth/forgot-password.php" class="dropdown-item">
                            <span>パスワードリセット</span>
                        </a>
                        <a href="#" id="logout-link" class="dropdown-item logout-link">
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
(function() {
  function initHeaderScripts() {
    const userIcon = document.getElementById('user-icon');
    const userMenu = document.querySelector('.user-menu');

    // Toggle dropdown on click
    if (userIcon && userMenu) {
      userIcon.addEventListener('click', function(e) {
        e.stopPropagation();
        userMenu.classList.toggle('active');
      });

      document.addEventListener('click', function(e) {
        if (!userMenu.contains(e.target)) {
          userMenu.classList.remove('active');
        }
      });
    }

    // Get all cancel subscription buttons by class (handles multiple buttons with same class)
    const cancelSubscriptionBtns = document.querySelectorAll('.cancel-subscription-btn');

    async function processHeaderCancellation(cancelBtn, cancelImmediately) {
      if (!cancelBtn) return;

      cancelBtn.disabled = true;

      const span = cancelBtn.querySelector('span');
      const originalText = span?.textContent || cancelBtn.textContent || '利用停止';

      if (span) span.textContent = '処理中...';
      else cancelBtn.textContent = '処理中...';

      try {
        const apiUrl = window.location.origin + '/php/backend/api/mypage/cancel.php';
        const response = await fetch(apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ cancel_immediately: cancelImmediately })
        });

        const result = await response.json();

        if (result.success) {
          if (typeof window.showSuccess === 'function') {
            window.showSuccess(result.message || '利用停止しました', { autoClose: 5000 });
          } else {
            alert(result.message || '利用停止しました');
          }
          setTimeout(() => window.location.reload(), 2000);
        } else {
          if (typeof window.showError === 'function') {
            window.showError(result.message || 'サブスクリプションのキャンセルに失敗しました');
          } else {
            alert(result.message || 'サブスクリプションのキャンセルに失敗しました');
          }

          cancelBtn.disabled = false;
          if (span) span.textContent = originalText;
          else cancelBtn.textContent = originalText;
        }
      } catch (error) {
        console.error('Error canceling subscription:', error);

        if (typeof window.showError === 'function') window.showError('エラーが発生しました');
        else alert('エラーが発生しました');

        cancelBtn.disabled = false;
        if (span) span.textContent = originalText;
        else cancelBtn.textContent = originalText;
      }
    }

    // Add event listeners to all cancel subscription buttons
    if (cancelSubscriptionBtns.length > 0) {
      cancelSubscriptionBtns.forEach(function(cancelBtn) {
        cancelBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          console.log('clicked');


         // ✅ Always use window.showConfirm (global) safely
         const showConfirmFn = (typeof window.showConfirm === 'function')
           ? window.showConfirm
           : (typeof showConfirm === 'function' ? showConfirm : null);

        const headerEndDateText = <?php echo json_encode($headerEndDate ?? '未設定'); ?>;
        const headerEndDateDisplay = headerEndDateText !== '未設定' ? headerEndDateText : '（未設定）';

        const headerConfirmMessage =
          '・停止されても、マイページで作って頂いたAI名刺はアカウントに残っています。\n\n' +
          '・マイページからお支払い手続きを行っていただければ、再びご利用いただけます。\n\n' +
          '・不動産DXツールをご利用いただいているお客様からの反響は配信されなくなります。\n\n' +
          '・期間終了時（' + headerEndDateDisplay + '）に不動産AI名刺がご利用いただけなくなります。\n\n' +
          '利用を停止しますか？（次回のご請求はございません。）';

        // ✅ If showConfirm is missing, fallback to native confirm so it still works
        if (!showConfirmFn) {
          console.error('showConfirm is not available (not global / different name / overwritten). Using fallback confirm().');

          const ok1 = confirm(headerConfirmMessage);
          if (!ok1) return;

          const ok2 = confirm('即座にキャンセルしますか？\n\nOK: 即座にキャンセル\nキャンセル: 期間終了時にキャンセル');
          processHeaderCancellation(cancelBtn, ok2);
          return;
        }

        // ✅ Normal modal flow (利用停止関連のため確認ボタンは「利用停止する」)
        // 1st modal: Cancel = abort. 2nd modal: キャンセル = abort, 期間終了後 = secondary, 即座 = confirm
        showConfirmFn(headerConfirmMessage, async () => {
          showConfirmFn(
            '即座に利用停止しますか？\n\n「即座」：即座にキャンセル\n「期間終了後」：期間終了後にキャンセル\n「キャンセル」：利用停止をキャンセル',
            async () => { await processHeaderCancellation(cancelBtn, true); },
            null,
            '即座に利用停止',
            {
              confirmButtonText: '即座',
              secondaryButtonText: '期間終了後',
              onSecondary: async () => { await processHeaderCancellation(cancelBtn, false); },
              cancelButtonText: 'キャンセル'
            }
          );
        }, null, '利用を停止しますか？', { confirmButtonText: '利用停止する' });
        });
      });
    } else {
      console.warn('cancelSubscriptionBtns not found in DOM (.cancel-subscription-btn)');
    }

    // Logout - use event delegation so both desktop and mobile menu (cloned) links work
    const logoutApiUrl = <?php echo json_encode(rtrim(BASE_URL, '/') . '/backend/api/auth/logout.php'); ?>;
    const logoutRedirectUrl = <?php echo json_encode(rtrim(BASE_URL, '/') . '/index.php'); ?>;
    document.addEventListener('click', async function(e) {
      const link = e.target.closest('.logout-link, #logout-link');
      if (!link) return;
      e.preventDefault();
      try {
        await fetch(logoutApiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include'
        });
      } catch (err) {
        console.error('Logout fetch error:', err);
      }
      window.location.href = logoutRedirectUrl;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHeaderScripts);
  } else {
    initHeaderScripts();
  }
})();
</script>



<!-- Mobile Menu Script -->
<script src="assets/js/mobile-menu.js"></script>


