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
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>不動産AI名刺 - 商談機会を逃さない</title>
    <!-- Google Fonts - Noto Sans JP -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="assets/css/lp.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>
    
    <!-- First View Section -->
    <section class="first-view">
        <div class="first-view-inner">
            <div class="first-view-image-wrap">
                <img src="assets/images/hero.jpg" alt="不動産AI名刺のイメージ" class="first-view-image">
            </div>
            <div class="first-view-overlay">
                <div class="first-view-top" id="first-view-top">
                    <p class="fv-line">莫大なデータの中からお客様の最適解を自動提案。</p>
                    <p class="fv-line">お客様が必要な情報をワンストップで提供可能。</p>
                    <p class="fv-line">成約が多くて忙しい営業の方こそ使ってほしい。</p>
                </div>
                <div class="first-view-main">
                    <div class="first-view-copy">
                        <p class="first-view-tagline">不動産関連の</p>
                        <h1 class="first-view-title">
                            <span class="text-red">出会い</span>を<span class="text-red">売上</span>に<br>
                            変える！
                        </h1>
                    </div>
                    <div class="first-view-right">
                        <img src="assets/images/hero-right.png" alt="不動産エージェントのイメージ" class="first-view-agent-image">
                    </div>
                </div>
                <div class="first-view-bottom-text">
                    <p class="first-view-bottom-caption">
                        ～<span class="text-red">失注率低減</span>と<span class="text-red">コミュニケーション活性化</span>による営業効果最大化～
                    </p>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Hero Slider Section -->
    <section class="hero-slider">
        <div class="hero-swiper-container">
            <div class="swiper hero-swiper">
                <div class="swiper-wrapper">
                    <!-- Slide 1 -->
                    <div class="swiper-slide hero-slide">
                        <div class="container">
                            <div class="hero-slide-content">
                                <div class="hero-slide-left">
                                    <div class="hero-badge">不動産エージェント向け</div>
                                    <h1 class="hero-title">
                                        <span class="text-blue">簡単に</span>つながり<br>
                                        顧客に<span class="text-blue">選ばれる</span><br>
                                        不思議な<span class="text-blue">AI名刺</span>
                                    </h1>
                                    <p class="hero-description">
                                        今お使いの名刺を強力にパワーアップ。営業が圧倒的に楽になる。<br>
                                        ワンタップで顧客とつながり、ロボットが顧客対応を自動化。
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Slide 2 -->
                    <div class="swiper-slide hero-slide">
                        <div class="container">
                            <div class="hero-slide-content">
                                <div class="hero-slide-left">
                                    <div class="hero-badge">不動産エージェント向け</div>
                                    <h1 class="hero-title">
                                        <span class="text-blue">デジタル名刺</span>とは<br>
                                        ずいぶん<span class="text-blue">違う</span><br>
                                        頼られる<span class="text-blue">AI名刺</span>
                                    </h1>
                                    <p class="hero-description">
                                        普通の名刺が、6種類の不動産DX機能を搭載した名刺に変身。<br>
                                        顧客が必要な情報発信で、顧客からの問い合わせが増える。
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Slide 3 -->
                    <div class="swiper-slide hero-slide">
                        <div class="container">
                            <div class="hero-slide-content">
                                <div class="hero-slide-left">
                                    <div class="hero-badge">不動産エージェント向け</div>
                                    <h1 class="hero-title">
                                        <span class="text-blue">楽</span>になる、仕事。<br>
                                        <span class="text-blue">楽に</span>なる、提案。<br>
                                        <span class="text-blue">楽になる</span>、つながり。
                                    </h1>
                                    <p class="hero-description">
                                        月間15万件以上の新着不動産情報から、お客様の希望の物件情報が<br>
                                        毎日自動で配信。お客様へのアクセス頻度が増え成約率がUP。
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Pagination -->
                <div class="swiper-pagination"></div>
            </div>
            <!-- <div class="hero-cta">
                <a href="#features" class="btn-primary btn-large">不動産AI名刺を作ってみる</a> -->
                <!-- <span class="hero-cta-text">※3分で完成</span> -->
            <!-- </div> -->
        </div>

        <div class="hero-slide-right">
            <!-- <img src="assets/images/first.png" alt="効率化" class="hero-slide-image"> -->
            <div class="container">
                <!-- <h2 style="margin-bottom: 1rem;">有効的な活用方法が簡単に理解できる</h2> -->
                <div class="video-container">
                    <div class="video-wrapper">
                        <video class="service-video" controls loop autoplay muted>
                            <source src="assets/video/card.mp4" type="video/mp4" id="concept">
                        </video>
                    </div>
                </div>
            </div>
        </div>

    </section>



    <!-- DX Tool Integration Section -->
    <section class="dx-integration-section">
        <div class="container">
            <div class="dx-integration-content">
                <div class="dx-tagline">
                    「名刺」+「営業支援ツール」+「業務 DX 機能」が一体になった
                </div>
                <h2 class="dx-main-title">"次世代型の営業起点ツール"</h2>
                <p class="dx-subtitle">
                    AI・ロボット・ビッグデータで不動産営業のDX化を強力にサポート！
                </p>
                <p class="dx-subtitle-text">
                    例えば、４５０万件以上の過去の販売履歴や毎日６０００件以上の新規売り出し情報。
                    そして、
                    物件の良し悪しが一瞬で判定でき、
                    今日の価格と欲しい人が分かる。
                    QRコードを読み込むだけで、
                    ビッグデータを自由に操る。<br>
                    お客様が私を選ぶようになる。
                    それが不動産AI名刺です。

                </p>
                <div class="dx-product-info">
                    <div class="dx-logo-wrapper">
                        <img src="assets/images/logo-1.png" alt="不動産AI名刺 ロゴ" class="dx-logo" loading="lazy">
                    </div>
                    <!-- <div class="dx-product-name-wrapper">
                        <span class="dx-tool-label">DXツール付き</span>
                        <h3 class="dx-product-name">
                            不動産<span class="dx-ai-highlight">AI</span>名刺
                        </h3>
                    </div> -->
                </div>
            </div>
        </div>
    </section>

    
    <!-- Problems Section -->
    <section class="problems-section">
        <div class="problems-inner">
            <div class="problems-content">
                <h2 class="problems-title">こんなお悩みありませんか?</h2>
                <ul class="problems-list">
                    <li class="problem-item">
                        <div class="problem-text">
                            <h3 class="problem-title">①名刺交換後、連絡が途絶える</h3>
                            <p class="problem-description">せっかく出会った見込み客と、<br> その後のコミュニケーションが取れない</p>
                        </div>
                    </li>
                    <li class="problem-item">
                        <div class="problem-text">
                            <h3 class="problem-title">②他社との差別化ができない</h3>
                            <p class="problem-description">競合エージェントとの違いを明確に示せず、<br>選ばれる理由が弱い</p>
                        </div>
                    </li>
                    <li class="problem-item">
                        <div class="problem-text">
                            <h3 class="problem-title">③顧客への情報提供が不十分</h3>
                            <p class="problem-description">物件情報や市場データを効果的に共有できず、<br>信頼構築に時間がかかる</p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Three Steps Section -->
    <section class="steps-section">
        <div class="container">
            <div class="steps-grid">
                <div class="step-card">
                    <span class="step-badge">1</span>
                    <div class="step-image">
                        <img src="assets/images/card-QR.png" alt="QRコード" loading="lazy">
                    </div>
                    <h3 class="step-title">QRコードをかざすだけ</h3>
                    <p class="step-desc">名刺にQRコードを印刷したり、携帯でQRコードを表示させ、お客様のカメラで読み込んでもらうだけ。</p>
                    <p class="step-desc">お客様へ必要なツールの提供が始まり、コミュニケーションツールで、より確実な連絡手段を確立します。</p>
                </div>
                <div class="step-card">
                    <span class="step-badge">2</span>
                    <div class="step-image">
                        <img src="assets/images/news.png" alt="最新情報" loading="lazy">
                    </div>
                    <h3 class="step-title">あなたから届く最新情報</h3>
                    <p class="step-desc">例えば、本日売却したらいくらになるのか。あなたから1週間に1度、お客様に自動的に配信できます。</p>
                    <p class="step-desc">例えば、お客様の希望条件に合致した、本日売り出しになった物件情報を24時間以内に、365日休みなく、あなたがお届けできます。</p>
                </div>
                <div class="step-card">
                    <span class="step-badge">3</span>
                    <div class="step-image">
                        <img src="assets/images/communication.png" alt="コミュニケーションツール" loading="lazy">
                    </div>
                    <h3 class="step-title">コミュニケーションツール</h3>
                    <p class="step-desc">ただLINEでつながるだけならLINEで十分です。お客様が必要なAIツールが提供できるところが大きな違いです。</p>
                    <p class="step-desc">そして、お客様からの問い合わせは直接あなたに届くようになります。お客様から連絡が来る、頼られる営業マンです。</p>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Automation Process Section -->
    <section class="automation-section">
        <div class="container">
            <div class="automation-content">
                <h2 class="automation-title">営業プロセスを「自動化」する。</h2>
                <div class="automation-grid">
                    <div class="automation-card-wrapper">
                        <div class="automation-card-content">
                            <h3 class="automation-card-title" style="margin-bottom: 0; font-size: 0.8rem; font-weight: normal;">001 /</h3>
                        </div>
                        <div class="automation-card">
                            <div class="automation-card-icon">
                                <div class="automation-icon">
                                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style="margin-bottom: -2rem;">
                                        <!-- U-shaped magnet with customer icon and lightning -->
                                        <path d="M 30 20 Q 30 10 40 10 L 60 10 Q 70 10 70 20 L 70 50 Q 70 60 60 60 L 40 60 Q 30 60 30 50 Z"
                                            fill="none" stroke="#0066cc" stroke-width="3"/>
                                        <circle cx="50" cy="35" r="8" fill="none" stroke="#0066cc" stroke-width="2"/>
                                        <path d="M 50 43 L 50 55" stroke="#0066cc" stroke-width="2"/>
                                        <path d="M 45 50 L 55 50" stroke="#0066cc" stroke-width="2"/>
                                        <!-- Lightning bolts -->
                                        <path d="M 75 25 L 80 35 L 77 35 L 82 45" stroke="#0066cc" stroke-width="2" fill="none" stroke-linecap="round"/>
                                        <path d="M 25 25 L 20 35 L 23 35 L 18 45" stroke="#0066cc" stroke-width="2" fill="none" stroke-linecap="round"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="automation-card-content">
                                <h3 class="automation-card-title">顧客獲得 <span class="automation-subtitle">(Lead Capture)</span></h3>
                                <p class="automation-description">
                                    ワンタップでLINE連携完了。QRコードを読み込むだけで、その場で顧客とつながり、失注を防ぎます。
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="automation-card-wrapper">
                        <div class="automation-card-content">
                            <h3 class="automation-card-title" style="margin-bottom: 0; font-size: 0.8rem; font-weight: normal;">002 /</h3>
                        </div>
                        <div class="automation-card">
                            <div class="automation-card-icon">
                                <div class="automation-icon">
                                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                                        <!-- Shield with briefcase -->
                                        <path d="M 50 15 L 25 25 L 25 60 Q 25 75 50 85 Q 75 75 75 60 L 75 25 Z"
                                            fill="none" stroke="#0066cc" stroke-width="3"/>
                                        <rect x="35" y="40" width="30" height="20" rx="2" fill="none" stroke="#0066cc" stroke-width="2"/>
                                        <path d="M 40 40 L 40 35 L 60 35 L 60 40" stroke="#0066cc" stroke-width="2" fill="none"/>
                                        <path d="M 45 50 L 55 50" stroke="#0066cc" stroke-width="2"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="automation-card-content">
                                <h3 class="automation-card-title">価値提供 <span class="automation-subtitle">(Value Proposition)</span></h3>
                                <p class="automation-description">
                                    顧客に6つの強力な不動産テックツールを無料で提供。情報武装で他社を圧倒し、信頼を勝ち取ります。
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="automation-card-wrapper">
                        <div class="automation-card-content">
                            <h3 class="automation-card-title" style="margin-bottom: 0; font-size: 0.8rem; font-weight: normal;">003 /</h3>
                        </div>
                        <div class="automation-card" style="border-bottom: none;">
                            <div class="automation-card-icon">
                                <div class="automation-icon">
                                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                                        <!-- Robot head with growth arrow -->
                                        <rect x="30" y="25" width="40" height="35" rx="5" fill="none" stroke="#0066cc" stroke-width="3"/>
                                        <circle cx="40" cy="38" r="3" fill="#0066cc"/>
                                        <circle cx="60" cy="38" r="3" fill="#0066cc"/>
                                        <path d="M 40 48 Q 50 52 60 48" stroke="#0066cc" stroke-width="2" fill="none"/>
                                        <rect x="35" y="20" width="30" height="8" rx="2" fill="none" stroke="#0066cc" stroke-width="2"/>
                                        <!-- Growth arrow graph -->
                                        <path d="M 20 70 L 30 65 L 40 60 L 50 55 L 60 50 L 70 45 L 80 40"
                                            stroke="#0066cc" stroke-width="2" fill="none" stroke-linecap="round"/>
                                        <path d="M 75 42 L 80 40 L 75 38" stroke="#0066cc" stroke-width="2" fill="none" stroke-linecap="round"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="automation-card-content">
                                <h3 class="automation-card-title">顧客育成 <span class="automation-subtitle">(Lead Nurturing)</span></h3>
                                <p class="automation-description" id="features">
                                    AIが顧客の希望条件に合う物件情報を毎日自動配信。常に顧客の第一想起を維持し、エンゲージメントを高めます。
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
       

    <!-- Features & Pricing Introduction -->
    <section class="features-intro-section">
        <div class="container">
            <h2 class="section-title">主な機能と作成手順</h2>
        </div>
    </section>

    <!-- Feature 01: LINE Integration -->
    <section class="feature-section feature-01">
        <div class="container">
            <div class="feature-content"">
                <div class="feature-visual feature-visual-desktop-only">
                    <div class="feature-image-wrapper line-feature">
                        <div id="communication-alternate" style="width:100%; margin-bottom: 20px; opacity: 0;" class="crossfade-container">
                            <div class="communication-category" id="comm-category-1">
                                <ul class="communication-list">
                                    <li><img src="assets/images/icons/line.png" alt="LINE"></li>
                                    <li><img src="assets/images/icons/messenger.png" alt="Messenger"></li>
                                    <li><img src="assets/images/icons/chatwork.png" alt="Chatwork"></li>
                                </ul>
                                <p class="category-future">※近い将来、「不動産MYページ」という独自コミュニケーションツールをリリースし、追加する予定です。</p>
                            </div>
                            <div class="communication-category" id="comm-category-2">
                                <ul class="communication-list">
                                    <li><img src="assets/images/icons/instagram.png" alt="Instagram"></li>
                                    <li><img src="assets/images/icons/facebook.png" alt="Facebook"></li>
                                    <li><img src="assets/images/icons/twitter.png" alt="X (Twitter)"></li>
                                    <li><img src="assets/images/icons/youtube.png" alt="YouTube"></li>
                                    <li><img src="assets/images/icons/tiktok.png" alt="TikTok"></li>
                                    <li><img src="assets/images/icons/note.png" alt="note"></li>
                                    <li><img src="assets/images/icons/pinterest.png" alt="Pinterest"></li>
                                    <li><img src="assets/images/icons/threads.png" alt="Threads"></li>
                                </ul>
                            </div>
                        </div>
                        <div class="category-future-card">
                            <p class="category-future-card-text">※近い将来、「不動産MYページ」という独自コミュニケーションツールをリリースし、追加する予定です。</p>
                        </div>
                    </div>
                </div>
                <div class="feature-text">
                    <div class="feature-badge">機能01</div>
                    <h2 class="feature-title">ワンタップで<br>SNS連携完了</h2>
                    <p class="feature-description">
                        QRコードを読み込むだけで、その場で顧客とつながれます。名刺交換後の失注を防ぎ、確実なコミュニケーション手段を確立します。
                    </p>
                    <ul class="feature-list">
                        <li><span class="check-icon">✓</span> LINE、Messenger、Chatworkに対応</li>
                        <li><span class="check-icon">✓</span> 顧客の連絡先を自動保存可能</li>
                        <li><span class="check-icon">✓</span> SNSアカウントも同時に共有できる</li>
                    </ul>
                    <div class="category-future-card category-future-card-mobile-only">
                        <p class="category-future-card-text">※近い将来、「不動産MYページ」という独自コミュニケーションツールをリリースし、追加する予定です。</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Feature 02: 7 Real Estate Tech Tools -->
    <section class="feature-section feature-02">
        <div class="container">
            <div class="feature-content reverse">
                <div class="feature-text">
                    <div class="feature-badge">機能02</div>
                    <h2 class="feature-title">６つの不動産テックツールで
                    情報武装</h2>
                    <p class="feature-description">
                        顧客に無料で提供できる不動産テックツールで、他社との圧倒的な差別化を実現。情報武装した顧客は、あなたを信頼し、選びます。
                    </p>
                    <ul class="feature-list yellow-bullets">
                        <li>全国マンションデータベース</li>
                        <li>物件提案ロボ(AI評価付き)</li>
                        <li>土地情報ロボ</li>
                        <li>AIマンション査定</li>
                        <li>セルフィン(物件自動判定)</li>
                        <li>オーナーコネクト</li>
                    </ul>
                </div>
                <div class="feature-visual">
                    <div class="tech-tools-hex-container">
                        <div class="tech-tools-hex-grid">
                            <!-- Hexagon 1: 全国マンションデータベース -->
                            <div class="tech-tools-hex-item">
                                <div class="tech-tools-hex-icon">
                                    <img src="assets/images/icons/ic (1).png" alt="全国マンションデータベース" loading="lazy">
                                </div>
                                <h4 class="tech-tools-hex-item-title">全国マンションデータベース</h4>
                                <p class="tech-tools-hex-item-desc">全国の分譲マンションの95%以上を網羅。</p>
                                <div class="tech-tools-hex-buttons">
                                    <!-- <span class="tech-tools-hex-btn">売り</span> -->
                                    <a href="https://self-in.com/demo/mdb" class="tech-tools-hex-btn" target="_blank" rel="noopener noreferrer">買い</a>
                                </div>
                            </div>
                            
                            <!-- Hexagon 2: 物件提案ロボ -->
                            <div class="tech-tools-hex-item">
                                <div class="tech-tools-hex-icon">
                                    <img src="assets/images/icons/ic (2).png" alt="物件提案ロボ" loading="lazy">
                                </div>
                                <h4 class="tech-tools-hex-item-title">物件提案ロボ</h4>
                                <p class="tech-tools-hex-item-desc">希望条件に合う物件をAI評価付きで自動配信。</p>
                                <div class="tech-tools-hex-buttons">
                                    <a href="https://self-in.net/rlp/index.php?id=demo" class="tech-tools-hex-btn" target="_blank" rel="noopener noreferrer">買い</a>
                                </div>
                            </div>
                            
                            <!-- Hexagon 3: 土地情報ロボ -->
                            <div class="tech-tools-hex-item">
                                <div class="tech-tools-hex-icon">
                                    <img src="assets/images/icons/ic (3).png" alt="土地情報ロボ" loading="lazy">
                                </div>
                                <h4 class="tech-tools-hex-item-title">土地情報ロボ</h4>
                                <p class="tech-tools-hex-item-desc">希望条件に合う土地情報を自動でお届け。</p>
                                <div class="tech-tools-hex-buttons">
                                    <a href="https://self-in.net/llp/index.php?id=demo" class="tech-tools-hex-btn" target="_blank" rel="noopener noreferrer">買い</a>
                                </div>
                            </div>
                            
                            <!-- Hexagon 4: AIマンション査定 -->
                            <div class="tech-tools-hex-item">
                                <div class="tech-tools-hex-icon">
                                    <img src="assets/images/icons/ic (4).png" alt="AIマンション査定" loading="lazy">
                                </div>
                                <h4 class="tech-tools-hex-item-title">AIマンション査定</h4>
                                <p class="tech-tools-hex-item-desc">個人情報不要でマンションの査定を実施。</p>
                                <div class="tech-tools-hex-buttons">
                                    <a href="https://self-in.com/demo/ai" class="tech-tools-hex-btn" target="_blank" rel="noopener noreferrer">売り</a>
                                </div>
                            </div>
                            
                            <!-- Hexagon 5: セルフィン -->
                            <div class="tech-tools-hex-item">
                                <div class="tech-tools-hex-icon">
                                    <img src="assets/images/icons/ic (5).png" alt="セルフィン" loading="lazy">
                                </div>
                                <h4 class="tech-tools-hex-item-title">セルフィン</h4>
                                <p class="tech-tools-hex-item-desc">物件の良し悪しをAIが自動判定。</p>
                                <div class="tech-tools-hex-buttons">
                                    <a href=" https://self-in.net/slp/index.php?id=demo" class="tech-tools-hex-btn" target="_blank" rel="noopener noreferrer">買い</a>
                                </div>
                            </div>
                            
                            <!-- Hexagon 6: オーナーコネクト -->
                            <div class="tech-tools-hex-item">
                                <div class="tech-tools-hex-icon">
                                    <img src="assets/images/icons/ic (6).png" alt="オーナーコネクト" loading="lazy">
                                </div>
                                <h4 class="tech-tools-hex-item-title">オーナーコネクト</h4>
                                <p class="tech-tools-hex-item-desc">マンション所有者向けの資産ウォッチツール。</p>
                                <div class="tech-tools-hex-buttons">
                                    <a href="https://self-in.net/olp/index.php?id=demo" class="tech-tools-hex-btn" target="_blank" rel="noopener noreferrer">売り</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Feature 03: 3 Minutes to Complete -->
    <section class="feature-section feature-03" style="margin-bottom: 6.5rem;">
        <div class="container">
            <div class="feature-content">
                <div class="feature-visual" style="opacity: 0;">
                    <img src="assets/images/sec-3.jpg" alt="Phone" class="feature-image">
                    <!-- <div class="feature-image-wrapper phone">
                        <div class="phone-placeholder">
                            <div class="phone-screen">
                                <div class="phone-header">Create Card</div>
                                <div class="phone-card-preview"></div>
                                <div class="phone-form">
                                    <div class="form-field"></div>
                                    <div class="form-field"></div>
                                    <div class="form-field"></div>
                                </div>
                            </div>
                        </div>
                    </div> -->
                </div>
                <div class="feature-text">
                    <div class="feature-badge">機能03</div>
                    <h2 class="feature-title"><span class="text-blue">3分で完成</span>、即日利用開始</h2>
                    <p class="feature-description">
                        必要な情報を入力するだけで、プロフェッショナルなAI名刺が完成。リアルタイムでプレビューを確認しながら、いつでも編集可能です。
                    </p>
                    <div class="steps-box">
                        <div class="step-item">
                            <div class="step-icon">1</div>
                            <div class="step-text">アカウント登録(SMS認証)</div>
                        </div>
                        <div class="step-item">
                            <div class="step-icon">2</div>
                            <div class="step-text">会社・個人情報を入力</div>
                        </div>
                        <div class="step-item">
                            <div class="step-icon">3</div>
                            <div class="step-text">即日利用開始</div>
                        </div>
                    </div>
                    <p class="feature-note">
                        ※6つの不動産テックツールとの連携は、お申し込みから1週間以内に接続されます。<br>
                        接続先は、あなた独自のページとなります。
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Real Estate Tech Tools Detail Section -->
    <!-- <section class="tools-detail-section" id="tools">
        <div class="container">
            <h2 class="section-title">搭載されている不動産テックツール</h2>
            <p class="section-subtitle">
                顧客に無料で提供できる、業界最先端のテックツール。これらのツールが、あなたの営業力を飛躍的に向上させます。
            </p>
            <div class="tools-grid">
                <div class="tool-card">
                    <div class="tech-tool-content">
                        <div class="tech-tool-banner">
                            <img src="assets/images/tech_banner/mdb.jpg" alt="全国マンションデータベース">
                        </div>
                    </div>
                </div>

                <div class="tool-card">
                    <div class="tech-tool-content">
                        <div class="tech-tool-banner">
                            <img src="assets/images/tech_banner/rlp.jpg" alt="物件提案ロボ" class="tech-tool-banner">
                        </div>
                    </div>
                </div>

                <div class="tool-card">
                    <div class="tech-tool-content">
                        <div class="tech-tool-banner">
                            <img src="assets/images/tech_banner/llp.jpg" alt="土地情報ロボ" class="tech-tool-banner">
                        </div>
                    </div>
                </div>

                <div class="tool-card">
                    <div class="tech-tool-content">
                        <div class="tech-tool-banner">
                            <img src="assets/images/tech_banner/ai.jpg" alt="AIマンション査定" class="tech-tool-banner">
                        </div>
                    </div>
                </div>

                <div class="tool-card">
                    <div class="tech-tool-content">
                        <div class="tech-tool-banner">
                            <img src="assets/images/tech_banner/slp.jpg" alt="セルフィン" class="tech-tool-banner">
                        </div>
                    </div>
                </div>

                <div class="tool-card">
                    <div class="tech-tool-content">
                        <div class="tech-tool-banner">
                            <img src="assets/images/tech_banner/olp.jpg" alt="オーナーコネクト" class="tech-tool-banner">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section> -->

    <!-- Testimonials Two Column Section -->
    <section class="testimonials-two-column-section">
        <div class="container">
            <div class="testimonials-two-column-grid">
                <!-- Left Column -->
                <div class="testimonials-column">
                    <h2 class="testimonials-column-title">この一枚が、会話のきっかけになる。</h2>
                    <div class="testimonials-column-content">
                        <div class="testimonial-block">
                            <div class="testimonial-portrait">
                                <div class="testimonial-portrait-image">
                                    <img src="assets/images/testimonials/brand-builder.png" alt="The Brand Builder">
                                </div>
                                <h3 class="testimonial-english-title">The Brand Builder</h3>
                            </div>
                            <div class="testimonial-content">
                                <p class="testimonial-japanese-text">
                                    相手に興味を持ってもらえたり、覚えてもらう、この体験はセルフブランディングの一環として、他の人と違う演出に役立っていますね。
                                </p>
                            </div>
                        </div>
                        <div class="testimonial-block">
                            <div class="testimonial-portrait">
                                <div class="testimonial-portrait-image">
                                    <img src="assets/images/testimonials/creator.jpg" alt="The Creator">
                                </div>
                                <h3 class="testimonial-english-title">The Creator</h3>
                            </div>
                            <div class="testimonial-content">
                                <p class="testimonial-japanese-text">
                                    自分の活動内容やスキル、SNSアカウントなどを自由に掲載できますし、デザインもカスタマイズできます。まさに、私が求めていた理想の名刺でしたね。
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="testimonials-column">
                    <h2 class="testimonials-column-title">営業活動を、劇的に変える。</h2>
                    <div class="testimonials-column-content">
                        <div class="testimonial-block">
                            <div class="testimonial-portrait">
                                <div class="testimonial-portrait-image">
                                    <img src="assets/images/testimonials/efficiency-expert.jpg" alt="The Efficiency Expert">
                                </div>
                                <h3 class="testimonial-english-title">The Efficiency Expert</h3>
                            </div>
                            <div class="testimonial-content">
                                <p class="testimonial-japanese-text">
                                    名刺交換後の失注が激減。顧客との信頼関係構築が圧倒的に早くなりました。
                                </p>
                            </div>
                        </div>
                        <div class="testimonial-block">
                            <div class="testimonial-portrait">
                                <div class="testimonial-portrait-image">
                                    <img src="assets/images/testimonials/trusted-advisor.jpg" alt="The Trusted Advisor">
                                </div>
                                <h3 class="testimonial-english-title">The Trusted Advisor</h3>
                            </div>
                            <div class="testimonial-content">
                                <p class="testimonial-japanese-text">
                                    AI査定ツールのおかげで、顧客との会話がスムーズになり、信頼を得やすくなりました。
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Profile Landing Section -->
    <section class="profile-landing-section">
        <div class="container">
            <div class="profile-landing-card">
                <div class="profile-landing-phone-column">
                    <div class="profile-landing-phone-wrapper">
                        <!-- <div class="profile-landing-phone">
                            <div class="profile-landing-phone-header">
                                <div class="profile-landing-phone-indicator"></div>
                            </div>
                            <div class="profile-landing-phone-body">
                                <div class="profile-landing-phone-profile">
                                    <div class="profile-landing-avatar"></div>
                                    <div class="profile-landing-profile-text">
                                        <p class="profile-landing-role">スタジオプレーヤー/同年代</p>
                                        <p class="profile-landing-name">さかき あかね</p>
                                    </div>
                                </div>
                                <div class="profile-landing-description">
                                    <p>
                                        #Prairie Cardリレーションカード利用代理店<br>
                                        サブスクメンバーを募っておりこの回の運営人<br>
                                        兼1stアーティストです。
                                    </p>
                                </div>
                                <button type="button" class="profile-landing-main-button">
                                    連絡先の表示と保存
                                </button>
                                <div class="profile-landing-social-grid">
                                    <span class="profile-landing-social-chip">Facebook</span>
                                    <span class="profile-landing-social-chip">Instagram</span>
                                    <span class="profile-landing-social-chip">X</span>
                                    <span class="profile-landing-social-chip">Eight</span>
                                    <span class="profile-landing-social-chip">LINE</span>
                                    <span class="profile-landing-social-chip">LinkedIn</span>
                                </div>
                            </div>
                        </div> -->
                    </div>
                </div>
                <div class="profile-landing-text-column">
                    <h2 class="profile-landing-title">あなただけのプロフィールページを作成</h2>
                    <p class="profile-landing-lead">
                        基本情報・SNSアイコン・自由リンクと大きく3つのパートに分かれています。
                    </p>
                    <p class="profile-landing-text">
                        シンプルで使いやすいデザインです。<br>
                        最新のお知らせや肩書きが変わっても、その場で更新できます。
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Profile Multi Features Section -->
    <section class="profile-multi-section">
        <div class="container">
            <div class="profile-multi-grid">
                <!-- Card 1 -->
                <div class="profile-multi-item">
                    <div class="profile-multi-phone" style="background-image: url('assets/images/phone2.png')">
                    </div>
                    <h3 class="profile-multi-title">ワンタップで、LINEと不動産テックツールへつながる</h3>
                    <p class="profile-multi-text">
                    名刺を読み取ったその場で、公式LINE・Messenger・WhatsAppなどにすぐ接続。<br>
                    さらに、全国マンションDBやAI査定などの不動産テックツールへもワンタップで案内できます。
                    </p>
                </div>

                <!-- Card 2 -->
                <div class="profile-multi-item">
                    <div class="profile-multi-phone" style="background-image: url('assets/images/phone3.png')">
                    </div>
                    <h3 class="profile-multi-title">物件提案・査定に役立つリンクを自由に掲載</h3>
                    <p class="profile-multi-text">
                    物件紹介ページ、会社HP、売却査定フォーム、説明資料（PDF）、YouTube動画、来店予約など、
                    営業に必要なURLをまとめて設置可能です。
                    </p>
                </div>

                <!-- Card 3 -->
                <div class="profile-multi-item">
                    <div class="profile-multi-phone" style="background-image: url('assets/images/phone4.png')">
                    </div>
                    <h3 class="profile-multi-title">つながり方が増えるから、失注が減る</h3>
                    <p class="profile-multi-text" id="pricing">
                    LINEで即連絡先交換、名刺情報の保存、アクセス履歴の把握などで「連絡が途切れる」を防止。<br>
                    コミュニケーションの起点を増やし、商談化までスムーズに導きます。
                    </p>
                </div>
            </div>
        </div>
    </section>

    <?php if (!$isTokenBased || $userType === 'new'): ?>
    <!-- New User Pricing Section -->
    <section class="lp-new-pricing-section">
        <div class="container">
            <h2 class="lp-new-title">費用・決済方法について</h2>
            <p class="lp-new-subtitle">初期費用 + 月額課金</p>
            
            <div class="lp-new-content-grid">
                <!-- Left Column: Benefits List -->
                <div class="lp-new-benefits">
                    <h3 class="lp-new-benefits-title">QRコードをかざすだけ</h3>
                    <ul class="lp-new-benefits-list">
                        <li class="lp-new-benefit-item">デジタル名刺の作成・管理</li>
                        <li class="lp-new-benefit-item">QRコード自動生成機能</li>
                        <li class="lp-new-benefit-item">LINE、SNS連携機能</li>
                        <li class="lp-new-benefit-item">6つの不動産テックツール連携</li>
                        <li class="lp-new-benefit-item-small">全国マンションデータベース</li>
                        <li class="lp-new-benefit-item-small">物件提案ロボ</li>
                        <li class="lp-new-benefit-item-small">土地情報ロボ</li>
                        <li class="lp-new-benefit-item-small">セルフィン</li>
                        <li class="lp-new-benefit-item-small">AIマンション査定</li>
                        <li class="lp-new-benefit-item-small">オーナーコネクト</li>
                        <li class="lp-new-benefit-item">自分の名前で顧客に情報が届く</li>
                        <li class="lp-new-benefit-item">顧客からの問い合わせが直接届く</li>
                    </ul>
                </div>
                
                <!-- Right Column: Pricing Card -->
                <div class="lp-new-pricing-card">
                    <div class="lp-new-pricing-content">
                        <!-- Popular Badge -->
                        <div class="lp-new-popular-badge">人気</div>
                        
                        <!-- Plan Title -->
                        <h3 class="lp-new-plan-title">不動産AI名刺プラン</h3>
                        
                        <!-- Main Price Display -->
                        <div class="lp-new-main-price">
                            <span class="lp-new-price-symbol">¥</span>
                            <span class="lp-new-price-number">500</span>
                            <span class="lp-new-price-period">/月</span>
                        </div>
                        
                        <!-- Initial Fee Note -->
                        <p class="lp-new-initial-fee-note">+初期費用 30,000円（税別）</p>
                        
                        <!-- CTA Button -->
                        <a href="register.php?type=new" class="lp-new-inquire-button">詳細を問い合わせる</a>
                        
                        <!-- Disclaimer -->
                        <p class="lp-new-disclaimer-note">※初月は無料トライアル期間です</p>
                    </div>
                </div>
            </div>
            
        </div>
    </section>
    <?php endif; ?>

    <!-- Concept Bridge Section -->
    <section class="lp-bridge-section">
        <div class="container">
            <h2 class="lp-bridge-headline">あなたの1枚は、何を語りますか？</h2>
        </div>
    </section>

    <?php if (!$isTokenBased || $userType === 'new'): ?>
    <!-- Final CTA Section -->
    <section class="final-cta-section">
        <div class="container">
            <div class="cta-content">
                <div class="cta-text">
                    <h2 class="cta-title">
                        <span class="text-blue">今すぐ始めて、</span><br>
                        営業力を次のレベルへ
                    </h2>
                    <p class="cta-description">
                        3分で完成。即日利用開始。<br>あなたの営業活動を劇的に変える、不動産AI名刺を今すぐ作成しましょう。
                    </p>
                    <a href="register.php?type=new" class="btn-primary btn-large" style="margin-top: 2.8rem; border-radius: 2.25rem; padding-inline: 3rem;">不動産AI名刺をつくる</a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

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
                            <a href="#" class="social-link">
                                <span>ヘルプセンター</span>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="social-link">
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

    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/js/mobile-menu.js"></script>
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/lp.js"></script>
    <script>
        (function() {
            var cat1 = document.getElementById('comm-category-1');
            var cat2 = document.getElementById('comm-category-2');
            var container = document.getElementById('communication-alternate');
            if (!cat1 || !cat2 || !container) return;
            
            // Set container height to accommodate both elements
            function setContainerHeight() {
                // Temporarily show both to measure max height
                cat1.style.position = 'relative';
                cat1.style.opacity = '1';
                cat1.style.visibility = 'visible';
                cat2.style.position = 'relative';
                cat2.style.opacity = '1';
                cat2.style.visibility = 'visible';
                
                var height1 = cat1.offsetHeight;
                var height2 = cat2.offsetHeight;
                var maxHeight = Math.max(height1, height2);
                
                // Reset styles
                cat1.style.position = '';
                cat1.style.opacity = '';
                cat1.style.visibility = '';
                cat2.style.position = '';
                cat2.style.opacity = '';
                cat2.style.visibility = '';
                
                container.style.minHeight = maxHeight + 'px';
            }
            
            // Initialize: show first category
            cat1.classList.add('active');
            cat2.classList.remove('active');
            
            // Set initial height after a brief delay to ensure elements are rendered
            setTimeout(setContainerHeight, 100);
            
            var showingFirst = true;
            setInterval(function() {
                if (showingFirst) {
                    cat1.classList.remove('active');
                    setTimeout(function() {
                        cat2.classList.add('active');
                    }, 50);
                } else {
                    cat2.classList.remove('active');
                    setTimeout(function() {
                        cat1.classList.add('active');
                    }, 50);
                }
                showingFirst = !showingFirst;
            }, 4000);
        })();
    </script>
    <?php if ($isTokenBased && !$tokenValid): ?>
    <script>
        // Show error if token is invalid
        alert('無効な招待リンクです。管理者にお問い合わせください。');
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 2000);
    </script>
    <?php endif; ?>
</body>
</html>

