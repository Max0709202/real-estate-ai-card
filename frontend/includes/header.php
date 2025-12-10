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
if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../../backend/config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        
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
                    <div class="user-dropdown" id="user-dropdown">
                        <?php if ($showMyCard): ?>
                        <a href="card.php?slug=<?php echo htmlspecialchars($cardSlug); ?>" class="dropdown-item" target="_blank">
                            <span>マイ名刺</span>
                        </a>
                        <?php endif; ?>
                        <a href="edit.php" class="dropdown-item">
                            <span>マイページ</span>
                        </a>
                        <a href="auth/reset-email.php" class="dropdown-item mobile-only-dropdown">
                            <span>メールアドレスリセット</span>
                        </a>
                        <a href="auth/forgot-password.php" class="dropdown-item mobile-only-dropdown">
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
                <a href="register.php?type=new" class="btn-primary">今すぐ始める</a>
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
})();
</script>

<!-- Mobile Menu Script -->
<script src="assets/js/mobile-menu.js"></script>
<!-- Modal Notification Script -->
<script src="assets/js/modal.js"></script>


