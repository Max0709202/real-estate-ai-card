<?php
/**
 * Landing Page - New Users
 * Handles token-based redirects for existing/free users
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

// Handle token-based access for existing/free users
$invitationToken = $_GET['token'] ?? '';
$isTokenBased = !empty($invitationToken);
$tokenValid = false;
$tokenData = null;
// Get userType from URL parameter first, default to 'new'
$userType = $_GET['type'] ?? 'new';

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
                // Use token's role_type if available, otherwise use URL parameter
                if (in_array($tokenData['role_type'], ['existing', 'free'])) {
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
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <div class="hero-content-inner">
                    <div class="hero-content-inner-left">
                        <div class="tag">不動産営業の新しいスタンダード</div>
                        <h1>デジタル名刺で<br>商談機会を逃さない</h1>
                        <p>QRコード付きデジタル名刺で、お客様とのLINE連携を簡単に。<br>
                        全国マンションデータベース、AI査定など、不動産テックツールを統合。</p>
                        <div class="hero-buttons">
                            <?php if ($isTokenBased && $tokenValid && in_array($userType, ['existing', 'free'])): ?>
                                <?php if ($userType === 'existing'): ?>
                                    <a href="new_register.php?type=existing&token=<?php echo urlencode($invitationToken); ?>" class="btn-primary btn-large">既存ユーザー登録 →</a>
                                <?php elseif ($userType === 'free'): ?>
                                    <a href="new_register.php?type=free&token=<?php echo urlencode($invitationToken); ?>" class="btn-primary btn-large">無料ユーザー登録 →</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="register.php?type=new" class="btn-primary btn-large">無料で始める →</a>
                            <?php endif; ?>
                            <a href="#howto" class="btn-outline btn-large">使い方を見る</a>
                        </div>
                    </div>
                    <div class="hero-content-inner-right">
                        <img src="assets/images/card.png" alt="AI名刺サンプル 1">
                    </div>
                </div>
                <div class="hero-preview">
                    <div class="swiper card-swiper">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <img src="assets/images/card1.png" alt="デジタル名刺サンプル 1">
                            </div>
                            <div class="swiper-slide">
                                <img src="assets/images/card2.png" alt="デジタル名刺サンプル 2">
                            </div>
                            <div class="swiper-slide">
                                <img src="assets/images/card3.png" alt="デジタル名刺サンプル 3">
                            </div>
                            <div class="swiper-slide">
                                <img src="assets/images/card4.png" alt="デジタル名刺サンプル 4">
                            </div>
                            <div class="swiper-slide">
                                <img src="assets/images/card5.png" alt="デジタル名刺サンプル 5">
                            </div>
                            <div class="swiper-slide">
                                <img src="assets/images/card6.png" alt="デジタル名刺サンプル 6">
                            </div>
                        </div>
                        <!-- Navigation buttons -->
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                        <!-- Pagination -->
                        <div class="swiper-pagination"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="container">
            <h2>営業活動を加速する機能</h2>
            <p class="section-subtitle">デジタル名刺と不動産テックツールの統合で、商談機会を最大化</p>
            
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">📱</div>
                    <h3>QRコード生成</h3>
                    <p>名刺に印刷できるQRコードを自動生成。お客様のスマホで簡単にアクセス。</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">💬</div>
                    <h3>LINE連携</h3>
                    <p>ワンタップでLINE追加。お客様との継続的なコミュニケーションを実現。</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🏢</div>
                    <h3>全国マンションDB</h3>
                    <p>全国のマンション情報データベースへ直接アクセス。物件情報を即座に提供。</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🤖</div>
                    <h3>物件提案ロボ</h3>
                    <p>AIが自動で最適な物件をマッチング。お客様のニーズに合わせた提案を自動化。</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📊</div>
                    <h3>AIマンション査定</h3>
                    <p>最新のAI技術で正確な査定額を算出。お客様の信頼を獲得。</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">✏️</div>
                    <h3>いつでも編集可能</h3>
                    <p>情報変更もリアルタイムで反映。印刷不要で常に最新の情報を提供。</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="pricing">
        <div class="container">
            <h2>有効的な活用方法が簡単に理解できる</h2>
            <p class="section-subtitle">30～60秒のアニメ動画で、サービスを簡単にご紹介</p>
            
            <div class="video-container">
                <div class="video-wrapper">
                    <video class="service-video" controls>
                        <source src="assets/video/card.mp4" type="video/mp4">
                        お使いのブラウザは動画再生に対応していません。
                    </video>
                </div>
                <p class="video-description">新規店舗・既存店舗共通でご利用いただける、契約を促す動画サンプルです。</p>
            </div>
        </div>
    </section>


    <!-- How to Use Section -->
    <section id="howto" class="howto">
        <div class="container">
            <h2>簡単4ステップで始められる</h2>
            <p class="section-subtitle">専門知識不要。誰でも簡単にプロフェッショナルなデジタル名刺を作成</p>
            
            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">STEP 1</div>
                    <div class="step-icon">📝</div>
                    <h3>情報入力</h3>
                    <p>会社名、写真、連絡先など17項目を入力。簡単なフォームで3分で完了。</p>
                </div>
                
                <div class="step-card">
                    <div class="step-number">STEP 2</div>
                    <div class="step-icon">👁️</div>
                    <h3>リアルタイムプレビュー</h3>
                    <p>入力内容がリアルタイムで反映。デザインを確認しながら作成。</p>
                </div>
                
                <div class="step-card">
                    <div class="step-number">STEP 3</div>
                    <div class="step-icon">📱</div>
                    <h3>QRコード生成</h3>
                    <p>自動でQRコードを生成。名刺に印刷して配布準備完了。</p>
                </div>
                
                <div class="step-card">
                    <div class="step-number">STEP 4</div>
                    <div class="step-icon">🚀</div>
                    <h3>共有・運用開始</h3>
                    <p>お客様がQRコードをスキャン。LINE追加や物件提案がスムーズに。</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Tools & Communication Section -->
    <section id="tools" class="tools-section">
        <div class="container">
            <h2>不動産テックツールとコミュニケーション機能</h2>
            <p class="section-subtitle">デジタル名刺から直接アクセスできる、充実した不動産営業ツール</p>
            
            <!-- Real Estate Tech Tools -->
            <div class="tools-subsection">
                <h3 class="subsection-title">不動産テックツール</h3>
                <p class="subsection-description">名刺のQRコードから、お客様が直接アクセスできる7つの強力なツールをご用意しています。</p>
                
                <div class="tools-grid">
                    <div class="tool-card">
                        <div class="tool-number">1</div>
                        <h4>全国マンションデータベース</h4>
                        <p class="tool-type">＜売り・買い＞</p>
                        <p class="tool-description">全国の分譲マンションの95％以上を網羅。販売履歴の閲覧が最も多い。</p>
                        <a href="https://self-in.com/●●●●●●/mdb/" target="_blank" class="tool-link">詳細を見る →</a>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-number">2</div>
                        <h4>物件提案ロボ</h4>
                        <p class="tool-type">＜買い＞</p>
                        <p class="tool-description">希望条件に合致している中古マンション・中古一戸建て情報を売り出し開始から24時間以内に評価付きで届けるツール</p>
                        <a href="https://self-in.net/rlp/index.php?id=●●●●●●" target="_blank" class="tool-link">詳細を見る →</a>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-number">3</div>
                        <h4>土地情報ロボ</h4>
                        <p class="tool-type">＜買い＞</p>
                        <p class="tool-description">希望条件に合致している土地情報を売り出し開始から24時間以内に届けるツール</p>
                        <a href="https://self-in.net/llp/index.php?id=●●●●●●" target="_blank" class="tool-link">詳細を見る →</a>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-number">4</div>
                        <h4>AIマンション査定</h4>
                        <p class="tool-type">＜売り＞</p>
                        <p class="tool-description">個人情報不要でマンションの査定を行うツール。「マンション」「査定」というキーワードでTOP表示されているコンテンツ。</p>
                        <a href="https://self-in.com/●●●●●●/ai/" target="_blank" class="tool-link">詳細を見る →</a>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-number">5</div>
                        <h4>セルフィン</h4>
                        <p class="tool-type">＜買い＞</p>
                        <p class="tool-description">中古マンション・中古一戸建ての物件情報から物件の良し悪しを自動判定するツール</p>
                        <a href="https://self-in.net/slp/index.php?id=●●●●●●" target="_blank" class="tool-link">詳細を見る →</a>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-number">6</div>
                        <h4>オーナーコネクト</h4>
                        <p class="tool-type">＜売り＞</p>
                        <p class="tool-description">マンション所有者向けの資産ウォッチツール。週に1回の査定レポートを始め、同じマンションの売り出し情報をキャッチしたら直ちにお届けするツール</p>
                        <a href="https://self-in.net/olp/index.php?id=●●●●●●" target="_blank" class="tool-link">詳細を見る →</a>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-number">7</div>
                        <h4>統合LP</h4>
                        <p class="tool-type">全サービス統合</p>
                        <p class="tool-description">上記6つのサービスを一括で紹介しているページです。</p>
                        <a href="https://self-in.net/alp/index.php?id=●●●●●●" target="_blank" class="tool-link">詳細を見る →</a>
                    </div>
                </div>
            </div>
            
            <!-- Communication Features -->
            <div class="communication-subsection">
                <h3 class="subsection-title">コミュニケーション機能</h3>
                <p class="subsection-description">名刺のQRコードを読み込むことで、メッセージアプリやSNSでのコミュニケーションをワンタップで確立。入力時に表示させたい機能にチェックを入れると、デジタル名刺に反映されます。</p>
                
                <div class="communication-grid">
                    <div class="communication-category">
                        <h4 class="category-title">メッセージアプリ</h4>
                        <p class="category-note">一番簡単につながる方法を教えてください。ここが重要になります。</p>
                        <ul class="communication-list">
                            <li>
                                <img src="assets/images/icons/line.png" alt="LINE">
                            </li>
                            <li>
                                <img src="assets/images/icons/messenger.png" alt="Messenger">
                            </li>
                            <li>
                                <img src="assets/images/icons/whatsapp.png" alt="WhatsApp">
                            </li>
                            <li>
                                <img src="assets/images/icons/message.png" alt="+メッセージ">
                            </li>
                            <li>
                                <img src="assets/images/icons/chatwork.png" alt="Chatwork">
                            </li>
                            <li>
                                <img src="assets/images/icons/andpad.png" alt="Andpad">
                            </li>
                        </ul>
                        <p class="category-future">※近い将来、「不動産MYページ」という独自コミュニケーションツールをリリースし、追加する予定です。</p>
                    </div>
                    
                    <div class="communication-category">
                        <h4 class="category-title">SNS</h4>
                        <p class="category-note">リンク先を入力できるようにする</p>
                        <ul class="communication-list">
                            <li>
                                <img src="assets/images/icons/instagram.png" alt="Instagram">
                            </li>
                            <li>
                                <img src="assets/images/icons/facebook.png" alt="Facebook">
                            </li>
                            <li>
                                <img src="assets/images/icons/twitter.png" alt="X (Twitter)">
                            </li>
                            <li>
                                <img src="assets/images/icons/youtube.png" alt="YouTube">
                            </li>
                            <li>
                                <img src="assets/images/icons/tiktok.png" alt="TikTok">
                            </li>
                            <li>
                                <img src="assets/images/icons/note.png" alt="note">
                            </li>
                            <li>
                                <img src="assets/images/icons/pinterest.png" alt="Pinterest">
                            </li>
                            <li>
                                <img src="assets/images/icons/threads.png" alt="Threads">
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <div class="container">
        <div class="contact-save-info">
            <h4 class="info-title">不動産エージェントの管理画面</h4>
            <a class="save-button-demo" href="./admin/dashboard.php">
                <span class="save-btn">名刺情報の保存ボタン</span>
            </a>
        </div>
    </div>
    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>不動産デジタル名刺</h4>
                    <p>不動産営業担当者向けのデジタル名刺作成システム。QRコード付きで、不動産テックツールと連携。</p>
                </div>
                <div class="footer-section">
                    <h4>サービス</h4>
                    <ul>
                        <li><a href="#features">機能</a></li>
                        <li><a href="#pricing">動画</a></li>
                        <li><a href="#howto">使い方</a></li>
                        <li><a href="#tools">ツール</a></li>
                        <!-- <li><a href="#pricing">料金</a></li> -->
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>サポート</h4>
                    <ul>
                        <li><a href="help.php">ヘルプセンター</a></li>
                        <li><a href="contact.php">お問い合わせ</a></li>
                        <li><a href="terms.php">利用規約</a></li>
                        <li><a href="privacy.php">プライバシーポリシー</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 不動産デジタル名刺. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/js/mobile-menu.js"></script>
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

