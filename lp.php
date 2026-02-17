<?php
/**
 * Landing Page - New Design
 */
require_once __DIR__ . '/backend/config/config.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>不動産AI名刺 - 簡単につながり、顧客に選ばれる</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/lp.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <a href="index.php">
                        <img src="assets/images/logo.png" alt="不動産AI名刺">
                    </a>
                </div>
                <div class="fixed-cta-button">
                    <a href="new_register.php" class="btn-primary">不動産AI名刺を作る</a>
                </div>
            </div>
        </div>
    </header>
    <!-- Fixed CTA Button -->
    

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
            <div class="hero-cta">
                <a href="new_register.php" class="btn-primary btn-large">もう少し詳しく知る</a>
                <span class="hero-cta-text">※3分で完成</span>
            </div>
        </div>
        
        <div class="hero-slide-right">
            <img src="assets/images/first.jpg" alt="効率化" class="hero-slide-image">
        </div>
        
    </section>

    <!-- Problems Section -->
    <section class="problems-section">
        <div class="container">
            <h2 class="problems-title">こんなお悩みありませんか?</h2>
            <div class="problems-grid">
                <div class="problem-card">
                    <div class="problem-icon">
                        <span>!</span>
                    </div>
                    <h3 class="problem-title">名刺交換後、連絡が途絶える</h3>
                    <p class="problem-description">
                        せっかく出会った見込み客と、その後のコミュニケーションが取れない
                    </p>
                </div>
                
                <div class="problem-card">
                    <div class="problem-icon">
                        <span>!</span>
                    </div>
                    <h3 class="problem-title">他社との差別化ができない</h3>
                    <p class="problem-description">
                        競合エージェントとの違いを明確に示せず、選ばれる理由が弱い
                    </p>
                </div>
                
                <div class="problem-card">
                    <div class="problem-icon">
                        <span>!</span>
                    </div>
                    <h3 class="problem-title">顧客への情報提供が不十分</h3>
                    <p class="problem-description">
                        物件情報や市場データを効果的に共有できず、信頼構築に時間がかかる
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Feature 01: LINE Integration -->
    <section class="feature-section feature-01">
        <div class="container">
            <div class="feature-content">
                <div class="feature-visual">
                    <div class="feature-image-wrapper line-feature">
                        <div class="line-placeholder">
                            <div class="line-logo">
                                <img src="assets/images/icons/line.png" alt="LINE">
                            </div>
                            <div class="qr-placeholder">
                                <img src="assets/images/qr.png" alt="QR">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="feature-text">
                    <div class="feature-badge">機能01</div>
                    <h2 class="feature-title">ワンタップで<br>LINE連携完了</h2>
                    <p class="feature-description">
                        QRコードを読み込むだけで、その場で顧客とLINEでつながれます。名刺交換後の失注を防ぎ、確実なコミュニケーション手段を確立します。
                    </p>
                    <ul class="feature-list">
                        <li><span class="check-icon">✓</span> LINE、Messenger、Chatworkに対応</li>
                        <li><span class="check-icon">✓</span> 顧客の連絡先を自動保存可能</li>
                        <li><span class="check-icon">✓</span> SNSアカウントも同時に共有できる</li>
                    </ul>
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
                    <img src="assets/images/dashboard.jpg" alt="Dashboard" class="feature-image">
                    <!-- <div class="feature-image-wrapper dashboard">
                        <div class="dashboard-placeholder">
                            <div class="dashboard-header">Dashboard</div>
                            <div class="dashboard-content">
                                <div class="dashboard-card"></div>
                                <div class="dashboard-card"></div>
                                <div class="dashboard-chart"></div>
                            </div>
                        </div>
                    </div> -->
                </div>
            </div>
        </div>
    </section>

    <!-- Feature 03: 3 Minutes to Complete -->
    <section class="feature-section feature-03">
        <div class="container">
            <div class="feature-content">
                <div class="feature-visual">
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
    <section class="tools-detail-section">
        <div class="container">
            <h2 class="section-title">搭載されている不動産テックツール</h2>
            <p class="section-subtitle">
                顧客に無料で提供できる、業界最先端のテックツール。これらのツールが、あなたの営業力を飛躍的に向上させます。
            </p>
            <div class="tools-grid">
                <div class="tool-card">
                    <div class="tool-icon">
                        <span class="icon-placeholder">🏢</span>
                    </div>
                    <h3 class="tool-title">全国マンションデータベース</h3>
                    <span class="tool-tag tag-purple">売り・買い</span>
                    <p class="tool-description">全国の分譲マンションの95%以上を網羅。販売履歴の閲覧が最も多い人気ツール。</p>
                </div>
                
                <div class="tool-card">
                    <div class="tool-icon">
                        <span class="icon-placeholder">🤖</span>
                    </div>
                    <h3 class="tool-title">物件提案ロボ</h3>
                    <span class="tool-tag tag-blue">買い</span>
                    <p class="tool-description">希望条件に合致した中古マンション・一戸建て情報を、売り出し開始から24時間以内にAI評価付きで自動配信。</p>
                </div>
                
                <div class="tool-card">
                    <div class="tool-icon">
                        <span class="icon-placeholder">📍</span>
                    </div>
                    <h3 class="tool-title">土地情報ロボ</h3>
                    <span class="tool-tag tag-blue">買い</span>
                    <p class="tool-description">希望条件に合致した土地情報を、売り出し開始から24時間以内に自動でお届け。</p>
                </div>
                
                <div class="tool-card">
                    <div class="tool-icon">
                        <span class="icon-placeholder">📊</span>
                    </div>
                    <h3 class="tool-title">AIマンション査定</h3>
                    <span class="tool-tag tag-green">売り</span>
                    <p class="tool-description">個人情報不要でマンションの査定を実施。「マンション」「査定」でTOP表示される信頼のコンテンツ。</p>
                </div>
                
                <div class="tool-card">
                    <div class="tool-icon">
                        <span class="icon-placeholder">🔍</span>
                    </div>
                    <h3 class="tool-title">セルフィン</h3>
                    <span class="tool-tag tag-blue">買い</span>
                    <p class="tool-description">中古マンション・一戸建ての物件情報から、物件の良し悪しをAIが自動判定。</p>
                </div>
                
                <div class="tool-card">
                    <div class="tool-icon">
                        <span class="icon-placeholder">🛡️</span>
                    </div>
                    <h3 class="tool-title">オーナーコネクト</h3>
                    <span class="tool-tag tag-green">売り</span>
                    <p class="tool-description">マンション所有者向けの資産ウォッチツール。週1回の査定レポートと売り出し情報を即時配信。</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Simple 3 Steps Section -->
    <section class="steps-section">
        <div class="container">
            <h2 class="section-title">簡単3ステップで始められます</h2>
            <div class="steps-container">
                <div class="step-card">
                    <div class="step-number-bg">01</div>
                    <div class="step-icon-box">
                        <span class="step-icon-emoji">👤</span>
                    </div>
                    <div class="step-label">STEP 01</div>
                    <h3 class="step-title">アカウント登録</h3>
                    <p class="step-description">メールアドレスと携帯電話番号でSMS認証を行います。</p>
                    <div class="step-arrow">→</div> 
                </div>
                <div class="step-card">
                    <div class="step-number-bg">02</div>
                    <div class="step-icon-box">
                        <span class="step-icon-emoji">✏️</span>
                    </div>
                    <div class="step-label">STEP 02</div>
                    <h3 class="step-title">情報入力</h3>
                    <p class="step-description">会社情報、個人情報、使用するテックツールを選択します。</p>
                    <div class="step-arrow">→</div>
                </div>
                <div class="step-card">
                    <div class="step-number-bg">03</div>
                    <div class="step-icon-box">
                        <span class="step-icon-emoji">🔔</span>
                    </div>
                    <div class="step-label">STEP 03</div>
                    <h3 class="step-title">即日利用開始</h3>
                    <p class="step-description">プレビューで確認後、すぐにAI名刺の利用を開始できます。</p>
                </div>
            </div>
            <p class="steps-note">
                ※6つの不動産テックツールとの連携は、お申し込みから1週間以内に接続されます。<br>
                接続先は、あなた独自のページとなります。
            </p>
        </div>
    </section>

    <!-- Customer Testimonials Section -->
    <section class="testimonials-section">
        <div class="container">
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <span class="testimonial-bullet">•</span>
                        <span class="testimonial-label">お客様の声</span>
                    </div>
                    <div class="testimonial-quote">!!</div>
                    <p class="testimonial-text">
                        名刺交換後の失注が激減。顧客との信頼関係構築が圧倒的に早くなりました。
                    </p>
                    <div class="testimonial-author">
                        <div class="author-info">
                            <span class="author-name">山田 太郎様</span>
                            <span class="author-title">不動産エージェント・東京都</span>
                        </div>
                        <span class="verify-icon">✓</span>
                    </div>
                </div>
                <div class="testimonial-photo">
                    <div class="photo-placeholder male-30s">
                        <div class="placeholder-icon">👤</div>
                    </div>
                </div>
            </div>
            
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <span class="testimonial-bullet">•</span>
                        <span class="testimonial-label">お客様の声</span>
                    </div>
                    <div class="testimonial-quote">!!</div>
                    <p class="testimonial-text">
                        物件提案の効率が大幅に向上し、成約率が2倍になりました。顧客からの問い合わせも増えています。
                    </p>
                    <div class="testimonial-author">
                        <div class="author-info">
                            <span class="author-name">佐藤 花子様</span>
                            <span class="author-title">不動産エージェント・大阪府</span>
                        </div>
                        <span class="verify-icon">✓</span>
                    </div>
                </div>
                <div class="testimonial-photo">
                    <div class="photo-placeholder female-40s">
                        <div class="placeholder-icon">👤</div>
                    </div>
                </div>
            </div>
            
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <span class="testimonial-bullet">•</span>
                        <span class="testimonial-label">お客様の声</span>
                    </div>
                    <div class="testimonial-quote">!!</div>
                    <p class="testimonial-text">
                        AI査定ツールのおかげで、顧客との会話がスムーズになり、信頼を得やすくなりました。
                    </p>
                    <div class="testimonial-author">
                        <div class="author-info">
                            <span class="author-name">鈴木 一郎様</span>
                            <span class="author-title">不動産エージェント・愛知県</span>
                        </div>
                        <span class="verify-icon">✓</span>
                    </div>
                </div>
                <div class="testimonial-photo">
                    <div class="photo-placeholder male-50s">
                        <div class="placeholder-icon">👤</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA Section -->
    <section class="final-cta-section">
        <div class="container">
            <div class="cta-content">
                <div class="cta-image">
                    <div class="cta-image-placeholder">
                        <div class="placeholder-icon large">🤝</div>
                    </div>
                </div>
                <div class="cta-text">
                    <h2 class="cta-title">
                        <span class="text-blue">今すぐ始めて、</span><br>
                        営業力を次のレベルへ
                    </h2>
                    <p class="cta-description">
                        3分で完成。即日利用開始。<br>あなたの営業活動を劇的に変える、不動産AI名刺を今すぐ作成しましょう。
                    </p>
                    <a href="new_register.php" class="btn-primary btn-large">もう少し詳しく知る</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
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
                        <li><a href="#features">特徴</a></li>
                        <li><a href="#pricing">料金</a></li>
                        <li><a href="#tools">テックツール</a></li>
                        <li><a href="help.php">よくある質問</a></li>
                        <li><a href="contact.php">お問い合わせ</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4>フォローする</h4>
                    <ul class="social-links">
                        <li>
                            <a href="#" class="social-link">
                                <img src="assets/images/icons/instagram.png" alt="Instagram" class="social-icon">
                                <span>Instagram</span>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="social-link">
                                <img src="assets/images/icons/youtube.png" alt="YouTube" class="social-icon">
                                <span>YouTube</span>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="social-link">
                                <img src="assets/images/icons/facebook.png" alt="Facebook" class="social-icon">
                                <span>Facebook</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4>最新情報をお届けします</h4>
                    <form class="newsletter-form" id="newsletter-form">
                        <div class="newsletter-input-wrapper">
                            <input type="email" id="newsletter-email" placeholder="メールアドレス" class="newsletter-input" required>
                            <button type="submit" class="newsletter-submit">
                                <span>→</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="footer-bottom-content">
                    <p>&copy; 2025 リニュアル仲介株式会社. All rights reserved.</p>
                    <p><a href="terms.php">特定商取引法に基づく表記</a></p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/lp.js"></script>
    <script src="assets/js/mobile-menu.js"></script>
</body>
</html>

