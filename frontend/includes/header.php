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
                <!-- User Menu (Person Icon with Dropdown) -->
                <div class="user-menu">
                    <div class="user-icon" id="user-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
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
                        <button type="button" class="dropdown-item dropdown-button cancel-subscription-btn" style="color: #dc3545; cursor: pointer; width: 100%; text-align: left; background: none; border: none; padding: 0.75rem 1.25rem;">
                            <span>サブスクリプションをキャンセル</span>
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
                        <a href="#" id="logout-link" class="dropdown-item">
                            <span>ログアウト</span>
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <!-- Login Button (when not logged in) -->
                <a href="login.php" class="btn-secondary">ログイン</a>
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
    const logoutLink = document.getElementById('logout-link');

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
      const originalText = span?.textContent || cancelBtn.textContent || 'サブスクリプションをキャンセル';

      if (span) span.textContent = '処理中...';
      else cancelBtn.textContent = '処理中...';

      try {
        const response = await fetch('../backend/api/mypage/cancel.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ cancel_immediately: cancelImmediately })
        });

        const result = await response.json();

        if (result.success) {
          if (typeof window.showSuccess === 'function') {
            window.showSuccess(result.message || 'サブスクリプションをキャンセルしました', { autoClose: 5000 });
          } else {
            alert(result.message || 'サブスクリプションをキャンセルしました');
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
          '・即座に停止する場合は「OK」ボタンを押した後、確認画面で選択してください。\n\n\n' +
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

        // ✅ Normal modal flow
        showConfirmFn(headerConfirmMessage, async () => {
          showConfirmFn(
            '即座にキャンセルしますか？\n\n「OK」: 即座にキャンセル\n「キャンセル」: 期間終了時にキャンセル',
            async () => { await processHeaderCancellation(cancelBtn, true); },
            async () => { await processHeaderCancellation(cancelBtn, false); },
            '即座にキャンセル'
          );
        }, null, '利用を停止しますか？');
        });
      });
    } else {
      console.warn('cancelSubscriptionBtns not found in DOM (.cancel-subscription-btn)');
    }

    // Logout
    if (logoutLink) {
      logoutLink.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
          await fetch('../backend/api/auth/logout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include'
          });
          window.location.href = 'index.php';
        } catch (error) {
          console.error('Error:', error);
          window.location.href = 'index.php';
        }
      });
    }
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


