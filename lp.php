<?php
/**
 * Landing Page - New Users
 * Handles token-based redirects for existing/free users
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/includes/functions.php';

startSessionIfNotStarted();

// Handle token-based access for existing users
$invitationToken = $_GET['token'] ?? '';
$isTokenBased    = !empty($invitationToken);
$tokenValid      = false;
$tokenData       = null;
// Get userType from URL parameter first, default to 'new'
$userType = $_GET['type'] ?? 'new';

// --- IPアドレスから既存ユーザー（含ERA）かどうかを判定 ---
// トップページにパラメータ無しでアクセスした場合、
// 過去に招待リンク経由でアクセスした端末(IP)であれば
// 自動的に index.php?type=existing へ振り分ける
try {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!$isTokenBased && (empty($_GET['type']) || $_GET['type'] === 'new') && !empty($clientIp)) {
        require_once __DIR__ . '/backend/config/database.php';
        $database = new Database();
        $db       = $database->getConnection();

        $stmt = $db->prepare("
            SELECT u.user_type, u.is_era_member
            FROM existing_user_ips e
            JOIN users u ON e.user_id = u.id
            WHERE e.ip_address = ?
            ORDER BY e.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$clientIp]);
        $ipUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ipUser && $ipUser['user_type'] === 'existing') {
            // Remembered existing/ERA device → redirect with type=existing
            header('Location: index.php?type=existing');
            exit;
        }
    }
} catch (Exception $e) {
    error_log('Existing-user IP detection failed in index.php: ' . $e->getMessage());
}

if ($isTokenBased) {
    // Validate token (but don't redirect - stay on index.php)
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
                $tokenData = $result['data'];
                // Use token's role_type if available and it is 'existing'
                if (($tokenData['role_type'] ?? null) === 'existing') {
                    $userType = $tokenData['role_type'];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Token validation error in index.php: " . $e->getMessage());
    }
}
?> 
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <?php if ($isTokenBased): ?>
    <!-- Prevent search engine indexing for token-based pages -->
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow">
    <?php endif; ?>
    <title>不動産AI名刺 - 商談機会を逃さない</title>
    <!-- Google Fonts - Noto Sans JP -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/lp.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <link rel="stylesheet" href="assets/css/new_lp.css">
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="lp-main">
        <!-- Section 1: Hero with tagline, headline, video placeholder -->
        <section class="new-lp-sec-1" id="new-lp-sec-1">
            <div class="new-lp-sec-1-inner">
                <div class="new-lp-sec-1-left">
                    <div class="new-lp-sec-1-tagline-wrap">
                        <span class="new-lp-sec-1-tagline-bracket new-lp-sec-1-tl"></span>
                        <span class="new-lp-sec-1-tagline-bracket new-lp-sec-1-tr"></span>
                        <span class="new-lp-sec-1-tagline-bracket new-lp-sec-1-bl"></span>
                        <span class="new-lp-sec-1-tagline-bracket new-lp-sec-1-br"></span>
                        <p class="new-lp-sec-1-tagline">一歩先を伴走する</p>
                    </div>
                    <h1 class="new-lp-sec-1-title">
                        <span class="new-lp-sec-1-title-outline">頼られる</span><span class="new-lp-sec-1-title-solid">AI名刺</span>
                    </h1>
                    <p class="new-lp-sec-1-sub">顧客に選ばれる私に</p>
                </div>
                <div class="new-lp-sec-1-right">
                    <div class="new-lp-sec-1-video-wrap">
                        <span class="new-lp-sec-1-video-label">動画</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Section 2: 連絡する営業 から 連絡が来る営業へ -->
        <section class="new-lp-sec-2" id="new-lp-sec-2">
            <div class="new-lp-sec-2-inner">
                <h2 class="new-lp-sec-2-title">
                    <span class="new-lp-sec-2-title-box">連絡する営業</span> から <span class="new-lp-sec-2-title-box"><span style="color: #0066CC;">連絡が来る</span>営業</span>へ
                </h2>
                <div class="new-lp-sec-2-body">
                    <p>成果を出し続ける営業は何が違うのでしょうか。</p>
                    <p>一方的に追い続ける営業は、<br>選ばれ続ける存在にはなれません。</p>
                    <p>情報提供のスピードと質を武器に、<br><span class="new-lp-sec-2-highlight">「今、知りたい」</span>情報を確実に早く届ける。</p>
                    <p>だから、<br>お客様のほうから連絡が来る。</p>
                    <p><span class="new-lp-sec-2-highlight">一歩先を伴走する。</span><br><span class="new-lp-sec-2-highlight">「頼られる営業」</span>を、仕組みで。</p>
                </div>
            </div>
        </section>

        <!-- Section 3: 不動産AI名刺とは -->
        <section class="new-lp-sec-3" id="new-lp-sec-3">
            <div class="new-lp-sec-3-inner">
                <div class="new-lp-sec-3-header">
                    <div class="new-lp-sec-3-label-row">
                        <img src="assets/images/logo-1.png" alt="DXツール付き 不動産AI名刺" class="new-lp-sec-3-logo">
                    </div>
                    <p class="new-lp-sec-3-subheading">「名刺」+「営業支援ツール」+「業務 DX 機能」が一体になった</p>
                    <p class="new-lp-sec-3-tagline">“次世代型の営業起点ツール”</p>
                </div>

                <div class="new-lp-sec-3-card">
                    <span class="new-lp-sec-1-tagline-bracket new-lp-sec-2-tl"></span>
                    <span class="new-lp-sec-1-tagline-bracket new-lp-sec-2-tr"></span>
                    <span class="new-lp-sec-1-tagline-bracket new-lp-sec-2-bl"></span>
                    <span class="new-lp-sec-1-tagline-bracket new-lp-sec-2-br"></span>
                    <div class="new-lp-sec-3-title">
                        <p>AI・ロボット・ビッグデータで不動産営業のDX化を強力にサポート！</p>
                    </div>
                    <div class="new-lp-sec-3-card-inner">
                        <div class="new-lp-sec-3-left">
                            <h3 class="new-lp-sec-3-left-title">名刺から、営業データが<br class="only-pc">一気につながる。</h3>
                            <p>例えば、<span class="new-lp-sec-3-strong">450万件以上</span>の過去の販売履歴。<br>毎日<span class="new-lp-sec-3-strong">6,000件以上</span>の新規売り出し情報。</p>
                            <p>物件の良し悪しを瞬時に判断し、<br>「<span class="new-lp-sec-3-strong">今日の価格</span>」と「<span class="new-lp-sec-3-strong">本当に欲しい人</span>」が分かる。</p>
                            <p>QRコードを読み込むだけで、<br>ビッグデータを、誰でも直感的に扱える。</p>
                            <p>だから、お客様が「<span class="new-lp-sec-3-strong">あなたを選ぶ</span>」ようになる。</p>
                            <p class="new-lp-sec-3-left-footer">それが、不動産AI名刺です。</p>
                        </div>
                        <div class="new-lp-sec-3-right">
                            <div class="new-lp-sec-3-phone-frame">
                                <img src="assets/images/new_LP/image 58.png" alt="営業データを表示するスマートフォン" class="new-lp-sec-3-phone-img">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Section 4: 米国不動産業界におけるデジタル名刺の背景 -->
        <section class="new-lp-sec-4" id="new-lp-sec-4">
            <div class="new-lp-sec-4-inner">
                <h2 class="new-lp-sec-4-title">
                    米国不動産業界におけるデジタル名刺（NFC/QR）の<br class="only-pc">急速な普及とその背景
                </h2>

                <div class="new-lp-sec-4-main">
                    <div class="new-lp-sec-4-left">
                        <div class="new-lp-sec-4-row">
                            <div class="new-lp-sec-4-card">
                                <div class="new-lp-sec-4-card-title">
                                    非対面・非接触<br>
                                    ニーズの高まり
                                </div>
                            </div>
                            <p class="new-lp-sec-4-text">
                                新型コロナウイルス感染症（COVID-19）の流行以降、<br>
                                対面での名刺交換を避け、スマートフォンのタップや<br>
                                QRスキャンで安全に情報を共有する形式が定着しました。
                            </p>
                        </div>

                        <div class="new-lp-sec-4-row">
                            <div class="new-lp-sec-4-card">
                                <div class="new-lp-sec-4-card-title">
                                    サステナビリティ<br>
                                    （環境意識）の向上
                                </div>
                            </div>
                            <p class="new-lp-sec-4-text">
                                不動産業界は持続可能性を重視し、紙の消費を減らす<br>
                                ペーパーレス化（エコフレンドリー）への転換が<br>
                                進んでいます。
                            </p>
                        </div>

                        <div class="new-lp-sec-4-row">
                            <div class="new-lp-sec-4-card">
                                <div class="new-lp-sec-4-card-title">
                                    DX（デジタルトランス<br>
                                    フォーメーション）の加速
                                </div>
                            </div>
                            <p class="new-lp-sec-4-text">
                                特に米国ではエージェント個々が独立した起業家として<br>
                                動いており、競合他社との差別化のためにテックツールを<br>
                                駆使して業務効率を最大化する動きが強くなっています。
                            </p>
                        </div>
                    </div>

                    <div class="new-lp-sec-4-right">
                        <div class="new-lp-sec-4-right-bottom">
                            <p class="new-lp-sec-4-bottom-text">
                                米国不動産市場において、デジタル名刺は<br>
                                「<span class="new-lp-sec-4-strong">持っていると便利</span>」なツールから<br>
                                「<span class="new-lp-sec-4-strong">エージェントとして必須</span>」のツールへと完全に主流化しています。
                            </p>
                            <div class="new-lp-sec-4-illustration-wrap">
                                <img src="assets/images/new_LP/us%201.png" alt="デジタル名刺を活用する米国エージェント" class="new-lp-sec-4-illustration">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="footer-background-text">
            <span>REAL ESTATE</span> 
            <span>AI</span>
        </div>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4 class="footer-logo">不動産AI名刺</h4>
                    <p class="footer-description">次世代のAI名刺で、営業力を最大化</p>
                </div>
                
                <div class="footer-section">
                    <h4>サービス</h4>
                    <ul>
                        <li><a href="#concept">不動産AI名刺とは</a></li>
                        <li><a href="#features">特徴</a></li>
                        <li><a href="#pricing">料金</a></li>
                        <li><a href="contact.php">お問い合わせ</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4>サポート</h4>
                    <ul class="social-links">
                        <li>
                            <a href="contact.php" class="social-link">
                                <span>お問い合わせ</span>
                            </a>
                        </li>
                        <li>
                            <a href="terms.php" class="social-link">
                                <span>利用規約</span>
                            </a>
                        </li>
                        <li>
                            <a href="privacy.php" class="social-link">
                                <span>プライバシーポリシー</span>
                            </a>
                        </li>
                        <li>
                            <a href="company.php" class="social-link">
                                <span>会社概要</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4>不動産エージェントの管理画面</h4>
                    <a href="./admin/dashboard.php" class="save-btn footer-save-btn">名刺情報の保存ボタン</a>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="footer-bottom-content">
                    <p>&copy; 2025 リニュアル仲介株式会社. All rights reserved.</p>
                    <p><a href="specific.php">特定商取引法に基づく表記</a></p>
                </div>
            </div>
        </div>
    </footer>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/mobile-menu.js"></script>
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/new_lp.js"></script>
    <?php if ($isTokenBased && !$tokenValid): ?>
    <script>
        alert('無効な招待リンクです。管理者にお問い合わせください。');
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 2000);
    </script>
    <?php endif; ?>
</body>
</html>
