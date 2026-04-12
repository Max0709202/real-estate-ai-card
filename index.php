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
    // Validate token via DB（cURL→BASE_URL は同一サーバ到達失敗で常に無効になることがある）
    try {
        require_once __DIR__ . '/backend/config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        $invCheck = validateInvitationTokenInDatabase($db, $invitationToken);
        if ($invCheck['ok']) {
            $tokenValid = true;
            $tokenData = $invCheck['data'];
            if (($tokenData['role_type'] ?? null) === 'existing') {
                $userType = $tokenData['role_type'];
            }
        }
    } catch (Exception $e) {
        error_log("Token validation error in index.php: " . $e->getMessage());
    }
}

// Token URLs and 既存/ERA LP (type=existing) must not be indexed (different pricing from new members).
$excludeFromSearch = $isTokenBased || $userType === 'existing';
if ($excludeFromSearch) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon.php?size=16&v=2">
    <?php if (!empty($excludeFromSearch)): ?>
    <!-- No index: invitation token URLs and 既存/ERA LP (pricing differs from new members) -->
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
                        <span class="new-lp-sec-1-title-outline">頼られる</span><br><span class="new-lp-sec-1-title-solid">不動産AI名刺</span>
                    </h1>
                    <p class="new-lp-sec-1-sub">顧客に選ばれる私に</p>
                </div>
                <div class="new-lp-sec-1-right">
                    <div class="new-lp-sec-1-video-wrap">
                        <video
                            id="new-lp-sec-1-video"
                            class="new-lp-sec-1-video"
                            src="assets/video/card.mp4"
                            playsinline
                            muted
                            loop
                            autoplay
                        ></video>
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
            デジタル名刺は必須のツールに。<br>
            米国不動産市場では完全に主流化。
            </h2>

            <!-- Trend Items -->
            <div class="trend-items">
                <!-- Trend 1 -->
                <div class="trend-item">
                    <div class="trend-image">
                        <img src="./images/section4-1.png" alt="非対面・非接触ニーズの高まり">
                    </div>
                    <p class="trend-text">
                        新型コロナウイルス感染症（COVID-19）の流行以降、対面での名刺交換を避け、スマートフォンのタッチやスキャンで安全に情報を共有する形式が定着しました。
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

                    <div class="coming-soon-box" style="display: none;">
                        <p>＜ご利用いただける機能＞</p>
                        <p>1. AIチャットボット機能</p>
                        <p>2. <a href="loan-simulator.php" style="color: #0066cc; font-weight: bold;">住宅ローンシミュレーター</a>（返済額・借入可能額の試算）</p>
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
                            <img src="assets/images/lp_icon/mdb.png" style="width:100%;"/>
                        </div>
                        <!-- <h4>全国マンション<br>データベース</h4> -->
                        <p>国内最大規模のマンションデータベース。マンションの基礎情報・販売履歴をはじめ、口コミなども閲覧できます。</p>
                        <a href="https://self-in.com/demo/mdb" target="_blank" style="display: flex; justify-content: center; align-items: center; text-decoration:none;">
                            <button>売却・購入</button>
                        </a>
                    </div>

                    <!--Card 2 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <img src="assets/images/lp_icon/rlp.png" style="width:100%;"/>
                        </div>
                        <p>希望条件に合う新着の売却情報を売り出しから24時間以内にAI評価付きで毎日配信します。希望物件の見落としが無くなります。</p>
                        <a href="https://self-in.net/rlp/index.php?id=demo" target="_blank" style="display: flex; justify-content: center; align-items: center; text-decoration:none;">
                            <button>購入</button>
                        </a>
                    </div>

                    <!-- Card 3 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <img src="assets/images/lp_icon/llp.png" style="width:100%;"/>
                        </div>
                        <p>建てたい工務店は決まっているのに、土地情報を探しているお客様向け。新着の土地売却情報を売り出しから24時間以内に毎日配信します。</p>
                        <a href="https://self-in.net/llp/index.php?id=demo" target="_blank" style="display: flex; justify-content: center; align-items: center; text-decoration:none;">
                            <button>購入</button>
                        </a>
                    </div>

                    <!-- Card 4 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <img src="assets/images/lp_icon/ai.png" style="width:100%;"/>
                        </div>
                        <p>個人情報不要で、膨大な販売履歴より瞬時にマンションの価格を査定します。いつでも自分のマンションの査定が可能です。</p>
                        <a href="https://self-in.com/demo/ai" target="_blank" style="display: flex; justify-content: center; align-items: center; text-decoration:none;">
                            <button>売却</button>
                        </a>
                    </div>

                    <!-- Card 5 -->
                    <div class="tool-card">
                        <div class="tool-card-icon">
                            <!-- REPLACE THIS SVG WITH YOUR ICON -->
                            <img src="assets/images/lp_icon/slp.png" style="width:100%;"/>
                        </div>
                        <p>物件の良し悪しを自動でしかも一瞬で判定するセルフインスペクションWEBアプリ。ネガティブ情報の発見にご活用ください。</p>
                        <a href="https://self-in.net/slp/index.php?id=demo" target="_blank" style="display: flex; justify-content: center; align-items: center; text-decoration:none;">
                            <button>売却・購入</button>
                        </a>
                    </div>

                    <!-- Card 6 -->
                    <div class="tool-card">
                        <!-- REPLACE THIS SVG WITH YOUR ICON -->
                        <div class="tool-card-icon">
                            <img src="assets/images/lp_icon/olp.png" style="width:100%;"/>
                        </div>
                        <p>今日の自宅の価格、今日の残債、今日売ったらいくら手元に残るかなど、登録すると1週間に1回配信されます。他住戸の売り出し情報が出たら直ちに情報を配信します。</p>
                        <a href="https://self-in.net/olp/index.php?id=demo" target="_blank" style="display: flex; justify-content: center;align-items: center; text-decoration:none;">
                        <button style="font-size:11px;">マンション所有者</button>
                        </a>
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
                    <span class="price-number"><?php echo number_format(pricing_amount_inc_tax_yen(PRICING_NEW_USER_MONTHLY)); ?></span>
                    <span class="price-period">/月（税込）</span>
                    <?php if ($userType === 'existing'): ?>
                    <div class="existing-user-price" style="position: absolute; height: 10px; background-color: red; width: 56%; rotate: 350deg; top: 34%;">
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="pricing-plus">+</div>
                <?php if ($userType === 'existing'): ?>
                <p class="pricing-initial">初期費用 <?php echo number_format(pricing_amount_inc_tax_yen(PRICING_EXISTING_USER_INITIAL)); ?>円（税込）</p>
                <?php endif; ?>
                <?php if ($userType === 'new'): ?>
                <p class="pricing-initial">初期費用 <?php echo number_format(pricing_amount_inc_tax_yen(PRICING_NEW_USER_INITIAL)); ?>円（税込）</p>
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
                                <span>自分の名前で顧客に情報が届く</span>
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
                 <a href="https://ai-fcard.com/register.php">
                    <button class="cta-button">不動産AI名刺をつくる</button>
                </a>
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
                        <!-- <li>
                            <a href="contact.php" class="social-link">
                                <span>お問い合わせ</span>
                            </a>
                        </li> -->
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
            </div>
            
            <div class="footer-bottom">
                <div class="footer-bottom-content">
                    <p>&copy; 2026 リニュアル仲介株式会社. All rights reserved.</p>
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

