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
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon.php?size=16&v=2">
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
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="assets/css/lp.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
    <link rel="stylesheet" href="assets/css/new_lp.css">

    <link rel="stylesheet" href="new_lp.css">
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
                    <p><span class="new-lp-sec-2-highlight" id="concept">一歩先を伴走する。</span><br><span class="new-lp-sec-2-highlight">「頼られる営業」</span>を、仕組みで。</p>
                </div>
            </div>
        </section>
        <!-- Section 3 - Product Overview -->
    <section class="section-product-overview">
        <div class="product-overview-container">
            <!-- Logo -->
            <div class="product-logo">
                <img src="./images/section3-logo.png" alt="不動産AI名刺">
            </div>

            <!-- Subtitle -->
            <p class="product-subtitle">「名刺」+「営業支援ツール」+「業務 DX 機能」が一体になった</p>

            <!-- Main Heading -->
            <h2 class="product-main-heading">"次世代型の営業起点ツール"</h2>

            <!-- Decorative Container -->
            <div class="product-content-container">
                <!-- Corner Decorations -->
                <div class="corner-top-right"></div>
                <div class="corner-bottom-left"></div>

                <!-- Container Heading -->
                <h3 class="container-heading">AI・ロボット・ビッグデータで不動産営業のDX化を強力にサポート！</h3>

                <!-- Content Split -->
                <div class="content-split">
                    <!-- Left Content -->
                    <div class="content-left">
                        <p class="content-main-title">
                            名刺から、<br>
                            営業データが一気につながる。
                        </p>
                        <p class="content-description">
                            例えば、<span class="highlight-blue">450万件以上の過去の販売履歴。</span><br>
                            <span class="highlight-blue">毎日6,000件以上の新規売り出し情報。</span><br>
                            <br>
                            物件の良し悪しを瞬時に判断し、<br>
                            <span class="highlight-blue">「今日の価格」</span>と<span
                                class="highlight-blue">「本当に欲しい人」</span>が分かる。<br>
                            <br>
                            QRコードを読み込むだけで、<br>
                            ビッグデータを、誰でも直感的に扱える。<br>
                            <br>
                            だから、<br>
                            <span class="highlight-blue">お客様が「あなた」を選ぶようになる。</span><br>
                            <br>
                            それが、不動産AI名刺です。
                        </p>
                    </div>

                    <!-- Right Image -->
                    <div class="content-right">
                        <img src="./images/section3-1.png" alt="不動産AI名刺イメージ">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Section 4 - Digital Trends -->
    <section class="section-digital-trends">
        <div class="digital-trends-container">
            <!-- Heading -->
            <h2 class="digital-trends-heading">
                米国不動産業界におけるデジタル名刺（NFC/QR）の<br>
                急速な普及とその背景
            </h2>

            <!-- Trend Items -->
            <div class="trend-items">
                <!-- Trend 1 -->
                <div class="trend-item">
                    <div class="trend-image">
                        <img src="./images/section4-1.png" alt="非対面・非接触ニーズの高まり">
                    </div>
                    <p class="trend-text">
                        高まり 新型コロナウイルス感染症（COVID-19）の流行以降、対面での名刺交換を避け、スマートフォンのタッチやスキャンで安全に情報を共有する形式が定着しました。
                    </p>
                </div>

                <!-- Trend 2 -->
                <div class="trend-item">
                    <div class="trend-image">
                        <img src="./images/section4-2.png" alt="サステナビリティ意識の向上">
                    </div>
                    <p class="trend-text">
                        不動産業界は持続可能性を重視し、紙の消費を減らすペーパーレス化（エコフレンドリー）への転換が進んでいます。
                    </p>
                </div>

                <!-- Trend 3 -->
                <div class="trend-item">
                    <div class="trend-image">
                        <img src="./images/section4-3.png" alt="DXの加速">
                    </div>
                    <p class="trend-text">
                        特に米国ではエージェント個々が独立した起業家として動いており、顧客組織との差別化のためにデジタルツールを駆使して業務効率を最大化する動機が強く働いています。
                    </p>
                </div>
            </div>

            <!-- Conclusion -->
            <div class="trends-conclusion">
                <p class="conclusion-text">
                    米国不動産市場において、デジタル名刺は<br>
                    「持っていると便利」なツールから「エー<br>
                    ジェントとして必須」のツールへと完全に<br>
                    主流化しています。
                </p>
                <div class="conclusion-illustration">
                    <img src="./images/section4-illustration.png" alt="デジタルビジネスイラスト">
                </div>
            </div>
        </div>
    </section>

    <!-- Section 5 - Problems -->
    <section class="section-problems">
        <div class="problems-section-container">
            <!-- Header (shared) -->
            <div class="problems-section-header">
                <p class="problems-section-label">problem</p>
                <h2 class="problems-section-title">こんなお悩みありませんか？</h2>
                <div class="title-decoration">
                    <svg width="118" height="13" viewBox="0 0 118 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M37.2018 0.752834C37.4765 0.83462 37.7355 0.94435 38.0156 0.975679C38.9055 1.0686 39.3863 1.54869 39.3578 2.72665C39.3459 3.44468 39.6676 3.62776 40.2307 3.49548C40.4586 3.44511 40.6918 3.37514 40.9197 3.26306C42.5236 2.47995 44.1236 2.29291 45.7286 2.24054C46.2941 2.22189 46.8962 2.25116 47.4412 2.03321C48.8429 1.48017 50.1886 1.50321 51.5394 1.56835C52.6127 1.62323 53.7075 1.41456 54.8047 1.25781C56.8762 0.972938 58.9399 0.655753 60.9953 0.522248C63.2262 0.3801 65.4305 0.428522 67.6455 0.406887C67.9256 0.40736 68.2003 0.520002 68.4805 0.489619C71.4789 0.0987482 74.3709 0.59358 77.3774 0.111595C78.1448 -0.0137041 78.8515 0.0864022 79.5316 0.346222C79.8901 0.484671 80.2537 0.634374 80.6754 0.537617C83.3574 -0.0250837 85.883 0.391558 88.4589 0.560283C89.9719 0.665788 91.5114 0.611579 93.0298 0.666628C93.7654 0.689787 94.4905 0.752148 95.2024 0.894365C97.33 1.31956 99.4947 1.54584 101.691 1.65452C103.042 1.71967 104.348 1.98228 105.677 2.15668C106.562 2.2692 107.444 2.39152 108.337 2.50549C109.229 2.61947 110.04 2.94468 110.862 3.29241C111.866 3.72551 112.886 4.16152 114.113 3.64798C114.474 3.49893 114.809 3.60215 115.07 3.82551C115.792 4.42222 116.592 4.69407 117.572 4.48463C117.8 4.43426 117.996 4.62492 118.006 4.92513C118.013 5.23515 117.827 5.49898 117.586 5.5675C116.608 5.8597 115.613 6.15879 114.731 5.72792C114.274 5.49904 113.818 5.38378 113.274 5.54003C112.26 5.81515 111.328 5.63219 110.47 5.23654C109.633 4.85505 108.788 4.7498 107.834 5.04637C107.069 5.28529 106.339 5.3351 105.769 4.72538C105.565 4.50241 105.275 4.54114 104.976 4.57841C104.17 4.69644 103.374 4.77526 102.57 4.85263C102.224 4.88117 101.844 4.9754 101.554 4.85985C100.303 4.36058 98.9022 4.63592 97.5882 4.46443C96.2612 4.28022 94.921 4.14502 93.6098 3.93286C92.118 3.68725 90.5307 4.04128 89.0257 3.87552C88.6409 3.83507 88.1327 3.97754 87.8713 3.75418C87.0115 2.99806 86.0003 3.29424 84.9658 3.49349C83.9313 3.69274 82.9259 3.69161 81.9183 3.51516C80.5024 3.26303 79.0365 3.16626 77.5228 3.36931C76.6665 3.4884 75.8157 3.43362 74.9622 3.45034C74.737 3.46006 74.5016 3.38555 74.2841 3.45843C72.5734 3.99537 70.9983 3.53881 69.3566 3.60496C67.0892 3.69918 64.8964 3.11786 62.5491 3.8455C62.3789 3.89625 62.1982 3.9245 62.0358 3.91499C58.5987 3.69983 55.0308 4.76713 51.5778 4.67247C51.3003 4.6622 50.986 4.69656 50.7187 4.83222C49.2721 5.55187 47.8533 5.46382 46.4261 5.62117C44.2553 5.84647 42.0797 5.84453 39.8966 6.97299C39.514 7.1696 39.0871 7.2551 38.6969 7.29596C37.8484 7.38566 37.1221 7.94042 36.3593 8.41638C35.9321 8.68702 35.553 8.41098 35.5043 7.8875C35.4377 7.12407 35.6068 6.37343 35.9564 5.65625C36.4506 4.64692 36.1581 4.26346 35.5541 3.87371C34.8847 3.45127 34.1572 3.27529 33.3246 3.33703C30.8056 3.53896 28.3053 3.54887 25.797 3.64989C24.0637 3.71963 22.273 3.69642 20.4856 4.64099C19.757 5.02042 19.0053 5.14863 18.2486 5.23473C16.46 5.44856 14.6761 5.9205 12.9411 6.88506C12.6685 7.04033 12.3828 7.18288 12.0947 7.24266C10.7694 7.51149 9.44681 7.77052 8.12687 7.9889C7.60046 8.07654 7.06626 8.13187 6.63592 8.6283C5.93532 9.42447 5.11805 9.64341 4.32727 9.70263C3.01542 9.79904 1.93305 10.3598 1.08463 11.7665C1.01109 11.8866 0.906198 11.9701 0.806524 12.0648C0.483887 12.3754 0.0705243 12.2268 0.021633 11.7959C-0.0658438 11.0183 0.108411 10.2789 0.596659 9.62856C0.927392 9.19598 1.31045 8.78339 1.7143 8.44668C2.17059 8.06823 2.65823 7.72647 3.15617 7.46893C6.115 5.93891 9.10487 4.59985 12.1646 3.67502C12.9715 3.43357 13.7809 3.21318 14.5721 2.90711C16.0053 2.35989 17.4329 1.98655 18.8837 1.77186C19.9156 1.61326 20.9554 1.36356 21.99 1.13345C24.6434 0.524276 27.2937 0.0791794 29.9172 0.00978866C31.6766 -0.0345324 33.4174 0.0823149 35.1741 0.109506C35.928 0.125776 36.582 0.391031 37.2124 0.713633L37.2018 0.752834Z"
                            fill="#192B49" />
                    </svg>
                </div>
            </div>

            <!-- Desktop layout -->
            <div class="problems-desktop">
                <div class="problems-content">
                    <div class="problem-card problem-card-1">
                        <div class="problem-checkmark">
                            <svg width="62" height="62" viewBox="0 0 62 62" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M31 54.25C34.0532 54.25 37.0766 53.6486 39.8974 52.4802C42.7182 51.3118 45.2813 49.5992 47.4402 47.4402C49.5992 45.2813 51.3118 42.7182 52.4802 39.8974C53.6486 37.0766 54.25 34.0532 54.25 31C54.25 27.9468 53.6486 24.9234 52.4802 22.1026C51.3118 19.2818 49.5992 16.7187 47.4402 14.5598C45.2813 12.4008 42.7182 10.6882 39.8974 9.5198C37.0766 8.35138 34.0532 7.75 31 7.75C24.8337 7.75 18.92 10.1995 14.5598 14.5598C10.1995 18.92 7.75 24.8337 7.75 31C7.75 37.1663 10.1995 43.08 14.5598 47.4402C18.92 51.8004 24.8337 54.25 31 54.25ZM30.4007 40.4033L43.3173 24.9033L39.3493 21.5967L28.241 34.9241L22.4931 29.1736L18.8403 32.8264L26.5903 40.5764L28.5898 42.5759L30.4007 40.4033Z"
                                    fill="#0071E4" />
                            </svg>
                        </div>
                        <h3 class="problem-card-heading">他社との差別化ができない</h3>
                        <p class="problem-card-text" id="features">競合エージェントとの違いを明確に示せず、選ばれる理由が弱い</p>
                    </div>
                    <div class="problem-illustration">
                        <img src="./images/section5-1.png" alt="悩む人">
                    </div>
                    <div class="problem-card problem-card-2">
                        <div class="problem-checkmark">
                            <svg width="62" height="62" viewBox="0 0 62 62" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M31 54.25C34.0532 54.25 37.0766 53.6486 39.8974 52.4802C42.7182 51.3118 45.2813 49.5992 47.4402 47.4402C49.5992 45.2813 51.3118 42.7182 52.4802 39.8974C53.6486 37.0766 54.25 34.0532 54.25 31C54.25 27.9468 53.6486 24.9234 52.4802 22.1026C51.3118 19.2818 49.5992 16.7187 47.4402 14.5598C45.2813 12.4008 42.7182 10.6882 39.8974 9.5198C37.0766 8.35138 34.0532 7.75 31 7.75C24.8337 7.75 18.92 10.1995 14.5598 14.5598C10.1995 18.92 7.75 24.8337 7.75 31C7.75 37.1663 10.1995 43.08 14.5598 47.4402C18.92 51.8004 24.8337 54.25 31 54.25ZM30.4007 40.4033L43.3173 24.9033L39.3493 21.5967L28.241 34.9241L22.4931 29.1736L18.8403 32.8264L26.5903 40.5764L28.5898 42.5759L30.4007 40.4033Z"
                                    fill="#0071E4" />
                            </svg>
                        </div>
                        <h3 class="problem-card-heading">他顧客への情報提供が不十分</h3>
                        <p class="problem-card-text">物件情報や市場データを効果的に共有できず、信頼構築に時間がかかる</p>
                    </div>
                    <div class="problem-card problem-card-3">
                        <div class="problem-checkmark">
                            <svg width="62" height="62" viewBox="0 0 62 62" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M31 54.25C34.0532 54.25 37.0766 53.6486 39.8974 52.4802C42.7182 51.3118 45.2813 49.5992 47.4402 47.4402C49.5992 45.2813 51.3118 42.7182 52.4802 39.8974C53.6486 37.0766 54.25 34.0532 54.25 31C54.25 27.9468 53.6486 24.9234 52.4802 22.1026C51.3118 19.2818 49.5992 16.7187 47.4402 14.5598C45.2813 12.4008 42.7182 10.6882 39.8974 9.5198C37.0766 8.35138 34.0532 7.75 31 7.75C24.8337 7.75 18.92 10.1995 14.5598 14.5598C10.1995 18.92 7.75 24.8337 7.75 31C7.75 37.1663 10.1995 43.08 14.5598 47.4402C18.92 51.8004 24.8337 54.25 31 54.25ZM30.4007 40.4033L43.3173 24.9033L39.3493 21.5967L28.241 34.9241L22.4931 29.1736L18.8403 32.8264L26.5903 40.5764L28.5898 42.5759L30.4007 40.4033Z"
                                    fill="#0071E4" />
                            </svg>
                        </div>
                        <h3 class="problem-card-heading">名刺交換後、連絡が途絶える</h3>
                        <p class="problem-card-text">せっかく出会った見込み客と、その後のコミュニケーションが取れない</p>
                    </div>
                </div>
            </div>

            <!-- Smart (mobile) layout -->
            <div class="problems-smart">
                <div class="problems-smart-illustration">
                    <img src="./images/section5-1.png" alt="悩む人">
                </div>
                <ul class="problems-smart-list">
                    <li class="problems-smart-item">
                        <span class="problems-smart-check">✓</span>
                        <div>
                            <h3 class="problems-smart-heading">他社との差別化ができない</h3>
                            <p class="problems-smart-text">競合エージェントとの違いを明確に示せず、選ばれる理由が弱い</p>
                        </div>
                    </li>
                    <li class="problems-smart-item">
                        <span class="problems-smart-check">✓</span>
                        <div>
                            <h3 class="problems-smart-heading">他顧客への情報提供が不十分</h3>
                            <p class="problems-smart-text">物件情報や市場データを効果的に共有できず、信頼構築に時間がかかる</p>
                        </div>
                    </li>
                    <li class="problems-smart-item">
                        <span class="problems-smart-check">✓</span>
                        <div>
                            <h3 class="problems-smart-heading">名刺交換後、連絡が途絶える</h3>
                            <p class="problems-smart-text">せっかく出会った見込み客と、その後のコミュニケーションが取れない</p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Section 6 - Features -->
    <section class="section-features">
        <div class="features-section-container">
            <!-- Header -->
            <div class="features-section-header">
                <p class="features-section-label">feature</p>
                <h2 class="features-section-title">不動産AI名刺の特徴</h2>
            </div>

            <!-- Feature Cards Grid -->
            <div class="features-cards-grid">
                <!-- Card 1 -->
                <div class="feature-card">
                    <div class="feature-card-image">
                        <img src="./images/section6-1.png" alt="QRコードをかざすだけ">
                    </div>
                    <h3 class="feature-card-heading">QRコードをかざすだけ</h3>
                    <p class="feature-card-text">
                        名刺にQRコードを印刷したり、携帯でQRコードを表示させ、お客様のスマホでかざすだけ。<br><br>
                        お客様が必要なツールの提供が始まり、コミュニケーションツールで、より確実な連絡手段を確立します。
                    </p>
                </div>

                <!-- Card 2 -->
                <div class="feature-card">
                    <div class="feature-card-image">
                        <img src="./images/section6-2.png" alt="あなたから届く最新情報">
                    </div>
                    <h3 class="feature-card-heading">あなたから届く最新情報</h3>
                    <p class="feature-card-text">
                        例えば、本日売却したらいくらになるのか、あなたから1週間に1度、お客様に自動的に配信できます。<br><br>
                        例えば、お客様の希望条件に合致した、本日売り出しになった新着情報を24時間以内に、365日休みなく、あなたがお届けできます。
                    </p>
                </div>

                <!-- Card 3 -->
                <div class="feature-card">
                    <div class="feature-card-image">
                        <img src="./images/section6-3.png" alt="コミュニケーションツール">
                    </div>
                    <h3 class="feature-card-heading">コミュニケーションツール</h3>
                    <p class="feature-card-text">
                        ただLINEでつながるだけならLINEでよいです。お客様が必要なツールが提供できることが大きな違いです。<br><br>
                        そして、お客様からの問い合わせは相談後あなたに届くようになります。お客様から連絡が来る、頼られる営業マンです。
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Section 7 - How to Use Video -->
    <section class="section-video">
        <div class="video-section-container">
            <!-- Header -->
            <div class="video-section-header">
                <p class="video-section-label">how to use</p>
                <h2 class="video-section-title">動画で見る不動産AI名刺の使い方</h2>
            </div>

            <!-- Folder-like Container -->
            <div class="video-folder-container">
                <!-- Text on Folder Tab -->
                <div class="folder-tab-text">
                    <span class="folder-tab-label">How to</span>
                    <span>動画で見る不動産AI名刺の使い方</span>
                </div>

                <!-- Video Player -->
                <div class="video-player-container">
                    <div class="video-responsive">
                        <iframe src="https://www.youtube.com/embed/o0jgL_4N7GM" title="不動産AI名刺の使い方"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen>
                        </iframe>
                    </div>
                    <!-- Subtitle overlay -->
                    <!-- <div class="video-subtitle">一枚持ち歩くだけで、ずっと使えるデジタル名刺</div> -->
                </div>
            </div>
        </div>
    </section>

    <!-- Section 8 - Sales Process Flow -->
    <section class="section-flow">
        <div class="flow-container">
            <!-- Header -->
            <div class="flow-header">
                <p class="flow-label">flow</p>
                <h2 class="flow-title">
                    <span class="flow-title-dark">営業プロセスを</span><span class="flow-title-blue">「自動化」</span><span
                        class="flow-title-dark">する</span>
                </h2>
            </div>

            <!-- Three Steps -->
            <div class="flow-steps">
                <!-- Step 1 -->
                <div class="flow-step">
                    <div class="step-image-circle">
                        <img src="./images/section8-1.png" alt="顧客獲得">
                    </div>
                    <p class="step-badge">STEP01</p>
                    <h3 class="step-title">顧客獲得</h3>
                    <p class="step-description">QRコードを読み込むだけ。その場で顧客とつながり、失注を防ぎます。</p>

                    <!-- Arrow after step 1 -->
                    <div class="flow-arrow arrow-1">
                        <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 30 L45 30 M45 30 L35 20 M45 30 L35 40" stroke="#0066CC" stroke-width="4"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="flow-step">
                    <div class="step-image-circle">
                        <img src="./images/section8-2.png" alt="価値提供">
                    </div>
                    <p class="step-badge">STEP02</p>
                    <h3 class="step-title">価値提供</h3>
                    <p class="step-description">役立つ不動産ツールを無料で提供。情報の差が、信頼の差になります。</p>

                    <!-- Arrow after step 2 -->
                    <div class="flow-arrow arrow-2">
                        <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 30 L45 30 M45 30 L35 20 M45 30 L35 40" stroke="#0066CC" stroke-width="4"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="flow-step">
                    <div class="step-image-circle">
                        <img src="./images/section8-3.png" alt="顧客育成">
                    </div>
                    <p class="step-badge">STEP03</p>
                    <h3 class="step-title">顧客育成</h3>
                    <p class="step-description">AIが物件情報を自動配信。検討中のお客様との関係を育てます。</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Section 9 - Capabilities -->
    <section class="section-capabilities">
        <div class="capabilities-container">
            <!-- Header -->
            <div class="capabilities-header">
                <p class="capabilities-label">Capabilities</p>
                <h2 class="capabilities-title">不動産 AI 名刺の機能</h2>
            </div>

            <!-- Point 1: SNS Connection -->
            <div class="capability-point point-sns">
                <div class="capability-image-left">
                    <img src="./images/section9-1.png" alt="SNS Connection">
                </div>
                <div class="capability-content-right">
                    <div class="point-badge">
                        <!-- Point 01 SVG -->
                        <svg width="110" height="55" viewBox="0 0 110 55" fill="none" xmlns="http://www.w3.org/2000/svg"
                            xmlns:xlink="http://www.w3.org/1999/xlink">
                            <rect width="110" height="55" fill="url(#pattern0_30_1629)" />
                            <defs>
                                <pattern id="pattern0_30_1629" patternContentUnits="objectBoundingBox" width="1"
                                    height="1">
                                    <use xlink:href="#image0_30_1629" transform="scale(0.00666667 0.0133333)" />
                                </pattern>
                                <image id="image0_30_1629" width="150" height="75" preserveAspectRatio="none"
                                    xlink:href="./images/point-01.png" />
                            </defs>
                        </svg>
                    </div>
                    <h3 class="capability-heading">
                        <span class="text-blue">ワンタップ</span><span class="text-dark">で<br>SNS連携完了</span>
                    </h3>
                    <p class="capability-description">
                        QRコードを読み込むだけで、その場で顧客とつながれます。名刺交換後の失注を防ぎ、確実なコミュニケーション手段を確立します。
                    </p>

                    <ul class="capability-checklist">
                        <li>
                            <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <g clip-path="url(#clip0_241_32)">
                                    <path
                                        d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                        fill="#0066CC" />
                                    <path
                                        d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                        fill="#0066CC" />
                                </g>
                                <defs>
                                    <clipPath id="clip0_241_32">
                                        <rect width="24" height="21" fill="white" />
                                    </clipPath>
                                </defs>
                            </svg>
                            <span>LINE、Messenger、Chatworkに対応</span>
                        </li>
                        <li>
                            <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <g clip-path="url(#clip0_241_32)">
                                    <path
                                        d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                        fill="#0066CC" />
                                    <path
                                        d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                        fill="#0066CC" />
                                </g>
                            </svg>
                            <span>顧客の連絡先を自動保存可能</span>
                        </li>
                        <li>
                            <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <g clip-path="url(#clip0_241_32)">
                                    <path
                                        d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                        fill="#0066CC" />
                                    <path
                                        d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                        fill="#0066CC" />
                                </g>
                            </svg>
                            <span>SNSアカウントも同時に共有できる</span>
                        </li>
                    </ul>

                    <div class="coming-soon-box">
                        <p>＜近日リリース予定＞</p>
                        <p>1. AIチャットボット機能</p>
                        <p>2. 住宅ローンシミュレーター</p>
                        <p>3. 不動産専用コミュニケーションツール</p>
                    </div>
                </div>
            </div>

            <!-- Point 2: Tech Tools - this is very long, continuing in next message -->
            <!-- Point 2: Tech Tools -->
            <div class="capability-point point-tools">
                <div class="capability-content-left">
                    <div class="point-badge">
                        <!-- SVG for Point 02 - omitted for brevity, insert from spec -->
                        <svg width="110" height="55" viewBox="0 0 110 55" fill="none" xmlns="http://www.w3.org/2000/svg"
                            xmlns:xlink="http://www.w3.org/1999/xlink">
                            <rect width="110" height="55" fill="url(#pattern0_30_1643)" />
                            <defs>
                                <pattern id="pattern0_30_1643" patternContentUnits="objectBoundingBox" width="1"
                                    height="1">
                                    <use xlink:href="#image0_30_1643" transform="scale(0.00666667 0.0133333)" />
                                </pattern>
                                <image id="image0_30_1643" width="150" height="75" preserveAspectRatio="none"
                                    xlink:href="data:image/png;base64,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" />
                            </defs>
                        </svg>

                    </div>
                    <h3 class="capability-heading">
                        <span class="text-blue">６つの不動産<br>テックツール</span><span class="text-dark">で<br>情報武装</span>
                    </h3>
                    <p class="capability-description">
                        顧客に無料で提供できる不動産テックツールで、他社との圧倒的な差別化を実現。情報武装した顧客は、あなたを信頼し、選びます。
                    </p>

                    <div class="capability-video-wrap video-responsive">
                        <iframe src="https://www.youtube.com/embed/jkIP8Kth4SQ" title="YouTube video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                    </div>
                </div>

                <!-- 6 Tool Cards on Right -->
                <div class="tool-cards-grid">
                    <!-- Card 1 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <svg width="70" height="70" viewBox="0 0 70 70" fill="none"
                                xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <rect width="70" height="70" fill="url(#pattern0_241_231)" />
                                <defs>
                                    <pattern id="pattern0_241_231" patternContentUnits="objectBoundingBox" width="1"
                                        height="1">
                                        <use xlink:href="#image0_241_231"
                                            transform="translate(-0.00166667) scale(0.00333333)" />
                                    </pattern>
                                    <image id="image0_241_231" width="301" height="300" preserveAspectRatio="none"
                                        xlink:href="data:image/png;base64,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" />
                                </defs>
                            </svg>
                        </div>
                        <h4>全国マンション<br>データベース</h4>
                        <p>全国の分譲マンションの85%以上を網羅。</p>
                        <button>売却・購入</button>
                    </div>

                    <!--Card 2 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <svg width="70" height="70" viewBox="0 0 70 70" fill="none"
                                xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <rect width="70" height="70" fill="url(#pattern0_241_237)" />
                                <defs>
                                    <pattern id="pattern0_241_237" patternContentUnits="objectBoundingBox" width="1"
                                        height="1">
                                        <use xlink:href="#image0_241_237"
                                            transform="translate(-0.00166667) scale(0.00333333)" />
                                    </pattern>
                                    <image id="image0_241_237" width="301" height="300" preserveAspectRatio="none"
                                        xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAS0AAAEsCAYAAACWv+VLAAAACXBIWXMAAAsSAAALEgHS3X78AAAgAElEQVR4nO2dTYhja17Gn77M4i6G6ZYCZ8DBTlCmUHC67kJdqPTpwOgshJth7kJhoNPWchY3PaiLLKbTigF10emlQnlToDCLkVulLnSTOWE2jptbURAKhCSzc1HY8QN01S7e/0lO0vk4H+8579fzg6K6qtInb3clz3ne//v/uPf27VsQQogrvGd6AYQQkgeKFiHEKShahBCnoGgRQpyCokUIcQqKFiHEKShahBCn+JzpBfhIszWIAIwAvAEQA7gBEM/GvbmxRRHiCfeYXKqPZmvwAEqsPtzzkCWUiMUAbmbjXlzHugjxCYqWJpqtQRtKsO7n/KsTiBODErK51oUR4hkUrZJkcFd5WWBzS3mj6bqEeAFFqwQl3FVeJtjcVr6p+PkIsRaKVgGarUEDwBD63FVeplhvKRngJ0FB0cpJszXoAuijeneVhyTAn2wpY6OrIaRCKFoZEXc1AvDY7EoyM8XmlnJucjGE6IKilQFL3VVeFgC6s3HvyvRCCCkDResADrqrfSwBdOoUrJOL2678Mb47P+UJKNEGRWsPnrgrALiGEqxaThxPLm4jqEOKR1s/Sk5Ab6CEjCegpBAUrS2arcEZlLvaftO5Rq3u6uTi9gGUyH+c8a8k+WgxgBu6MZIVilaKZmvQB/DC9Do08BpAf5+7ktrIzmzc6+h4MnFXIwAPS1xmiVQax935aVx2XcRPKFrwyl0toMQo3vXDrez9yWzci8o8WQF3lZfkBDTZUs4reh7iEMF3eQjIXWnN3tfkro7xCKkbycnF7UbBOd1YmAQrWrJF2hUwdo087qo04q6GAJ7quF5O7kP9Oz6UtQCbBecM8AdAcNtDeRP3Ud2Wpk7KuKvc28OTi9u6ai3LsFFwzgC/fwQlWqnmfFVuaepgCuWudr4hM9ZGZhYtcVejI9ezlXSA/4oi5j5BbA89c1cvZ+Nef98PdeeXOeKuDnEf6+TgUV1PKoc7EdjsUTvei1Zg7mqE7Nn780M/dNxdpVkC6N+dnw7resLtw51mawBsxd7YXqg43m4PxV2ZChjrRqe7WkLFwfa+iU8ubjtQ/3euuquECYBOXakSOQ932OyxIF6KVo3N+apmAlXkrMtdTaDc2nzXD08ubvNez1aOuquTi9v+3flpX8eTaQw/sNljBrwTrWZrcAVPtjSH3FAF7sqrWst9qQ/p2si789N7ZZ+s4vADmz3uwDvRAjaCoMlnl+JZB91Qgez9kNxV5+78dGet5a7s/TKiZehwh80e4alobSPbqLSQ2fgGzeKu+sievX+0YDowdzXC1s2rqGhZdrgTXLPHIERrF/LCS4Qsgtk3rm53dbAdzcnF7RnUFslG8c5DbneVJq9oOXK4s8DmltK7AL+zoiVv5DbWVrlU0FLcWIS1kNVR3rOECrSPDqyrD73uKs/1bOYSQDevu0qTR7QcP9xZ9TLzoXOty3labWzmwmz0Z8p7hxGXM0pd7wE2ndgZ9L5gD7qhArWRWdzVKMf1bGUB5a7iXT/UXRtZwVxLEzyGev32Da9DCy47rT4OO4aN/kw6gpaaAvwH3VCBAG9I7uo1VCrDPmHO5YaOOS3H3VWag+EH1/BZtHax0Z+p7C9RBCbCWsSOxYiyuKsRsovhwYLpwNzVCNnd0FSuty//zQd3BWQ43HGR0ERrm43+TJrcWITNk8qH0O+uDrajqaE5X51odVcAXh5KKvVoNkCWw503Lrqv0EVrF1prxCTA/0ajuzoWu8p7PVuZQgXa410/rMBdNeBPvlrW1JknLuZ6uRyIr4rH8vExsBHgL1QjduBOVyRgPJ2Ne+0jj2kAuEJ9J6BVcNANFaiNDMVd6T7csRKK1nEeQgnLU2CjYj9GwRqxEgHezrEH3J2fjtJfi/OKsN6y2vzGPOiGCmTvh+SudIYfrIaiVYzEjb0AgGZrkKlGrGSA97pIoqBsr+LkawnOp2Nuttx1j7mr3LWWhwqmA3NXI7gfLlhB0dJDMoAhcWNLAKPZuNfdetwVit/VNwRLYjoP8rZdEddxA8lJk+tEyH4Cqhvd7upgOxoJQHtTDRCKu0pD0aqG+1AioJN46+s2gE9SE2puUGBeoJzKXckHgJUbi1BtwfkSwLBmd9WHH/lql1CVFMG4qzQULXdpyOf0hJoXOybU3JRwYwBWbkdnwflBN1SgNjKLuxrBnq1wUbJMXrK9NrI0FC0/2TgBPbm43SiizTvcQcRgjk03FiF/gD9Tcz7kq7UMxV3VOtfSZihadrGEulMCet9oD+Vje15gjPW2MtcJ6I4AfwObdZrbriaLuxrt+Hv7ONiOJjB3NYL72fuZoWjZw8YpkLwYqwyiJm4MAHBycZs+Ab0p6MZG2AzwJy5svp2KkaaAu9rbjsazADTd1Q58F61rqDdiA/Z2MF1ABVW334RXqPeNt3ECKgH+9JYyznMxcUAx3j1AWFHAXS0BREcEtSGfJ3D3hHAK9ZqId/0wRHeVxmfRmmxnj6cKnPuwY9tw8BTIMMm8wMdYB/g3Cs6LTrkpURvZP+YAJZdtlWoiJ2kR3EiuBWqea+kiPotWvP0NEYerZmsQQwWWTf7ikwaANgrWPhI3BuCdAP9NFjdWojZyUWR2obiV1boMNXvMgu65lt7is2jtZTbuvWm2BkOYPVXyYUTUvgD/DdQI+jj9YKkZ/KTgc70Tw2q2Bo28XQoMNHvMAt1VDoIUrRw8n417Qwe3GCZJtpTJ/1WaRonrznd870ZqQdMlVHGei8qNI8amG6sjuRaguyoERWs/k6S9x44tRgfFHQMpxq43dnLzWMXeRMRKNXsUEVk9X4Fmj8fQPddyF5dQNwnvBI+iVYDZuDcS9+V15rHDbMTepL3QKvZW0I1tlDrtafaYhSzN+crURm5c35PhxRtQtIozAkWrChbyWeeWbCP2lmovlN5W5oovptz3UK7ZwOEAv+65ltvsu/4NKFqEVMJGAfXJxW3VDqGKZo+j9PdSsdAGlKDMd/1dDdn711An0Tuv7xsULXuJNFwjSRC1Pa6xq8RniHodwnazx43kWhQ47d2Ohe5CQ21k0qLG9ZPozFC0LES2Gt1jjzvCEkAjqc2ztIPpEqoP/Mj0QnawkVwLZG/2mAWNtZGjkAQL8Fu0HpheQBE05uTcpIuJ0wXOImA/KHl9HbTzlgcZZlezxxjrLWV87AIV1EYGJViA36LVabYGoyItik1QZ07O3flpfHJx+xpmi4qnjgnWLjZ6mW2lW8TYCvDL7ziGnTWwzvCe6QVUyH2okp223N22Me3EHkuVfhLXmKHe2FOWO/QEwEuonJ/FkcdW8fwu8gjqZvAp3u1e2wAFqzQ+Oy1AvUA+BTZOh2KoF48NrUs+lbuzjSzuzk+j9DdS/eS7sD+47wsud6uoBJ+d1jbJ6dAnyHZaY9qJmWa+/Y2789M30seqDbUNItUxBfDBbNyLAHxgeC1WEZJo5eWRbNvIFhLg75teh6csoWpez5J4rHyeFLyedzdf37eHZXkhR9Mx3i3/KJuS4Dq+xqRMcrDEJy8S+O/ouJZNULSOk5wOpcs/HsCePky+osshLOBG8HsiW0EtyCHPEHbk42mFopUfBkXrQYeTXfWpKlHgXBe5GxzuIoRWzIxpEas4ubh9IHWHOm4OKyGYjXvxbNwbSgvuM9h3kFB0u91JUnokMXkOjwULoGj5TENSFJzh5OK2DY1vun3lLfL9jo7nsICHAP6j2RrMAbyCh9vBbSha/vIQmrYcFXGWiOrJxW3j5OI2hsqpq+VNl7FSYgk1xusS9jmzbWzb7lYGY1p+81TcS4z1BJ1YBqu2D/3FGrgP4Obk4vYG9hRwb9OdjXuj5ItUB9MIdiQnBwlFy3826uNk+IQtJM35bGWe/iLdwVROkilcBuD20D4msGMr8li6QZAdzMa9Lur9PTkVn6wSipY9LAA8mY170WzcO4P+AuUiXJ1c3Lb3BPQbdS/GQmpJsJV0DeYFCr5vD5Mi6TPY/UvfNfeuCyn2Nsj9ZA0nF7fpBniA3UF+bxDBemfmY8j4LFqL2bjXSL7YGsrZhR2B30Nz72wrk9logEeqJYQk0aL4LFqj9BfpoZwyVimGeeFqhzKMgBwlqXFN5mp6WYKjgyBjWuJsRoaXMaFgVYsUu7vCq2ZrcNVsDWKo9kkUrD0EKVpClu3XSwBPADyHGtO0rHRFRDfxnq61tvIhWNt6FJ+3h2WZpoLjMTaHcvbB2I4L3Acwb7YG6Qk6MbCq0/OZCVSemXevU4rWfvbVrc2hilRtP5Ekio1RYJIUuoS/269kDuIVAIhgvzK7JL2EvD0si+93ap/JKlidKhdRAZcAGolgCU5Mo8oDnRaxiWuobXhy3G/aDT0Vp7JvIKot8acFlLuKTS+kDiha9tIwvYAaWUBNml45hJOL2yHKjYvXxSuok70kUflGPmxy2sEIFsDtoZWkphCX5cnd+ek9bJ6A2lAelOY1gLO0YAmxgbUcIpnm9ApqOrctSZ/TkAQLoNOyDgnwj1C++8EkmeAsn2PICejJxW0XdgRnn9+dn7IcqBy2VU5Ujs9Oq2N5js7G2pqtwYNmazAE8BkqPpUUobiu8jkysKBgkSL4LFoPofoe2Spcj2RiSlIUe4N6+zNlOVVaQIlbFS1Y5hVckwSA79vDx1D9s9NB1BgqyG1DIPVTyRuykSVUrOkNoAZOYD3JpgO7m/cRj/HZaaVJB1E/Q429yB3mJhEsQE2Vvjs/vbo7P+1DiRdLmqplCeDZbNy7B+AD8P97RSiiVYTHUm1Ptrg7P53DvcRLl7iGShIdAasC/6JJoraGRwrj+/awLEOpNYx3HCtHta/GLoI7taqBKpJEbQiDaIWidZj7UAmOSc3aBOqO9wAeFqISoywBnO2b1ZgXudmOYE/WvjYoWvlICm9J9ehwCJdynQbWhwgR7DxE6BcUrHe2f83WoA97uvNqh6JFrEOSX3VknHdFCDZiQpLAG8OuN3XRmNWjZmtwNhv3blKJyV53H2Eg3l+cC8CeXNyeyaRpLdn6+5yLBLY7Op7DEj5rtgZz1JCYbAMULX95dHJx2ze9iAM8lknXAABZ62eoafu91b7lEFPYMYfyGDZueSuB20O/eXFycXsGSapNahEFG/qnxycXt3OomJONb7rVaLetaU5tBOBobIWi5T8fyseLk4tbQLmGB7BDJB7CjnXsI07+kJ7mBKAvE51s6fQQFNwehscjZBOKM3FpZDcd2NfmJwgoWmQf9wGMpOZwF84F+nUizmtex3NJzhVTbQRuD8khHgH4j5OL2+2Cc0BPk0JyBBGsrIcGQUDRIllICs5ZBVAjMuasD7vyyYxD0SLEDiKIiw0lSbQoIYnWJVQMogF7SzmIRpqtQUPmVLrAC9kKAnS0BwlFtK5n414n/Q15gSR5N2dgoNNHrpqtQaSrCLkGKFYZCEW03qnrkjvwHKkgp7Q9jrAWM8YS3OYRgFhyqmIAN4mAye+aOEgoopUJ6WMUJ1+LG4ugRKwNbild5JF8vACAZmswheoFRmftKBStA4gbGwFAszUYwb7OACQ/eYLbSTcIYhFMLs2IdAbwrgskOcirI9tIVgwYgE4rH3PTCyC18wOZ5pQk1sbSu6oPum4jULQIOU5S2P0hAFg89i0IuD0khDgFRSsclnCjmR0hB+H2MAwW2JwWHUGlckRQwWTGZogzULTC4GprWnSM1FG+9M1KVwew5o1YC0UrDA6WsdydnybTakYAID20IqyFjImYxBooWuQdxJVdIVXidHJx2wHwiak1EZLAQDzJxN356QiqUwYhRqFokTyMTC+AEIoWIcQpKFqEEKegaJGQYHKtB1C0SCg8n417Z7Nx7x6AJwCeA7gGZxc6B1MeSBDMxr1h6s8xVHLtEHin2WMEJtdaDUWLBE+62WNCqvV2BJY6WQVFi5Ad7Gi9nXQxpXgZhjEtN5iCDQiNIp1rI9PrIBQtF3gpAeS56YWEjgjXxPQ6QofbQ3uZAujIG4UQIrgsWiOoQRM+xhhezsa9vulFEGIjzm4PZbt0BuAllGVfGl2QHiYAPqBgkZpwZfL2Bi47rUS4+snXcsITYZ1v48pw1SWAfjqXiJCKmbgaenBatLaRX8LqF9FsDZJmdjbfUSZQsau56YWQYHA6/OCVaG0zG/eSZnY2QndF6saLwx2vRcti6K5I3TjtrtJQtOrlDYBns3FvZHohDmLzFt9mJgC6rrurNBStfLyBstiFCmpn415b73KCYAGgI8M3SHa8DT9QtHIgd6uzVICf02qq5TWAfnr8GcmE1+EHilYBUgH+VZB/R7pF1cQAXtTwPCZI3FVseiGO4a27SkPR0sR2ukXV3J2fxicXt8+g8tRcyUfLAt1VMbx2V2koWg4jY71GJxe3DWxOiHZxuzoF0KW7ys0SKtA+Mr2QuqBoecDd+ekcqnVNerhqhLWI2e5aXt6dn/ZNL8JBrqHcle2/X61QtDxFHEtseBnHmIIng0VYQomVrYnTlULRIqaguypGkO4qDUWL1A3dVTGCdldpKFqkTkZ0V4V4DZXKEKy7SkPRIpmRNIsn2DypzJxuIQcGJDsLKHcVm16ITVC0SC5SAf4hAEi6RQQzMwMX8CtHLQ3d1R4oWqQU4p5G6e9JukUdb7Y2VJqHT8JFd3UEihbRTl0JolKF0PCoFpTuKgMULeI8ltSClsGL5nx1QdEiXlJ3LWgJvGnOVxcULULMQHdVEIoWIfVDd1UCihYh9TGHmmtJd1UCihYh+ehiHdyPkCPdIoReV3Vw7+3bt6bXQIizpNItIrzby+wJ8630Q9EiRDPN1iCSP87prvRD0SKEOMV7phdACCF5oGgRQpyCokUIcQqKFiHEKShahBCnYHKppTRbgwaAhnwZyec3WBcB37CFCQkRpjxYgrRSaSNfP6gllIjFAGImMpIQoGgZRISqAyVWurpvTiC9pZjYSHyEomWAZmvQgRKrqjtsTqFaIVPAiDdQtGpCatTaAPow09OcAka8gKJVA83WoAslVvcNLyXhGustJIP5xCkoWhUi28A+7J4WcwklXsFPLibVkToNj+RbydeAOkgC5FDp2I2UolUBUuU/RL0zAMuyhNo+jtikjpQlFQ6JkLPvGNRh0mg27o12/ZCipRG5m4zg7girhAXWAjY3uxTiCimh6kLPDXtnH32Klgbkl9UF8ML0WiqAAXxyENlZdAA8reDySwDdtOuiaJVE4lZD2BNkrxIG8MkKee3rclXHWPXWp2gVxNG4lU4oYIFi6IBpCaAxG/feULRyInGrPqqxwq5CAQsAC07DX87GvT5FKyOpuFUXYWwFi5IIWMwYmB9YtKtYAmhQtDLQbA3aUL80m/OtbCQJ4sdMo3AP2VUMAXxoeClpnlG0DiAFzUO4n8JgAwusHRgTWS3G8tPwS4rWDuSXNgTjVlVyjXVLHbowS7AgbnWMaSWiJbayLR8Jb6BepCObg7UW1gmGwAIiYGAszAgu7Sq0ilYOh/JyNu71tT2xBiTYOIK9d5iQoIjVhIu7Cm2i1WwN+sh3sjYFEJl2XR6V3vjMEkrAki6tbDWtAVd3FaVFq+TJ2mQ27kWlFlAQy4ON5DgLrEXM6pCDbbi+qygsWhr3wM/2VXNXRWClNyGwBDC0LeRgG57sKvIH4ivICF8AOKvjTmlRkhyphp1dAULHs13FdWbRqjgjvNLAPEtvgmIJFSulcMHLXcXzTKJVQ+7GEsptzXVelKU3wbIqrjW9EFPIrqIPt7eCu2geHNZa4z/8vjxPR9cFWXoTNPehsu8jw+uoHRdTGHJwORv35judlsHt1JOyA0ddSpIjlVP7IY9JCqQducRqN/aO05J/uKmAXR8F746e32FIMbpQp2Ve43oKQ0a6Sfhow2k1W4MRzL/pv5G3oNbVJDlSC01fM+o9SWHIwuvZuNdNvlg5rWZrYItLGULFIzIhgvWquuUQx4ngmdvyLIXhEAsoh7WhB+8BK3v5sYFF7eKhbFGzMoLa7xKyi4bpBehETvLn8F+wXkPFsN4xMO/J5+72DwzTlbvJUeRYe1jxeggxSrM1iJqtQQzgE/gdBplADbHo7ktZSbaHNnUmBNQvZYiMKRCzca8vdyCfA5FVkQxpvQHwAMAZ8g/XJBUR0AHTEkB/Nu4dNSCfkxQBbXz5S/fx5S+tTdK//tu/4z//+3+LXOppszXo5wiidgF8WuSJAmYCoL3rjiaviwiqJ5rLgV5nE0w9T2FIcwkVu8r0u/oc1N21NF/4/Pv47re/hm/+xi9sfP+//uf/8Bff/ycML39Y5LIjZEyBmI17V83WYAK332B1MsUewQIAKYO5gWy9JVk3ghIxl1yYc+U8gaQwACrQ3smbm3mv8eSPIgA/KPPMX/j8+/jeq2/h537mJ/c+5q//4V/wu3/8t0UunznhVNzBZ0WeJEAKJ/KmOtNGsC+0kGY5G/e03JTrIKAUhlJdOe69ffsWzdagVFOtP//Dj/C1X/nK0cf93p/8Hb7/9/+c9/KL2bjXyPpgS3LNrGc27t3TdS2LXdhGfo+tSNyqD3tO8KtkAuWu5kUvkJweXhe9wJe/dD+TYAHA73zzl4o8xUPJxcpKH0yBOMZU58Vm496VnPY0ADQBPEeJ15QmlnDgVDmVwuC7YC2gEsejssm+idOKUHCL+NHXv4o//f3fzPz4ZmtQ5GlyVe0bLkVyAp1O6xDy2kqcWJ19zJ5nOYkyRWC93V5DnQxqORR5DwAktjEpcoEvf7GWg42kC0RWhlDKTvYgd/jKmY17sbiwMwA/AeAZ1GlRlb+fS1sFq9kaNCSE8QP4L1hTHMm5KsKq9lCCgLO8F/j1X/0K/uwPPsr8+IJOa/XXs1pLeVN+UubJPMd4z6mtgH4EPUf71saxAkphyJxzVYTSBdNf+Pz7mP7NdzI99kfTH+O3nv9lnstvcz0b99rHH6aQDGLfT2LKMIXKj4lNLwRYiViU+sgT1J9AvVFizcsqTWC93a6hAu2V3Qy3ResBVFAw153gu9/+Gp598xePPu63v/NX+Meb0ruCPCkQEUqmcwTCFOuR9bHhtayQ12OEdZb+NnOoPKwrGzs5BJTCABTMuSrCO00Aiwaxv/fqW/jlRz+99+cF0x12MZX4SCaarcEV7M4lso1kxmAiYnOjq3GQwFIYgJqHL+/rXDpHASvbffpr+OjrX8VPpYLzP5r+GMPLH+pwWGkyd6QsGqsjKxZYC1iuPmch4uEgiUOUzrkqwj7RaqNEHd8XPv8+fv5nv6hbqNIwBcIc1xAnRhe2JrAUhiWUWBm5ie2dxuNAEDuzJS0aqyNHCd6FBTieTmvOVREOiVYEu4PYucaOMQWicoKLhQWUwgBYNAj34NxDB+r4LmfjXifrg5utwQ3CsO82MIUSMe9cWGApDJXmXBXhmGg1oI6Ubb6TMAXCDSZYuzDjd+sieDwAdR+V51wV4eiEaQeC2JPZuBdlfbADsboQSLaSMRwQsQDFqracqyJkEa0HUG7LZivMFAi3SUTsBpYkuMrrvg0VswoppFBrzlURjooW4EQQO2/PrSHCSfxzlSmUiN0AuKlDyFJC1UZ4CclGcq6KkEm0ACeC2EyB8J8F1O8tls9zAPOibzTpdNvAutbR5td3VRjNuSpCHtGKYHcQO2/CKYe8+sUSm/3g32x93cB6BuIDhClQ2xjPuSpCZtECnKjjy5sCMYfdsTpCqsCanKsivHf8IRtY2acoxdOcI9Fs//cQopMlVEfXM1cFC8gpWhI7eFnNUrSROQlO9vGFOrYS4hjXUOETa5JEi5LXaQFKFGweHPFYMpazQrdFfGYBlYC9d8ala+QWLfmH2/5Gz+O2bqB6lhPiGy9n417Dhrw3nRRxWpBETpsHR+QdO9aF3e6RkDxMoOYp9E0vpAoKiZbQ0bWIiuhLPtZRxD06v9cnwbOEptmCNlNYtMqMHauJXGPH5K5ks3sk5BCvoQLtziSJFqWM0wLsd1sfS61hVvoVrYOQqqhktqDNlBItsaCv9SylMkZZHyixOpvdIyEJXuRcFaGs0wKUO7E5iP1YSpCy0q9oHYTowpucqyKUFi1Hgth5UiBiMAWC2Il3OVdFyFV7eAgH6vjy9tyyvWMrCQvr+1zVhY7tYYL1Cac5UiDmsN89kjDwOueqCNpEy4E6vvvIJ6y2lysRvwki56oIOp0WYL/bepE1BcKRciXiJ8HkXBVBq2g5UseXJyg/gsqDIaQOgsu5KoJupwXYX8f3Yc4UCLotUjXB5lwVQbtoOZIC0c/6QEmBuK5sJSR0rqEmpdv+nrGGKpwWoETL5jq+xzJhKCt0W0Q36ZyruenFuEQloiVuq1/FtTWSpwvEHPZ3bCXu8BLKXcWmF+IiVTktF+r4HoIpEKReVjlXDLQXpzLREvoVX78s3ZwpEP1KV0N8ZQlVkcGcKw1UKloO1PHl7bm1L1Z3CXVUfQ9AE8Az+Z7NcT1SD0nO1cj0QnxBW+3hPsTJzCp9kvJ8kPWoeWto7dFR4jLSLEp9sJ4xDKYAuoxb6ady0QKAZmvQB/Ci8icqzmQ27kVZH9xsDUYARkVekCJ6ycfjvH+fWM8SamozUxgqoi7RegBgDrtdxjfqLpuQ/5co9cFR7W5zDeWu5qYX4jO1iBYASF7UJ7U8WTEWs3GvYXIBspWO5KMNu0WerFlAhQli0wsJgdpECwCarcEN7HYTVvUs2oqHfWh0MWQfLwEMmcJQH3WLVoR1ENtGllAnPVa+AFPxsDbsFv8QOHoIQ6qhVtECgGZrcAW7XcPr2bhnfdnOVjysDbu7xvrEEipuNTK9kFAxIVoN2J8C0XTtDpqKh7XB1IqqeA11MmilEw+F2kULAJqtwRDAx7U/cXZypUDYSCoe1gZTK8rCnCuLMCVaLqRAPPHpRSrxsMSFMR6WDeZcWYgR0QKAZmvQBfDKyJNnw3gKRFWk4mGJiDEe9i7MubIUY6IFODF27HkId1mJh2dg7SoAAAK3SURBVCUCFsFuB1w1zLmyHNOiFYEpENYh8bBExEKKhzHnygGMihYANFuDGHa/MaxKODVBszVIuzAf42HMuXIIG0TrDMBnRhdxHOdSIKpC4mFpEbN5e38M5lw5iHHRAlZdE56aXscBrmfjXtv0ImzE4XgYc64cxRbRYgqEJzjQeucSSqzmphdCimGFaAFO9Nyazsa9M9OLcAnLWu9QrDzBGtECnEiBeMb4R3G2Wu9EqP53vQAwAk8EvcI20erA8p5bUKOf+AbQQEWtd5YArgBc1d3UkdSDVaIFMAUiZCQelghZA9m2k0sANwBiADHjjv5jo2hFsD/h9IyxkXqQuNjOWCIFKkysEy3AiRSIy9m41zG9CEJCpOphrUXpw+5pzk/FERJCasZK0ZKtl+2Fyn3TCyAkRKwULWHfNGdbeEy3RUj9WCtaklbQN72OI7C0h5CasVa0AEASOaem13EAZsgTUjNWi5Zg/WQcQkh9WC9akotzbXodhBA7sF60BFvd1tz0AggJDSdES1IgXppexw5Y20ZIzTghWsIQdiWcLliQS0j9OCNaFqZA2LplJcRrrKw9PIQlPbfYV4sQQzjjtFJ0DD73EhQsQozinGhJCsTEwFNfQs1AHBl4bkKI8DnTCyhIB8CspufiTDxCLMK5mFZCszUYAvi4wqfgeHRCLMRVpwWok8QO9I8dW0JNbbG9NQ4hQeJcTCtBUiB0C8tLqLgVBYsQS3F2e5igKQXiGmo8+rz0ggghleLy9jChg+KDMKZQYhVrWw0hpFKcd1pAoXmJSyixGlWyIEJIZXghWgDQbA3aUNOEDwXml1BxME4cJsRRvBEtYDUjrw21ZTzDWsAmWE8dnhtZHCFEC16JFiHEf5xNeSCEhAlFixDiFBQtQohTULQIIU5B0SKEOAVFixDiFBQtQohT/D+A/iF50Z3poAAAAABJRU5ErkJggg==" />
                                </defs>
                            </svg>
                        </div>
                        <h4>物件提案ロボ</h4>
                        <p>希望条件に合う物件をAI評価付きで自動配信。</p>
                        <button>買い</button>
                    </div>

                    <!-- Card 3 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <svg width="70" height="70" viewBox="0 0 70 70" fill="none"
                                xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <rect width="70" height="70" fill="url(#pattern0_241_242)" />
                                <defs>
                                    <pattern id="pattern0_241_242" patternContentUnits="objectBoundingBox" width="1"
                                        height="1">
                                        <use xlink:href="#image0_241_242"
                                            transform="translate(-0.00166667) scale(0.00333333)" />
                                    </pattern>
                                    <image id="image0_241_242" width="301" height="300" preserveAspectRatio="none"
                                        xlink:href="data:image/png;base64,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" />
                                </defs>
                            </svg>
                        </div>
                        <h4>土地情報ロボ</h4>
                        <p>希望条件に合う土地物件を自動でお届け。</p>
                        <button>買い</button>
                    </div>

                    <!-- Card 4 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <svg width="70" height="70" viewBox="0 0 70 70" fill="none"
                                xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <rect width="70" height="70" fill="url(#pattern0_241_249)" />
                                <defs>
                                    <pattern id="pattern0_241_249" patternContentUnits="objectBoundingBox" width="1"
                                        height="1">
                                        <use xlink:href="#image0_241_249"
                                            transform="translate(0.0621575 0.0606882) scale(0.00297709)" />
                                    </pattern>
                                    <image id="image0_241_249" width="301" height="301" preserveAspectRatio="none"
                                        xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAS0AAAEtCAYAAABd4zbuAAAACXBIWXMAAAsSAAALEgHS3X78AAAQuklEQVR4nO3dwXXbxhbG8c852luvgggVRKkgMhqwUoHpCkJvsQmzwTZ0BaEqCNUATFUQuQJQHUgV6C0wkCGKlEASmJkL/H/n+Jwn2iLmME+f7gzuDN49Pj4KAKw4CT0AAMOXpPmppPONl8/cn21uyyJbbvuLd1RaAHZJ0vxML4PlYuPrbf/mXNL7Iy//IGlWFtm8+SKhBYyQq3wuVQXQmXv5VNIvgYb0mquyyCb1F4QWMDJJmk8lzXR8JeTTU3ARWsCIJGm+kPQp9DgO9HtZZMufQo8CgB9Jms9kN7Ckqjqk0gLGwC2ol6HH0YGESgsYh1noAXTknNACxuEy9AA6QmgBQ+faGyzdKXzNmtAChm+zE90yQgsYgdvQA+jQLXcPgRFI0vxeA5gilkX2jkoLGIetm4+NuZEkQgsYh1noAXTgXiK0gFEoi2wt6a/Q4zjSrURoAaNRFtlM0lXocRxhLRFawKi4kxK+qDqrypq1xN5DYJQa52ldqjpHq+leL9skbt3rl5L+6H2A2/2vLLJ7QgtAK0maTyT9E+r6ZZG9k5geAmghSfO5AgaWXLuDxIMtALzCTSPnCn8O1339PwgtAFu5wFopjnPjn9bYmB4CeCFJ83NVQRFDYEmEFoBdkjS/UFVh/Rx2JM88TQ+5ewjgSeg7hLvUdw4l1rQAOBE/qedZIyyhBYycW3BfSPoYeCi7PGt0JbSAEXNP6VkqngX3bdbNLwgtYKTcHcKV4j8ccN38gruHwAi5Bff/1H9gfe/gPZ5NDwktYGTcgnvfdwgfJH1QVckd6775BaEFjESS5qdJmq/U/x3CO0kXZZGt1MGTgNz7PCG0gBForF/91vOlvks6L4usntKdHfl+L879IrSAgUvS/FJ+9hBeq6qwmtO5Y7vqXzz+jLuHwIAlaT6V9LeHS125U1Gb1+7iIbHrzRcILWCAPB8p87ksssWW1zdPRD3EevMFQgsYGI8Now+SLjcXyhsuOrjGi/dmTQsYEHdCg48jZZp3CHc56+A695svEFrAQLj1q2/y0zDavEO4y9mxF9p2DaaHgHGe169eLLi/4tiF+LttL1JpAYY1+q98BNaXPQJLOr7iW297kUoLMMr1Xy3U/3TwQdKkLLJlz9fZtHX6SaUFGJSk+UzSv+o/sOoFd9+BJW1ZhJeotABT3PrVUv1vx5GqBffNDvd9v/+Yu5irbS8SWoARbv1qKT8PnNhnwX2XY3rFHna1UzA9BAxonH/lI7D2XXDfZa4tG55bmu36C57GA0TMczvDWx3ue3M3C/7d89tuyiK72PWXVFpApDy3M9QNo6su39Qt4H/ecxyXr/0DKi0gQh7bGaTqSJnJEQvub3Lbi2bafQPhQdK8LLLZW+9FaAGRSdJ8LukPT5f7q01QdMVVj5eqtvicqurFWktatg1NQguIhOfHeYVqGD0aa1pABNx00MfpDNKP/itzgSURWkBwbjroo7td+nEk8lsnNESL5lIgkABPd/5SFtnc07V6Q2gBAXi+O9h5/1VIhBbgkWsWncnf3cHvqgJr7el6vSO0AE/c7f6F/E0Hu9g/GB0W4gEP3N7Blfy1M3weYmBJVFpAr9x0cCHpo6dL3qmaDpq9O/gWKi2gJ40n4/gKrGu1e+CEaVRaQA/cyaJ/erzkINoZ2iC0gA653quF/JwsKg2snaENpodARxpbcXwF1o2kszEFlkSlBRzN80F9Na+nM8SE0AKO4BbbF/JzDLI0wungJqaHwIHcYvs3+QusUU4HN1FpAXsK0NkujXg6uInQAvaQpPlU1d5BHxudJaaDLxBaQAueH5Jau1EVWL2d3W4RoQW8wfMxMjWmgzsQWsAOAfYNSiPYO3gs7h4CW7jqai2/gTWKvYPHotICGgIc0idVi+3TssgWHq9pFqEFOAEaRaXqZNEJ1VV7hBZGL1B1JUlfyyKber6meYQWRi1QdUXv1REILYxSwOrqWtV0kN6rAxFaGJ2A1dVsLAf19YnQwmgErK5YbO8QoYVRCFRdSXS2d47QwqAF6mqXqs72CYvt3aMjHoMVqKtdkr6q6mxfeb7uKFBpYXDcwyXm8h9WD6qqq6Xn644KlRYGxZ135fNZg7VrVaeKElg9o9LCIAR4dFeN6sozKi2Y585qL+U/sG5UrV0RWB5RacGsgG0MNIoGRGjBnIBNolJVXU3KIlsHuDZEaMGYQEcfSwOrrtwa4KWkC0mnni+/lrSStDxkD+a7x8fHrgcEdC7gQrs0oOoqcJW66aBfBIQWoucW2v8McOmhVVenqiocn89rbOOqLLJJ239MaCFaARfapQFVV7UkzW8VX2DVWh+ISGghOgH3C0oDq65qAavVfXxos/WJPi1ExXW0rxUmsOq+q0EFlmPhWOdWY+TuIaLgpoJzhZm+DPppOO6z9X239RCtflFRaSGoJM1PkzRfSPqmMIFV7xlcBLi2LxehB9CWC9hXUWkhGDcVnClMFcCeQaMILXgXeCooVeddzXi4hE2EFrxxDaIzSZ8CDYHTRAeA0IIX7pb7VOEWhDmrfSAILfTK7RWcK0yDqFS1MUx5Es5wEFroReC9gtJAm0RBaKFjkWzI5SnOA0ZooTOBWxgkFtpHgdDC0QJvbK79JWlOdTV8hBYOFsG6lTTA0xjwOkILe3PrVnOF67eSBr5fELux9xB7cf1Wa4UNrK8a/n5B7EClhVaSNJ+oWmQPuW5FzxUILbzOLbLPFHbdiqkgnhBa2CqSRXaJzc3YQGjhmQg2NdeYCmIrQguSnu4IThV2U7PEVBBvILQQQyd7jQZRvInQGrFI7ghKNIhiD4TWCEWy7UZiryAOQGiNSCTtC1K1bjXnUD4cgtAagYjCSpKuVC20s26FgxBaAxZR+4JECwM6QmgNUGRhdacqrHhUFzpBaA1IZGHFuhV6QWgNQCRHxTSx9Qa9IbQMi6iLvXataiq4Dj0QDBehZVCEYXWjqrJahR4Iho/QMiTCsLpTFVaL0APBeBBaBkQYViyyIxhCK2IRhpXEpmYERmhFyLUuTCVNFE9YXamaCq5DDwTjRmhFJLI+q1rUJzAkaX4p6TzQ5W9pmvWP0IpAxGEV7R3BWM4AS9Kcjn/PCK2AIg2r6I+LSdJ8oXg+s58l/Zuk+VVZZJPQgxkDQisAd+rCVNLHwENpMtG+EFlgNX1K0vy+LLJp6IEMHaHlUWRHxNRMhJX0tH4VY2DV/kjSfBlzlToEhJYH7ljjieIKqwdV+xUttS/MQg+ghZmki8BjGDRCq0cRncHeZDGs6vW/X0KPo4XfkjQ/tfTZWkNodazREDoRYdWlUG0NhziXtAo9iKEitDriKoGJ4upel+yHVc1SaKFHhNaRIm1bqLHlBoNDaB0o0juBNbbcYLAIrT25xfWp4lwUJqwweIRWCxEvrtcIK4wGofWKxnrVpeJaXJd+LLAvCCuMCaG1RaTbbGpDuRsIHITQaoi0GbRGWAEitGLur6oRVkDDaEPLTQEnirO/Sqo2Mi9EWAHPjC60Im9ZkAydugCEMIrQivTM9U2EFdDCoEPLnb80UZx3AWtRH2sMxGZwoeUaQSeqKqsY7wLWCCvgAIMJrSTNz1UFVawL6zW614EjmA4tV1VdKu6FdalqW1iouhO4DjsUwDaTodWoqmLcXtNE2wLQMTOhZaiqkrgTCPQm+tAyVFVJLK4DvYsytIxVVRKL64A3UYVWY2uNhaqKo2GAAIKHlutWr6uqmPuqaqxXAQEFCy0j3epN16ruAq5CDwQYM6+h5RbVJ4p7D2DTg6SlWK8CotF7aDW21UxkY1FdqqaA9XoV/VVARHoLLYPTP6maAi7KIluGHgiA7ToNLYPTP4ktNoApR4eWwbt/te+qpoBLpoCAHQeFVqP5c6I4n7D8mitVU8BV6IEA2N9eoWV0nUpi4zIwGG+GlrEu9U0srAMDszW0Ggvql7K1TiX9qKrYXgMM0LPQcmE1l711KomqChiFp9Byj9aay9YUkKoKGJkT6VmFZSWwqKqAkaorrYXiDyyqKgA6cW0MMe8JpK8KwJMTVXcIY/NdP6qqQfdVuZaSqaQL+a92HyStVO0KWHi+NnCQE0lnoQfh1HsAF2WR3QYeS+/croKlwt6pfa+qUfhjkuZTSZMxfPawLfjJpaoW1Uf1m97t17xVXOuIv0haJWl+QXAhZqFCq57+LUe6qL5UXIFVe68quM6GPi2HXSeS1vIzRRnV9G8XNw2L+cbHe0kzVetsQHROVC3EfurxGleqKip6qioWwmAiG+PECP3k1pLuOn7fG0mfJf2vLLIJgVVxa1kW9nK+d3c1gejUa1oTSd+OfK/6XPWxrlO1cRZ6AHs4V1WFA1E5kaSyyFZJmn+W9M+e33+nalF51OtUA3UaegDANk93D8siWyRpvla1WP7aFKZ+rBZd6gC8e9by4ELozJ34cKHn05m1WFAHENjWPi23OL/wOhIAaOGn0AMAgH0QWgBMIbQAmEJoATCF0AJgCqEFwBRCC4AphBYAUwgtAKYQWgBMIbQAmEJoATCF0AJgCqEFwBRCC4AphBYAUwgtAKYQWgBMIbQAmEJoATCF0AJgCqEFwBRCC4AphBYAUwgtAKYQWgBMIbQAmEJoATCF0AJgCqEFwJSTLt8sSfNzSaddvmcbZZGtfF8TQBhHh1aS5qeSpu7P+6NHdNgYJOlK0rwsstsQYwDgx1HTQ1dZrSX9qUCB1fBJ0n9Jmk8DjwNAjw4OLRdYK4UPq01/J2k+Cz0IAP04KLTclHCp+AKr9meS5hehBwGge4dWWlNJP3c5kB7MQg8AQPcODa1Jl4PoyW9uCgtgQPYOLTc1jL3Kql2EHgCAbh1SaVmqXrz3jAHoFx3xAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYQmgBMIXQAmAKoQXAFEILgCmEFgBTCC0AphBaAEwhtACYckhorbseRI/uQw/AsNvQA9iwCj0Awyz9HLw51r1DqyyytaS7Q0YTwCr0AJrKIluFHsMeYgutdegB7CG2z24VegAtPZRF9uZnd+j0cHng9/l01+YDCOAq9ABauHG/nKLhxnMTehwtXJVFFlVl434OLBQarXLl0NCaSXo48Ht9mYYewA6z0ANoYRZ6ADvMQg+ghXnoAewQ689D7UEt//seFFruN0nMH8JVWWRRVoOuYvgcehyv+BrrNNaN62vocbziS6TVvdzPQ8xV/rRtdX/w3cOyyBaK84fvqiyySehBvCbiz+5rWWQx/zKSG1+MwfWlLLJYqyxJkvu5iDG4PrufiVaOanlwF0okXR/zPh25k/R77IFVc5/dr4pjneZG0ofYA6vmxvlBcX12UQdWzf18/K441riuJf26T2BJ0rvHx8dOrp6k+Zmkc/fHp7Wk21jL8jYCf3ar2Bbd9+E+uwtJZ54vvZb9z67+/9yZ50vfqvqZXR/yzZ2FFgD48H+O1Z/n3YLJawAAAABJRU5ErkJggg==" />
                                </defs>
                            </svg>
                        </div>
                        <h4>AIマンション<br>査定</h4>
                        <p>個人様宅までマンションの査定を実施。</p>
                        <button>売却</button>
                    </div>

                    <!-- Card 5 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <!-- REPLACE THIS SVG WITH YOUR ICON -->
                            <svg width="70" height="70" viewBox="0 0 70 70" fill="none"
                                xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <rect width="70" height="70" fill="url(#pattern0_241_254)" />
                                <defs>
                                    <pattern id="pattern0_241_254" patternContentUnits="objectBoundingBox" width="1"
                                        height="1">
                                        <use xlink:href="#image0_241_254" transform="scale(0.00332226)" />
                                    </pattern>
                                    <image id="image0_241_254" width="301" height="301" preserveAspectRatio="none"
                                        xlink:href="data:image/png;base64,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" />
                                </defs>
                            </svg>
                        </div>

                        <h4>セルフィン</h4>
                        <p>物件が貸しやすいかAI自動判定。</p>
                        <button>売却・購入</button>
                    </div>

                    <!-- Card 6 -->
                    <div class="tool-card">
                        <!-- REPLACE THIS SVG WITH YOUR ICON -->
                        <div class="tool-card-icon">
                            <svg width="70" height="70" viewBox="0 0 70 70" fill="none"
                                xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <rect width="70" height="70" fill="url(#pattern0_241_171)" />
                                <defs>
                                    <pattern id="pattern0_241_171" patternContentUnits="objectBoundingBox" width="1"
                                        height="1">
                                        <use xlink:href="#image0_241_171" transform="scale(0.00332226)" />
                                    </pattern>
                                    <image id="image0_241_171" width="301" height="301" preserveAspectRatio="none"
                                        xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAS0AAAEtCAYAAABd4zbuAAAACXBIWXMAAAsSAAALEgHS3X78AAAgAElEQVR4nO2dS3IayRaGf3fcudzBAgwjhpZXYMwGLK/A5WABLU+ZdGlwmRovgOjSClraAIYVtBgyAhZAXLMC30EeZKwWj6o8+aL+L4K4fS0qMyWov06ePI8XP378ACGEpMJvoRdACCFloGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCkoGgRQpKCokUISQqKFiEkKShahJCk+E/oBVSh1R00AVwDuATwVv55BeABwB2Au8W4/z3M6gghLnnx48eP0Gs4mVZ38BJADuCPI2/dABgCKBbj/tLxsgghHklGtESwJgBel7z0FsBwMe4/qC+KEOKdlETrAeUFa5cpjHjdKS2JEBKAJESr1R1cA/iiNNwKZotJvxchCZKKaC0BvFIedgOggLG+lspjE0IcEb1otbqDDMBfjqe5hXHaTxzPQ0hQGqP5JYAM5uT9Uv75QV7FuteO3vebgmjZ+rLKMIURr8LTfIQ4pzGav4QRqmsc37HcA8jWvXa0rpOoRavVHXQAfAsw9Qo/Qyai/fAIOURjNL+CEav3JS/dAOjEanXFLlp3KP8H12QF4IrhEiQVGqN5E0aoMtj5gTcAmjFaXNGKlkS9L0KvA/LUoXCRmGmM5hmMUL09/M5S3K977SvF8VSIWbQKAB9Dr0PYAGhyq0hiQpzq1wCuAFw4mqa17rWXjsauRJS5hxL9HpPCX8D4uLLA6yA1R5zqVzBi5eOA6lpe0RBrlYdruHtyVOWjiCkh3mmM5p3GaF4A+B9MCJCvE/XM0zwnE6toZaEXsIdO6AWQetEYzV82RvMJzCl6CHfJhfjLoiG67aEEk9qcetzDBMoBwNaU1oqmv4QpfUOIc8RnNUH4XccVTPZIFEQnWqhuZc0AZM+c8l23uoMcwJ82iyLEJ+K7KhBesADgfWM0b8bikI9qeyjBpFWObGc4EJawGPdzAJ+qr4wQ7/hytJ9KFnoBW6ISLVT/w2THwhEkNee+4vhblpbXE3IUsbKiOrEDRevfSDBpFUfjrETgZ15h/F0mltcTcgou466q8krSgoITjWih+pPlZMe4ZVT7lCVsiCeaoRewB4rWFol/ykKv4wh56AWQ2nB5/C0ncwvgA4B3MH7dW4uxPsrWNSixnB5mqG4ON099o2xBq7BirS3iEY10sRmAq2dO/IrGaD6EOZms4ujPYLJDghGFpQU7p+NViUj1rOIcecXrCKnC0vL6FUxpmWfHkZIzVzA5tWXJKq9KieCipRBMeoETRE+ErYo4blgUkHhmYnn90SJ+ImhV7ofXEvQajOCiBR3l/lPE71l22o9V2YIGNYVJ/Vj32hMYa6kKK7n+lHmKinMEDccIKlqt7mC3Q7Qtf7W6g0mrO3g84Wh1B02Jhl+ieqAeRYuEoGq62LLk+6cV5rgK6ZAP7YjXVuy3AN62ugOt8W5ZQ4sEYojjndRDcYGA+YjBLC2LYFKf0MoiQRCfUxUrqFny/VX9U1nF66wJuT3MAs59ClOWWCaBKSpc86oxmndOeaOUnKkaavRW6tF7J4hoWZzk+YRWFgmKOMqrhCUMj/mc5Od5hbF3CXIPh7K0Ysyt2mW1GPdZN4vEQJXv4WsAk33CJRbSBPZ15oKk9YRyxOeB5j2VPPQCCBGGqOb7fQ1gKSWad4Vv2wtRw2h41RjNr9a9ttcHvHdLS0IStCqJumADViclkSDR67OKl1/AnEB+23n9Ad1dTqY41kmE2B5G78timAOJjJj9q+99O+S9ipZyMKkritALIOQJsVv+Xn1bvi2t2K2sW9bMIrEheYQ2JWVc4/W+9iZaDCYlxIoi9AIOcHJsmAY+Tw8zj3NVgcGkkSEPug5MlHcHpiXcoRzSGUwtqglMDt7DuXym61570hjNV4j3ECuDp3LkL378+OFjHrS6g++IOzbr0zmWoBEnafPI2x6OlTLxhZwuX8GIlMYNuoG5me4ATFLe/jdG8xxxt8L73cf3yItoSdmYv5xPVJ3VYtxvhl6EDSJOl/LqwAhV2Zt+CtPoduIz9kYsqmvoxQ8dYgaz1bpLTcDkM16EXscBPlmUuzkZX9vD3NM8VUnOlyU+hO3rEjo3+1t5fYeHEyvpc3kN4L3ruXZ4DeALgC+t7mAKoEjFwl732svGaH4Pv3+vMlzDg+/NuaUlX8xvTiexYwOgaRObJekSTQkEVGfHiurIy2UTT+dPSwl9GSKe8JcNzM02jN36kjZef4dexwHeuLoPtvgQrQni+XI+x9fFuG91ZLvja1hBfCcwW6zSQrjjg+rg53bPl/PVqWBJonzVtBRfRG99NUbzmP3DX9e9ttMQCKeiJb6KmPfgANCyfbo2RvMlnheWFeQUC4c7rHTkf0OKu2vBuoZxE8R6sz0lWutLuunEWiBws+61nVY1dS1aBeJ+qt4uxv3MZgCpSRTzIcMxNjCdW5yY9GJd3SFua/sYU/x03gc/Za27Q95ZcKl8WWMWLEDHaRh7lP8hXAtWB8bSTFmwALP+vwAsW91BsduHIAQWVU19kbkc3JmlJQ0lYo4pmS7G/Y7NAHKCF/MhwyFcC9Y1zCndubKtBjJBgPivBCz81r6+i7a4DHmI3QIpFMaI/Xfcxwqm+7C6YCXibNfgAuZ3/AgAre5gBeO73L6228jt33hbi70prwfLQpN3MH/nWH2EGRyFOjmxtOoQTJqAX2EfMxgLS903s9Nf0mVIxrmgYekXiPfhsFr32k0XA7vyacVugWgEk+YKY/jGpWBdwq6/ZN14K6frNsQcFP1KYsrUURctcb7G/MXdHmVXRoJJgzpjKzCFO8G6QvUO3nXG6uEu2/uqnah9kLkY1IWlFbuVpXFsfY20btDbda/tSrAymAjtlP4esaDx4IvZ2nrvohO1qmiJuRtrXtSWXGGMTGEMX9yse+3MxcCt7mCIuH2XsfNKIXyi0FiIQzLtAbUtrVx5PG3uFaLfM8Rb0+gpn9a9du5iYAkcjjUqOyUym4vrWNVUTbTk5Ch2P4+GKZ0pjOGaDUziaqE9cKs7eCn5pLGeWqXGewWHfMw15F81RvPL4287HU1LK3Y/z2wx7k9sBpBg0tiju2cALh3GYE0Q/98gNawe9lL7LGaHvKq1pSlameJYLqiDlXULc0K41B6YMVhO0bipC4UxXHGl6ZBXES05QYrZz7OyLTUiwaQxb4k+r3vtjDFYSfJKQoVsKBTW4YoLKLqOtCyt2MMcCoUxYv0dVzD+KydH3yJYE8S99T8HMpuLxbq+11iII9TuH2vRSiSY1OqGFtM2U1mNLvdw5L8CHj/bCShYPriSLbgNMTvkX2t1otawtDKFMVyiEUyaIa4bdwMTznDlqvuJbPm/Ia7f+5yx3kLJafFGZTVuULG2rEQrkQasucIYMW0NpzDWVeFqggQS3s+Vc3fIZxqD2FpaMd3MzzE9s2DSG0nHWbqaQIJGKVhheC0+RBsKjYU44kLuJysqi5bsv60X4JhcYYxMYQxbZjDO9tzlJAmUx64Dmc3F4t+c6SzFCdaniDaWVoa4/R0rhWDSS4QPpLxZ99rOnO3AY5T7AyhYMZApjBF7EnXTZgAb0Yp9a5grjBHyd5zClKzNXU4ifskJ4j4BrhMX4lO04Q5xO+Qzm4sriVYCwaSbhINJNzCBok59V8BjDNYDKFixkdlcLCfKMYc/ZDYXV7W0rCb1QKopO7cwnaqdm/fy4PkHcW/x64pGVdNCYR2ueCV5vJUoLVoScBjaz3MMjWBSn1vDFYB3rtJwnsITwiTIbC5e99oTxJ1EnVW9sIqlVXkyT9wqBJNewY8FsoFxtDflS+aUVnfQpMM9GTKFMWJ2yH+smkRdSrRqFEyqMcYx7mG2gj7m2lrI9F+lA6ua7qGspVVpEo9oBJNewf0hg9MUnKdIWeRzS8lZwZywPn3FfGpWFtu0nu+IO4k6q3LRyX0PJZh0ibi/+B8sG2CiMZpP4NZn98llCs4uYhnfIW3raoZfm6A+nLL9l5PRJoCOvFL9G/xu4+6Qh/DfiuvR5k3ZGMQyHaZ9+XmqslIQLNfBpDceBStD3B2I97HCr+3mK92wi3F/K3J3wKOAX8EcsMQcrvOUDBa+qXWvfdcYzVeI93e+RkmLq4xo5WUGDkCuMIbLE0PrEjmnIBZxgfi7Iu0yg1nzRMRGHXEbDAEMxb+XI/5TcMB8J22/N3eItwlJ6S3wSdtDcQjGbGJuADQtzegm3La5/7rutZ2GUcjnVCAN62oFs9bC1g9ZlYTE642NmHv4bttSymVyqqUVe8rOUKlmlkucRSiLdZUj3qfplg3M36GwzQvVQNbQka10jjPaQu2y7rWXjdF8injFOUOJk86jp4fi0Iz1l91SKIzhVJhdxWHthDLELFgzAJ9grOEsBsHaRVK+LgHcIM7TR42qpoXGQhzxtkwS9SmWVuxW1q1SzawUtlSPyJd4iHjj5rZW1dCVn0oTsdRzyRbIEdffdVvVtKg6wLrXLhqjecwHM9c4UWsOWlqJBJNqOLdzhTG8Ib6rJeL8bJ5aVdEL1i6LcX+5GPczAO9g4r5iQcN4iDmJ+mSH/LHtYWa3DudMbW8KSdx07svQ7PuG+MJPNjDJ3m8W4/7lYtwvFHyMQVmM+5PFuN8B8AFx5PC9Vkiijjmt55XElB1lr2jJ9iP2rWFKVpZa3zexBD4hvP8laavqFBbj/t1i3G8iDn+X1f2YQFXT7JQ3HbK0YnuaP0UjmLQJf4cMmeZgO85j31uYFYCvAFrnYlWdwmLcz2Ei7G8DLiNTGCNma+ukqqaHRCtXW4obUrKyAHNC0tEcUPwvHbj3v2yF6s1i3G8uxv3rULFVIVmM+9/Fyn2DMP6uC4Uk6pj9WsAJO5Jng0vlGP2bgwVpoRFM+hLA//SWdBIzAB2HvQo7ME9jWyt5A0mjgcMo9dQRARnCb3zX/WLctxKuxmheIM5DHABYrXvt5qE37BOtCeKOzfq6GPet9veN0TwH8KfOckpxv+611fxb+xAB68BsIV9i/+c5A/AdRqCWMAnJFKkStLqDHMbf5Mud0rKxdMXij9koeXcorvFfoiUnFDGH/AOWHxoANEbz7wjns7vxVUeL+MFz3NyN+Ngq0xjNl4g3A+B23Wtn+374nGgViNd0BEwwaWYzgASThi437K1EzbkhlkJTXk+ZAFi6bgqyD8kgGcLtTmUlJ5qVCbjTOIUNTIHMZ90ov4iWPC18+3nK8k6hn2EsFTxL1xKqG+J7vMLPre6pn9u2ckThq9jiLh78XVb3QcpJ1E9PD2OPy5oqCFYHcQgWAEykhhfZoTGaNxuj+bU8XP4HYxV/RLnP7TWALwCWjdHc+/faQ3xXZnOxWKIxVzXd+5mlJlqFwhgx/Y4XoHABMBbVjlAtYARH4+FyAeBLYzRfaoecnILD+K6PZ55E/XrfffG4PZTyHKH9PIfQ2Mc3EadJvIEJhajdVlFSNzL4K1o4BZCF8Hk58Hd9Xoz7VvGKgQ+kjvFsDbpdSyv3t5ZKpBZMWoZaWVyy/cvlBOtv+K2y+hbAojGaD5XzQY+yGPcflPMZM4UxCoUxXJE9948vfvz4Uadg0iXifapsOdtTxQBW1TE2AHIfHb2fQym+q3ZVTbeWVkx+nufQyG/zGfxnw19S9+gsCGxVHSMWf9dXi2Fsk6iXiKsEz1Oyp//wovnuv03ErbSATjDpEvEG0z3HFIC33ojaSCzcFeISqWOE9Hc1YbZqZf1dm8W4b7XNjSRu8RCt3c/kNyiWTHHEvVJl0pQECzBf3uWpNYZioDGaX4qv6DvMTZCSYAFh/V27ye9l/F0Xcohmwx3Cl905RLb7f36DyUuLGY2tUuzb331cAPi7MZpPytTQ9smTmKp/YGrVp7ANP8QfCBffNZFT8s84XUgymznFmo+5+kO2+39eNN/9N0e84fyzxbhvdaKWQHJoGb7COI6DbhlFQLdO9VgCdV2xgtkyTnxPXLLLkm0S9SXMQydWPqx77TvghG48gdGwsjKFMWJhawEUvi0v2frlDoI/Y+cVgG8hrF2p33UNoIXjznKNqqYxlJXex6Ob5EXz3X+vYb6AsXHOwaRazGCEfaLtPJa/XUdesVex9Ukwa1dCkwo875/VuF9i1YItv6977e8xnx5qlN8YIu5+gJrMYCocPAB4KBNd/6RqQgcmMZkitZ8NgGGo8kKt7uAaZtv49DP6YFOCPFBhzDJ8Xvfaw21w6QRxFf2zDiYFgMZoPkFcv5dvNjAi9hwvUY/tnUtWAK63vhaf7PF31aKq6Va0mjBf7liertY1s4DHJ8cEvDmJW6Yw4uU9d/SZ+K7fLTNHrmCCgGPlzW+AiRFBXGEBucYg4nfoIO4YFJI+bwH8IwckoeO7MpvxxGqM2iH/eHooLak+IPwNbh1MuosIVzIBmiRpPsKc7ua+J5Y6c5cw+bW2FApjuOLlvhrxOcKdGFlXJn2OmjnlSXiC+btsifzUffpsNx7glw7TGfylwFgf2+4joSoP5LwI5u+yoTGa3yHONKybvcGlEtiWi4h8gp922rmrgWWbWCVYdQXzxYu5nTiJl2D+LktitRCXey2t55Dgtmu4UWDrbPVjnGD2TvGzSenDvgBCSXm4xM/gy9SSsevKCuZmnMD0etx+hr4siqDxXWWJtKppq5RoPV5l/F7braPWL2UdTHoKz8RurWAsvLuqUc4SnJkh3viWurOB2aIVz/1QHmY5/H1+Sfi7IvQD36577aySaG0Rv1cGI2A21oZ1YvSp7KQqzGC+OBPFsZvw++Unx5nB1N8/+kASC1qzhvsxovZ3RZZE/dgL0Uq0dpGaPhnKf+AzAB2FyqQnsa1Q4LLErnzYBRjUGpqDTT/3IQGWQ/jb9t/CiFd0BR8j6RH6S+MXNdHaIn6vDKdZG/cAMl+C5ZsIzeu6YVVvX+KtfJXpjtLfFUFV0xWMkfFojaqL1pYdv1cHvyr1CsYRWriIx4qNCD70urJZ99rWBzty4jdETf1dAUOFZjAiXjz9gTPRIj+hcAXhft1rq2VCBPJ35SGKDz7FYxL1BuZ0d3jIz0fR8gSFyzs3LrZaclJcwK+/Kw/RbGOLh+q/U5i/6Ukn+BQtj4iPJNbS1ueGE9HaIqfQOTz6u2AskCD+XwdlnrYxc8OygkzR8gxrfHnjdt1rZy4nEH9PDn+HLf9ySvtCtscT2Iv0PYDCxmdH0fKMhFzEVLvsXFmte+2mj4nkMx3CT2T9BsBliO2ihYtjBfP3udNYN0UrANwmeuOdT0e2+H6GcB/XNF332h3HczyLWFx3OM2ndwtjVU0010DRCgArTnhjBWOVePUDiUWSw62z/k3ISPoDHcRLOdWrQNEKBK0tbzj3bT2HPJiu4S441elBQ8xQtAIReaG1c2MG48Be+p7YYT5qsC1iaChaB2h1B1cw5Uu2fAdwp1UOOuJCa+fKDQKFDTgITqVokZ+IWB1KmP0KIFdocRZ7c8xz5GCZGtcoJmNze0gMUq3ilGNd6+oU20qW6177u/z31qrr4GeBOjrr3bACkIVKk1EITg3qiA8JRWsHqVBRJl3BujnmMWRbkUG34CL5yRRGvJa+J95x1pc9kKnt1hCgaP1CqztYorzZ/mYx7jt/4nk4jYqB3Y7Yvjtgf4XJ8Qvh72ri9ODUk4sanisULaHEtvApXxfjvrdGtwFSR3wwhXGQ/5LaEUCoNzDC5axA5CEkODXHfmf9PYxVWFvBAihaAB7LRj+gmnN0Kh1+vRKg2oArPh8TiUA5fiH9XVf46dcETM7fXV19WE+haAFodQc5qgd6BhEt4PFmniB8OdyqlKos6jnHD4i8hntdqb1oiZW1RPXtRzDR2uKxSJsmlYv0eczx2xJtDfc6srdZa42w9ZcsldZRGUlTmYZeR0kq+wHXvfZk3WtfwjQRXuktaS8fASwbo3meWMPVs6TWoiV17G3z/wr7lahwhXS6YM80Qgxka3kJE+m+sR3vCBcw35UHSRYmgai1aME4d22YxtKcQ7YuGdzfvBpMtAZa99rfJTL8EmYb55pXAP5qjOYT2aYSz9RWtMTKsvUD5fYr0UMcxkGO60ui7hta99pL2Sa/gZ+t8lsA3xqj+Z0cEBBP1Fa0YH9z38ZiZe0iVocPP48NzvxC6177QaLFP8DP3+E9gAX9Xf6opWhJuo7tsXluvxJnZKEXcISO6wnWvfadlFv+DD9b5j9hnPXeAo3rSi1FC/aCc6NVnsYFEhS565RfwWyZtq/QvPa1pZLA1SZMmo5rLgB8aYzmS/q73FG7OK0KSdFP2QBo2palcc1WFA6d0smNta0m4buul2oz1VNgcOp5UEfReoBdUOLnxbiv4uyWw4Dmzj8tQ1lw4o/JYOKnfKUGlYqI1yJQcGrQhqvnRK1EyyIpestqMe43FdbRwf7E2CmAax+VI/bhqTHDliDCBXj/PYM3XD0X6ubTyi2vt3ayinB+w/5M/rcA/pH3BWHdaxfixL7xMN025uny+Ft1CRic6nVbfG7UxtJqdQe2pY2tcwwr5Dm+Cx1WUbLPnS3BcvwcNqDYR5AuQedALSwtEYvcchiNo+yyeY6FwpxWiBP5En5ShB5z/DzM9QsBglM/MjyiGrWwtBR8WbeLcT+zXEMT1VqGfViM+3fH3+aWAGVwVjDO68LTfL+g2IDiEBsATfq4ylELSwt2wYwb6ASSVh3Du6/nOeTG6sBfUnbQHL+d4FSX/q4LmER3UoK6iFbT4tqhbRiCnBZW9ZV0bObWZEe4fCZlb3P8ihA5fpIW1YS74NQoHkopURfRqsr2mNqW3OLaqLYOIlwhrIOPMCdv3nP8pJLENYAW9P1dFK2S1EW0qsY8XdtGvouVZdNVeGIzvwskTchHGZinbMMGliFqWomzvgPgHfS2yYyWL0ldRKuKI3u1GPcLhbltxthYXu+Sa4Sr3XUB4+96COTv2q2cavs3oGiVpBaiJbFOZc36zHZeObW0OX0axprjKNvE0LW7XiNgTSs52WyiurN+g2oP1FpTi5AHAGh1B5cwW61T4qRCBJI+JfrEbPEtLfHTWpjs/PgljL/GZ9PVGwRKk6kYnPrhaa9HcpzaiBbwKFzHortnADLb3D/LtmSAKX+T26whJmQbdyUv17FP1wHjuzo43HB1S7Ccy9SplWgBjxZQJq9dC2AKoNDwY0kg6QOqW1krAJcaVpas5Qq/Vgt9CBmwKoGb17A7oDhGDA1Xc/zbyryFsQbpy6pI7UTLB63uoIBdDtsnW/EUcR4eWMcKxqKc2Mxjg6cSMVMY8Vo6nGMvsoW+BPCdQqUDRUsZi3SdLdblb2QNE5y2DbMWSFskB88mmf0UvsKkBUXrIySnUYvTQ88UltfnCmvIcbrfaCgiFwwpifwGbhtR/AHWcD8LaGkpolDKWcvKKmvp3S/G/eA5cB6Tslcwznqe3CUILS1dbOOWMoU1FBWueS8+sKB4TMp+BeDvUMUHiR0ULSUkkNTGQrDuVm2ZMhTFzbsjXL4arv4TKhmbVIOipYBSkUHb67XGCI4kKHfgr+FqsGRsUh6Klg62HWxCW1lR4qmm1ZbdGu6Z47mIBRQtS8TKsj2RyhWWYjvGUmENTtipaeWjssS2+OAD/V1xQtGyZ4jqke+AKeU8sVlAqzu4gp2V9TXmjtnA45Yxg78a7q8BTNg5Jz4oWhZIeIFt95bcfiVWp5Za5aS9sO61H3ZqWrn2d10AoJM+MihadtiGONwqlHLOcKblbw4hNa2aAD7Drb/rAgmJeh2gaFVEHN/vLYfJ7VdiNYZWOelgSDR9E+5quANsPhEVjIivSKs7mMDOj6TRliyDXWs0lfI3sk2+hon1uoRx6n+HKQNU+LLkZBtXwM0p6hsmPMcBLa0KyImhzY2xgeWJo0Js2AoKVpbUDVvA5Pa9hdlOvZb//gLgQaxS5zyp4a7t72L8ViRQtKphexSu4UeyjQ3LFZp2DHG80OErAN98CRfgzN+1VBqHWELR8o+1H0khNsy6aYdsCf8occmd7/zGHX/XjeVQq1D1uMi/oWhVY2lxrZaVZRMbllvOX2WMC+gkhJdC4rtymJ6F9xWHKdQWRKyhI74ire5gifLbM+tmFQoNM0KVvwGA2WLcDxplXqKG+5aZtAsjkUBLqzpFhWusm7/C3HA2VlZmOT9Q3fLw1ZVnL+Lv6sD0LDzmrJ/BVJwgEUFLqyJi8Uxw+o1obWUolHLWaI3WgUWhw8W4/8Jmfm2kkmmGXz/HGUzziSLEmshh/hN6AamyGPe/S5zUsZZkgN4TOw98ve0YoTpS70Wc9UkH2NYNbg8tkN6Il9hffWADc3LVUQgvaMIuzzGG8jdW8xMC0NKyRsQoa3UH1zDW1HYLOFFuz5VbXq/R0MF2DYXCGkjNoU8rARR8WTGkDFn70wgBuD1Mhabl9bnCGmzH0FgDIdweJoJNom4M5W80/GlXMNUWNMJGSMLQ0koAuUmrJgDnCkuwHUPDn3YFcxCxFP8hqSkUrXQoKlxzo2Bl2SZm38opq80aXuJnTasLAF9a3cGy1R0wUr2GULTSYYhycU5aidm5zRgK1wNGsJ5mAbwC8I9sG0mNoGglgmwROzhduLIIErOt/WlCfuBnhZyukppA0UoI2WZ1cLht/AzAm8W4f2czl0L5G+tCh7KODIe3p6zhXjN4epgY2yh82RZd4md60ATAg61Y7WBrZVmX4CmxPeUWsUYwuJT8C9luPaC6aEoAnY0AAAHySURBVFmX4JF15DheGfXx7bH3biQ6cHtIniOHZZFBJSurzPayaTMfSQeKFvkFhcTs1WLc16iaUHZ7yk45NYGiRZ7StLw+t12ACOep20LA1CpjlHxNoGgRTawbZgh5yfezHlaNoGiRX7DMEcyUltEs8d5bJaEkiUDRIs8xrXKNVv0wKWHzAcfzLa1L7pD0oGiR58g9XbOXxbh/J12DbvBv8ZoB+EDBqieM0yLP0uoOCpx+ivh1Me47r7zQ6g4ubZOvSfpQtMhepO39sS7SN4txP/ewHEIAULTIEaSZRYZfKy1sYLoQ5YxCJ76haBFCkoKOeEJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUlC0CCFJQdEihCQFRYsQkhQULUJIUvwfy8nJ7J8FxOEAAAAASUVORK5CYII=" />
                                </defs>
                            </svg>
                        </div>


                        <h4>オーナー<br>コネクト</h4>
                        <p>マンション所有者向けの資産ウォッチツール。</p>
                        <button>マンション所有者</button>
                    </div>
                </div>
            </div>

            <!-- Point 3: Quick Setup -->
            <div class="capability-point point-setup">
                <div class="capability-image-left">
                    <img src="./images/section9-2.png" alt="Quick Setup">
                </div>
                <div class="capability-content-right">
                    <div class="point-badge">
                        <!-- SVG for Point 03 - insert the one from spec -->
                        <svg width="110" height="55" viewBox="0 0 110 55" fill="none" xmlns="http://www.w3.org/2000/svg"
                            xmlns:xlink="http://www.w3.org/1999/xlink">
                            <rect width="110" height="55" fill="url(#pattern0_30_1669)" />
                            <defs>
                                <pattern id="pattern0_30_1669" patternContentUnits="objectBoundingBox" width="1"
                                    height="1">
                                    <use xlink:href="#image0_30_1669"
                                        transform="matrix(0.00666667 0 0 0.0133333 -0.00333333 0)" />
                                </pattern>
                                <image id="image0_30_1669" width="151" height="75" preserveAspectRatio="none"
                                    xlink:href="data:image/png;base64,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" />
                            </defs>
                        </svg>


                    </div>
                    <h3 class="capability-heading">
                        <span class="text-blue">3分</span><span class="text-dark">で完成、<br></span><span
                            class="text-blue">即日利用開始</span>
                    </h3>
                    <p class="capability-description">
                        必要な情報を入力するだけで、プロフェッショナルなAI名刺が完成。リアルタイムでプレビューを確認しながら、いつでも編集可能です。
                    </p>

                    <ul class="setup-steps">
                        <li>
                            <!-- Number 1 SVG -->
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M13.233 5.43324V17.7969H10.619V7.91442H10.5466L7.71527 9.68928V7.37109L10.776 5.43324H13.233ZM11.6151 23.2301C10.3313 23.2301 9.10778 23.0349 7.94467 22.6445C6.78558 22.2541 5.72107 21.7028 4.75113 20.9904C3.78522 20.2821 2.94609 19.4429 2.23373 18.473C1.5254 17.5071 0.976037 16.4446 0.585649 15.2855C0.195261 14.1224 6.65896e-05 12.8989 6.65896e-05 11.6151C6.65896e-05 10.3312 0.195261 9.10973 0.585649 7.95064C0.976037 6.78752 1.5254 5.72301 2.23373 4.7571C2.94609 3.78717 3.78522 2.94803 4.75113 2.2397C5.72107 1.52734 6.78558 0.97597 7.94467 0.585582C9.10778 0.195194 10.3313 0 11.6151 0C12.899 0 14.1205 0.195194 15.2795 0.585582C16.4427 0.97597 17.5072 1.52734 18.4731 2.2397C19.443 2.94803 20.2821 3.78717 20.9905 4.7571C21.7028 5.72301 22.2542 6.78752 22.6446 7.95064C23.035 9.10973 23.2302 10.3312 23.2302 11.6151C23.2302 12.8989 23.035 14.1224 22.6446 15.2855C22.2542 16.4446 21.7028 17.5071 20.9905 18.473C20.2821 19.4429 19.443 20.2821 18.4731 20.9904C17.5072 21.7028 16.4427 22.2541 15.2795 22.6445C14.1205 23.0349 12.899 23.2301 11.6151 23.2301ZM11.6151 21.5096C12.7098 21.5096 13.7502 21.3426 14.7362 21.0085C15.7263 20.6785 16.6338 20.2096 17.4589 19.6019C18.2839 18.9982 18.9983 18.2839 19.602 17.4588C20.2097 16.6338 20.6786 15.7262 21.0086 14.7362C21.3426 13.7461 21.5097 12.7057 21.5097 11.6151C21.5097 10.5204 21.3426 9.48 21.0086 8.49396C20.6786 7.50391 20.2097 6.59635 19.602 5.77131C18.9983 4.94626 18.2839 4.23189 17.4589 3.6282C16.6338 3.02048 15.7263 2.55161 14.7362 2.22159C13.7502 1.88755 12.7098 1.72053 11.6151 1.72053C10.5204 1.72053 9.47805 1.88755 8.48799 2.22159C7.49794 2.55161 6.59038 3.02048 5.76534 3.6282C4.94431 4.23189 4.22994 4.94626 3.62223 5.77131C3.01853 6.59635 2.54966 7.50391 2.21562 8.49396C1.8856 9.48 1.72059 10.5204 1.72059 11.6151C1.72059 12.7098 1.8856 13.7521 2.21562 14.7422C2.54966 15.7282 3.01853 16.6338 3.62223 17.4588C4.22994 18.2839 4.94431 18.9982 5.76534 19.6019C6.59038 20.2096 7.49794 20.6785 8.48799 21.0085C9.47805 21.3426 10.5204 21.5096 11.6151 21.5096Z"
                                    fill="#0066CC" />
                            </svg>
                            <span>アカウント登録(SMS認証)</span>
                        </li>
                        <li>
                            <!-- Number 2 SVG -->
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M7.28061 17.7969V15.9134L11.6815 11.8384C12.0558 11.4762 12.3697 11.1502 12.6233 10.8604C12.8809 10.5707 13.0761 10.2869 13.2089 10.0092C13.3417 9.72751 13.4081 9.42365 13.4081 9.09766C13.4081 8.73544 13.3256 8.42353 13.1606 8.16193C12.9956 7.89631 12.7702 7.69306 12.4844 7.5522C12.1987 7.40732 11.8747 7.33487 11.5125 7.33487C11.1342 7.33487 10.8042 7.41134 10.5224 7.56428C10.2407 7.71721 10.0234 7.93655 9.87045 8.2223C9.71751 8.50805 9.64105 8.84813 9.64105 9.24254H7.15987C7.15987 8.43359 7.34299 7.7313 7.70923 7.13565C8.07547 6.54001 8.58861 6.07919 9.24865 5.7532C9.90868 5.4272 10.6693 5.2642 11.5306 5.2642C12.416 5.2642 13.1867 5.42116 13.8428 5.73508C14.5028 6.04498 15.0159 6.47562 15.3822 7.02699C15.7484 7.57836 15.9315 8.21023 15.9315 8.92258C15.9315 9.38944 15.839 9.85026 15.6538 10.305C15.4727 10.7598 15.1487 11.2649 14.6819 11.8203C14.215 12.3717 13.557 13.0337 12.7078 13.8065L10.9028 15.5753V15.6598H16.0945V17.7969H7.28061ZM11.6151 23.2301C10.3313 23.2301 9.10778 23.0349 7.94467 22.6445C6.78558 22.2541 5.72107 21.7028 4.75113 20.9904C3.78522 20.2821 2.94609 19.4429 2.23373 18.473C1.5254 17.5071 0.976037 16.4446 0.585649 15.2855C0.195261 14.1224 6.65896e-05 12.8989 6.65896e-05 11.6151C6.65896e-05 10.3312 0.195261 9.10973 0.585649 7.95064C0.976037 6.78752 1.5254 5.72301 2.23373 4.7571C2.94609 3.78717 3.78522 2.94803 4.75113 2.2397C5.72107 1.52734 6.78558 0.97597 7.94467 0.585582C9.10778 0.195194 10.3313 0 11.6151 0C12.899 0 14.1205 0.195194 15.2795 0.585582C16.4427 0.97597 17.5072 1.52734 18.4731 2.2397C19.443 2.94803 20.2821 3.78717 20.9905 4.7571C21.7028 5.72301 22.2542 6.78752 22.6446 7.95064C23.035 9.10973 23.2302 10.3312 23.2302 11.6151C23.2302 12.8989 23.035 14.1224 22.6446 15.2855C22.2542 16.4446 21.7028 17.5071 20.9905 18.473C20.2821 19.4429 19.443 20.2821 18.4731 20.9904C17.5072 21.7028 16.4427 22.2541 15.2795 22.6445C14.1205 23.0349 12.899 23.2301 11.6151 23.2301ZM11.6151 21.5096C12.7098 21.5096 13.7502 21.3426 14.7362 21.0085C15.7263 20.6785 16.6338 20.2096 17.4589 19.6019C18.2839 18.9982 18.9983 18.2839 19.602 17.4588C20.2097 16.6338 20.6786 15.7262 21.0086 14.7362C21.3426 13.7461 21.5097 12.7057 21.5097 11.6151C21.5097 10.5204 21.3426 9.48 21.0086 8.49396C20.6786 7.50391 20.2097 6.59635 19.602 5.77131C18.9983 4.94626 18.2839 4.23189 17.4589 3.6282C16.6338 3.02048 15.7263 2.55161 14.7362 2.22159C13.7502 1.88755 12.7098 1.72053 11.6151 1.72053C10.5204 1.72053 9.47805 1.88755 8.48799 2.22159C7.49794 2.55161 6.59038 3.02048 5.76534 3.6282C4.94431 4.23189 4.22994 4.94626 3.62223 5.77131C3.01853 6.59635 2.54966 7.50391 2.21562 8.49396C1.8856 9.48 1.72059 10.5204 1.72059 11.6151C1.72059 12.7098 1.8856 13.7521 2.21562 14.7422C2.54966 15.7282 3.01853 16.6338 3.62223 17.4588C4.22994 18.2839 4.94431 18.9982 5.76534 19.6019C6.59038 20.2096 7.49794 20.6785 8.48799 21.0085C9.47805 21.3426 10.5204 21.5096 11.6151 21.5096Z"
                                    fill="#0066CC" />
                            </svg>
                            <span>会社・個人情報を入力</span>
                        </li>
                        <li>
                            <!-- Number 3 SVG -->
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M11.5849 17.9659C10.6834 17.9659 9.88051 17.811 9.1762 17.5011C8.47592 17.1871 7.92253 16.7565 7.51605 16.2092C7.11358 15.6578 6.90632 15.0219 6.89424 14.3015H9.52635C9.54244 14.6033 9.64105 14.869 9.82216 15.0984C10.0073 15.3237 10.2528 15.4988 10.5587 15.6236C10.8645 15.7483 11.2086 15.8107 11.591 15.8107C11.9894 15.8107 12.3416 15.7403 12.6474 15.5994C12.9533 15.4586 13.1928 15.2634 13.3658 15.0138C13.5389 14.7643 13.6254 14.4766 13.6254 14.1506C13.6254 13.8205 13.5329 13.5288 13.3477 13.2752C13.1666 13.0176 12.905 12.8164 12.5629 12.6715C12.2249 12.5266 11.8224 12.4542 11.3555 12.4542H10.2025V10.5344H11.3555C11.7499 10.5344 12.0981 10.466 12.3999 10.3292C12.7058 10.1924 12.9432 10.0032 13.1123 9.76172C13.2813 9.51622 13.3658 9.23047 13.3658 8.90447C13.3658 8.59458 13.2914 8.32292 13.1425 8.08949C12.9976 7.85204 12.7923 7.6669 12.5267 7.53409C12.2651 7.40128 11.9592 7.33487 11.6091 7.33487C11.2549 7.33487 10.9309 7.39927 10.6371 7.52805C10.3433 7.65282 10.1079 7.83191 9.93082 8.06534C9.75374 8.29877 9.65916 8.57244 9.64708 8.88636H7.14176C7.15383 8.17401 7.35707 7.54616 7.75149 7.00284C8.1459 6.45952 8.67715 6.03492 9.34524 5.72905C10.0173 5.41915 10.776 5.2642 11.6212 5.2642C12.4744 5.2642 13.2209 5.41915 13.8609 5.72905C14.5008 6.03894 14.9978 6.4575 15.352 6.98473C15.7102 7.50793 15.8873 8.09553 15.8832 8.74751C15.8873 9.43975 15.6719 10.0173 15.2373 10.4801C14.8066 10.9429 14.2452 11.2367 13.553 11.3615V11.4581C14.4625 11.5748 15.1548 11.8907 15.6297 12.4059C16.1086 12.917 16.3461 13.5569 16.342 14.3256C16.3461 15.0299 16.1428 15.6558 15.7323 16.2031C15.3258 16.7505 14.7644 17.1811 14.048 17.495C13.3316 17.8089 12.5106 17.9659 11.5849 17.9659ZM11.6151 23.2301C10.3313 23.2301 9.10778 23.0349 7.94467 22.6445C6.78558 22.2541 5.72107 21.7028 4.75113 20.9904C3.78522 20.2821 2.94609 19.4429 2.23373 18.473C1.5254 17.5071 0.976037 16.4446 0.585649 15.2855C0.195261 14.1224 6.65896e-05 12.8989 6.65896e-05 11.6151C6.65896e-05 10.3312 0.195261 9.10973 0.585649 7.95064C0.976037 6.78752 1.5254 5.72301 2.23373 4.7571C2.94609 3.78717 3.78522 2.94803 4.75113 2.2397C5.72107 1.52734 6.78558 0.97597 7.94467 0.585582C9.10778 0.195194 10.3313 0 11.6151 0C12.899 0 14.1205 0.195194 15.2795 0.585582C16.4427 0.97597 17.5072 1.52734 18.4731 2.2397C19.443 2.94803 20.2821 3.78717 20.9905 4.7571C21.7028 5.72301 22.2542 6.78752 22.6446 7.95064C23.035 9.10973 23.2302 10.3312 23.2302 11.6151C23.2302 12.8989 23.035 14.1224 22.6446 15.2855C22.2542 16.4446 21.7028 17.5071 20.9905 18.473C20.2821 19.4429 19.443 20.2821 18.4731 20.9904C17.5072 21.7028 16.4427 22.2541 15.2795 22.6445C14.1205 23.0349 12.899 23.2301 11.6151 23.2301ZM11.6151 21.5096C12.7098 21.5096 13.7502 21.3426 14.7362 21.0085C15.7263 20.6785 16.6338 20.2096 17.4589 19.6019C18.2839 18.9982 18.9983 18.2839 19.602 17.4588C20.2097 16.6338 20.6786 15.7262 21.0086 14.7362C21.3426 13.7461 21.5097 12.7057 21.5097 11.6151C21.5097 10.5204 21.3426 9.48 21.0086 8.49396C20.6786 7.50391 20.2097 6.59635 19.602 5.77131C18.9983 4.94626 18.2839 4.23189 17.4589 3.6282C16.6338 3.02048 15.7263 2.55161 14.7362 2.22159C13.7502 1.88755 12.7098 1.72053 11.6151 1.72053C10.5204 1.72053 9.47805 1.88755 8.48799 2.22159C7.49794 2.55161 6.59038 3.02048 5.76534 3.6282C4.94431 4.23189 4.22994 4.94626 3.62223 5.77131C3.01853 6.59635 2.54966 7.50391 2.21562 8.49396C1.8856 9.48 1.72059 10.5204 1.72059 11.6151C1.72059 12.7098 1.8856 13.7521 2.21562 14.7422C2.54966 15.7282 3.01853 16.6338 3.62223 17.4588C4.22994 18.2839 4.94431 18.9982 5.76534 19.6019C6.59038 20.2096 7.49794 20.6785 8.48799 21.0085C9.47805 21.3426 10.5204 21.5096 11.6151 21.5096Z"
                                    fill="#0066CC" />
                            </svg>
                            <span>即日利用開始</span>
                        </li>
                    </ul>

                    <p class="setup-disclaimer">
                        ※6つの不動産テックツールとの連携は、お申し込みから1週間以内に接続されます。接続先は、あなた独自のページとなります。
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Section 10 - Profile Page -->
    <section class="section-profile">
        <div class="profile-container">
            <!-- Main Content Box -->
            <div class="profile-main-box">
                <div class="profile-main-content">
                    <h2 class="profile-main-heading">
                        <span class="profile-heading-text">あなただけの</span><br>
                        <span class="profile-heading-blue">プロフィールページ</span><span class="profile-heading-dark">を作成</span>
                    </h2>
                    <p class="profile-main-description">
                        基本情報・SNSアイコン・自由リンクと大きく3つのパートに分かれています。<br>
                        シンプルで使いやすいデザインです。最新のお知らせや肩書きが変わっても、その場で更新できます。
                    </p>
                </div>

                <!-- Right Phone Image -->
                <div class="profile-main-image">
                    <img src="./images/section10-1.png" alt="Profile Page">
                </div>

                <!-- Left Bottom Illustration -->
                <div class="profile-main-illustration">
                    <img src="./images/section10-2.png" alt="Person">
                </div>
            </div>

            <!-- Three Feature Cards -->
            <div class="profile-cards-container">
                <!-- Card 1 -->
                <div class="profile-card">
                    <div class="profile-card-image">
                        <img src="./images/section10-left.png" alt="LINE Integration">
                    </div>
                    <div class="profile-card-content">
                        <h3 class="profile-card-heading">
                            ワンタップで、LINEと<br>不動産チェックツールへ<br>つながる
                        </h3>
                        <p class="profile-card-text" id="pricing">
                            名刺を読み取ったその場で、公式LINE・Messengerなどにすぐ接続。<br><br>
                            さらに、お客様が必要な自動物件提案ツールやAI査定ツール、全国のマンションデータベースなどの不動産チェックツールへもワンタップで案内できます。
                        </p>
                    </div>
                </div>

                <!-- Card 2 -->
                <div class="profile-card">
                    <div class="profile-card-image">
                        <img src="./images/section10-middle.png" alt="Documents">
                    </div>
                    <div class="profile-card-content">
                        <h3 class="profile-card-heading">
                            物件提案・査定に役立<br>つリンクを自由に掲載
                        </h3>
                        <p class="profile-card-text">
                            物件紹介ページ、会社HP、売却査定フォーム、説明資料（PDF）、YouTube動画、来店予約など、営業に必要なURLをまとめて設置可能です。
                        </p>
                    </div>
                </div>

                <!-- Card 3 -->
                <div class="profile-card">
                    <div class="profile-card-image">
                        <img src="./images/section10-right.png" alt="Communication">
                    </div>
                    <div class="profile-card-content">
                        <h3 class="profile-card-heading">
                            つながり方が増えるか<br>ら、失注が減る
                        </h3>
                        <p class="profile-card-text">
                            LINEで即連絡が交換、名刺情報の保存、アクセス履歴の把握など「連絡が途切れる」を防止。<br><br>
                            コミュニケーションの起点を増やし、商談化までスムーズに繋ぎます。
                        </p>
                    </div>
                </div>
            </div>

            <!-- Bottom Right Illustration -->
            <div class="profile-bottom-illustration">
                <img src="./images/section10-bottom.png" alt="Business People">
            </div>
        </div>
    </section>

    <!-- Section 11 - Pricing -->
    <section class="section-pricing">
        <div class="pricing-container">
            <!-- Header -->
            <div class="pricing-header">
                <p class="pricing-label">Pricing</p>
                <h2 class="pricing-title">費用・決済方法について</h2>
            </div>

            <!-- Blue Pricing Box -->
            <div class="pricing-box-blue">
                <div class="popular-badge">人気</div>
                <h3 class="pricing-plan-name">不動産AI名刺プラン</h3>
                <div class="pricing-divider"></div>
                <div class="pricing-amount">
                    <span class="price-yen">¥</span>
                    <span class="price-number">500</span>
                    <span class="price-period">/月</span>
                </div>
                <div class="pricing-plus">+</div>
                <?php if ($userType === 'existing'): ?>
                <p class="pricing-initial">初期費用 <?php echo number_format(PRICING_EXISTING_USER_INITIAL); ?>円（税別）</p>
                <?php endif; ?>
                <?php if ($userType === 'new'): ?>
                <p class="pricing-initial">初期費用 <?php echo number_format(PRICING_NEW_USER_INITIAL); ?>円（税別）</p>
                <?php endif; ?>
            </div>

            <!-- Light Blue Features Box -->
            <div class="features-box">
                <div class="features-content">
                    <!-- Left Side - QR Code Image -->
                    <div class="features-image">
                        <img src="./images/section11-1.png" alt="QR Code">
                    </div>

                    <!-- Right Side - Features List -->
                    <div class="features-list-container">
                        <h4 class="features-heading">ご利用いただけるサービス</h4>
                        <ul class="features-list">
                            <li class="feature-item">
                                <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g clip-path="url(#clip0_239_261)">
                                        <path
                                            d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                            fill="#0066CC" />
                                        <path
                                            d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                            fill="#0066CC" />
                                    </g>
                                    <defs>
                                        <clipPath id="clip0_239_261">
                                            <rect width="24" height="21" fill="white" />
                                        </clipPath>
                                    </defs>
                                </svg>
                                <span>デジタル名刺の作成・管理</span>
                            </li>
                            <li class="feature-item">
                                <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g clip-path="url(#clip0_239_261)">
                                        <path
                                            d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                            fill="#0066CC" />
                                        <path
                                            d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                            fill="#0066CC" />
                                    </g>
                                </svg>
                                <span>QRコード自動生成機能</span>
                            </li>
                            <li class="feature-item">
                                <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g clip-path="url(#clip0_239_261)">
                                        <path
                                            d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                            fill="#0066CC" />
                                        <path
                                            d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                            fill="#0066CC" />
                                    </g>
                                </svg>
                                <span>LINE等、ワンタップでSNS連携</span>
                            </li>
                            <li class="feature-item">
                                <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g clip-path="url(#clip0_239_261)">
                                        <path
                                            d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                            fill="#0066CC" />
                                        <path
                                            d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                            fill="#0066CC" />
                                    </g>
                                </svg>
                                <div class="feature-sub-list">
                                    <div>6つの不動産チェックツール連携</div>
                                    <ul class="sub-features">
                                        <li>· 全国マンションデータベース</li>
                                        <li>· 物件提案ロボ</li>
                                        <li>· 土地情報ロボ</li>
                                        <li>· セルフィン</li>
                                        <li>· AIマンション査定</li>
                                        <li>· オーナーコネクト</li>
                                    </ul>
                                </div>
                            </li>
                            <li class="feature-item">
                                <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g clip-path="url(#clip0_239_261)">
                                        <path
                                            d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                            fill="#0066CC" />
                                        <path
                                            d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                            fill="#0066CC" />
                                    </g>
                                </svg>
                                <span>自分の名前で営業に情報が届く</span>
                            </li>
                            <li class="feature-item">
                                <svg width="24" height="21" viewBox="0 0 24 21" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g clip-path="url(#clip0_239_261)">
                                        <path
                                            d="M10.3678 10.2595L7.19763 7.27428L4.30919 10.0285L4.297 10.0405L4.29419 10.0428L10.3692 15.7969L24 2.76847L21.0993 0.00268176V0.0022348L21.0965 0L19.087 1.92327L16.3195 4.56704L10.3678 10.2595Z"
                                            fill="#0066CC" />
                                        <path
                                            d="M20.5959 8.53058V8.52566L18.2362 10.7819V17.2713C18.2362 18.0879 17.5387 18.7499 16.6856 18.7499H3.91031C3.05391 18.7499 2.35969 18.0879 2.35969 17.2713V5.08987C2.35969 4.27685 3.05391 3.61133 3.91031 3.61133H14.7005L15.007 3.31902L16.2047 2.17524L17.0395 1.38099C17.0395 1.38099 17.0372 1.38099 17.0362 1.38099L17.0395 1.37742C16.9247 1.36803 16.807 1.36133 16.6856 1.36133H3.91031C1.75312 1.36133 0 3.03609 0 5.08987V17.2713C0 19.3287 1.75266 20.9999 3.91031 20.9999H16.6856C18.8395 20.9999 20.5959 19.3287 20.5959 17.2713V12.1192L20.5987 8.52834L20.5959 8.53058Z"
                                            fill="#0066CC" />
                                    </g>
                                </svg>
                                <span>顧客からの問い合わせが直接届く</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Illustration -->
                    <div class="features-illustration">
                        <img src="./images/section11-2.png" alt="Business Illustration">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Section 3 - CTA -->
    <section class="section-cta">
        <div class="cta-container">
            <!-- Top Heading -->
            <h2 class="cta-main-heading">あなたの1枚は、何を語りますか？</h2>

            <!-- Content Box -->
            <div class="cta-content-box">
                <!-- Heading -->
                <div class="cta-heading-group">
                    <h3 class="cta-heading-line1">今すぐ始めて、</h3>
                    <h3 class="cta-heading-line2">営業力を次のレベルへ</h3>
                </div>

                <!-- Description -->
                <p class="cta-description">
                    3分で完成。即日利用開始。<br>
                    あなたの営業活動を劇的に変える、不動産AI名刺を今すぐ作成しましょう。
                </p>

                <!-- Button -->
                <button class="cta-button">不動産AI名刺をつくる</button>
            </div>

            <!-- Illustration -->
            <div class="cta-illustration">
                <img src="./images/section12-1.png" alt="AI Business Card">
            </div>
        </div>
    </section>


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
                        <!-- <li>
                            <a href="#" class="social-link">
                                <span>ヘルプセンター</span>
                            </a>
                        </li> -->
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

    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/js/mobile-menu.js"></script>
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/lp.js"></script>
    <script src="assets/js/new_lp.js"></script>

    <script src="new_lp.js"></script>

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

