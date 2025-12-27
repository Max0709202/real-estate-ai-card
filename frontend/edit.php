<?php
/**
 * Business Card Editor Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

// 認証チェック
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Get subscription information
$subscriptionInfo = null;
$hasActiveSubscription = false;
try {
    require_once __DIR__ . '/../backend/config/database.php';
    $database = new Database();
    $db = $database->getConnection();

    // Get subscription information - check for any subscription (not just active ones)
    $stmt = $db->prepare("
        SELECT s.id, s.stripe_subscription_id, s.status, s.next_billing_date, s.cancelled_at,
               bc.id as business_card_id, bc.card_status, bc.payment_status
        FROM subscriptions s
        JOIN business_cards bc ON s.business_card_id = bc.id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $subscriptionInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    // Also check if user has completed payment (for cases where subscription might not exist yet)
    $hasCompletedPayment = false;
    if (!$subscriptionInfo) {
        $stmt = $db->prepare("
            SELECT bc.payment_status
            FROM business_cards bc
            WHERE bc.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $cardInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cardInfo && in_array($cardInfo['payment_status'], ['CR', 'BANK_PAID'])) {
            $hasCompletedPayment = true;
        }
    }

    // Show cancel button if:
    // 1. Has active/trialing subscription, OR
    // 2. Has completed payment (for new users who should have subscription)
    if ($subscriptionInfo && in_array($subscriptionInfo['status'], ['active', 'trialing', 'past_due', 'incomplete'])) {
        $hasActiveSubscription = true;
    } elseif ($hasCompletedPayment) {
        // Check if user is new user type (should have subscription)
        $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userType = $stmt->fetchColumn();
        if ($userType === 'new') {
            $hasActiveSubscription = true; // Show button even if subscription not found (will be created)
        }
    }
} catch (Exception $e) {
    error_log("Error fetching subscription info: " . $e->getMessage());
}

// Default greeting messages
$defaultGreetings = [
    [
        'title' => '笑顔が増える「住み替え」を叶えます',
        'content' => '初めての売買で感じる不安や疑問。「あなたに頼んでよかった」と言っていただけるよう、理想の住まい探しと売却を全力で伴走いたします。私は、お客様が描く「10年後の幸せな日常」を第一に考えます。'
    ],
    [
        'title' => '自宅は大きな貯金箱',
        'content' => '「不動産売買は人生最大の投資」という視点に立ち、物件のメリットだけでなく、将来のリスクやデメリットも隠さずお伝えするのが信条です。感情に流されない、確実な資産形成と納得のいく取引をサポートします。'
    ],
    [
        'title' => 'お客様に「情報武装」をご提案',
        'content' => '「この価格は妥当なのだろうか？」「もっとよい物件情報は無いのだろうか？」私は全ての情報をお客様に開示いたしますが、お客様に「情報武装」していただく事で、それをさらに担保いたします。他のエージェントにはない、私独自のサービスをご活用ください。'
    ],
    [
        'title' => 'お客様を「3つの疲労」から解放いたします',
        'content' => '一つ目は、ポータルサイト巡りの「情報収集疲労」。二つ目は、不動産会社への「問い合わせ疲労」、専門知識不足による「判断疲労」です。私がご提供するテックツールで、情報収集は自動化、私が全ての情報を公開しますので多くの不動産会社に問い合わせることも不要、物件情報にAI評価がついているので客観的判断も自動化されます。'
    ],
    [
        'title' => '忙しい子育て世代へ。手間を省くスマート売買',
        'content' => '「売り」と「買い」を同時に進める住み替えは手続きが煩雑になりがちです。忙しいご夫婦に代わり、書類作成から金融機関との折衝、内覧の調整まで私が窓口となってスムーズに進めます。お子様連れでの内覧や打ち合わせも大歓迎です。ご家族の貴重な時間を奪わないよう、迅速かつ丁寧な段取りをお約束します。'
    ]
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>名刺編集 - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/edit.css">
    <link rel="stylesheet" href="assets/css/register.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <link rel="stylesheet" href="assets/css/admin.css">

    <!-- Cropper.js CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css">
    <style>
        .btn-secondary {
            background: #6c757d;
            color: #fff;
            border: none;
            font-weight: 500;
            transition: background 0.3s;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <?php 
    $showNavLinks = false; // Hide nav links on edit page
    include __DIR__ . '/includes/header.php'; 
    ?>
    
    <div class="edit-container">
        <header class="edit-header" style="padding-top: 3rem;">
            <div class="edit-header-content">
                <h1>マイページ（デジタル名刺作成・編集）</h1>
            </div>
            <button type="button" id="direct-input-btn" class="btn-direct-input">
                <span class="direct-input-text">
                    <span class="direct-text">プレビュー</span>
                    <!-- <span class="input-text">入力</span> -->
                </span>
            </button>
        </header>
        <div class="edit-content">
            <div class="edit-sidebar">
                <nav class="edit-nav">
                    <a href="#header-greeting" class="nav-item active" data-step="1" data-section="header-greeting-section">
                        <span class="step-number">1/5</span>
                        <span class="step-label">ヘッダー・挨拶</span>
                    </a>
                    <a href="#company-profile" class="nav-item" data-step="2" data-section="company-profile-section">
                        <span class="step-number">2/5</span>
                        <span class="step-label">会社プロフィール</span>
                    </a>
                    <a href="#personal-info" class="nav-item" data-step="3" data-section="personal-info-section">
                        <span class="step-number">3/5</span>
                        <span class="step-label">個人情報</span>
                    </a>
                    <a href="#tech-tools" class="nav-item" data-step="4" data-section="tech-tools-section">
                        <span class="step-number">4/5</span>
                        <span class="step-label">テックツール</span>
                    </a>
                    <a href="#communication" class="nav-item" data-step="5" data-section="communication-section">
                        <span class="step-number">5/5</span>
                        <span class="step-label">コミュニケーション</span>
                    </a>
                </nav>
            </div>

            <div class="edit-main">
                <!-- Step 1: Header & Greeting -->
                <div id="header-greeting-section" class="edit-section active">
                    <h2>ヘッダー・挨拶部</h2>
                    <p class="step-description">会社情報とご挨拶文を入力してください</p>
                    <form id="header-greeting-form" class="edit-form">
                        <div class="form-group">
                            <label>会社名 <span class="required">*</span></label>
                            <input type="text" name="company_name" class="form-control" required>
                        </div>
                        
                        <div class="form-section">
                            <h3>ロゴマーク</h3>
                            <div class="upload-area" data-upload-id="company_logo">
                                <input type="file" id="company_logo" accept="image/*" style="display: none;">
                                <button type="button" class="btn-upload" onclick="document.getElementById('company_logo').click()">アップロード</button>
                                <div class="upload-preview"></div>
                                <small>ファイルを選択するか、ここにドラッグ&ドロップしてください（自動でリサイズされます）<br>対応形式：JPEG、PNG、GIF、WebP</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>顔写真</h3>
                            <div class="upload-area" data-upload-id="profile_photo">
                                <input type="file" id="profile_photo" accept="image/*" style="display: none;">
                                <button type="button" class="btn-upload" onclick="document.getElementById('profile_photo').click()">アップロード</button>
                                <div class="upload-preview"></div>
                                <small>ファイルを選択するか、ここにドラッグ&ドロップしてください（自動でリサイズされます）<br>対応形式：JPEG、PNG、GIF、WebP</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>ご挨拶 <span class="required">*</span></h3>
                            <p class="section-note">
                                ・挨拶文は１つは必ずご入力ください。<br>
                                ・５つの挨拶文例をそのまま使用できます。<br>
                                ・挨拶文の順序を上下のボタン・ドラッグで変更できます。
                            </p>
                            <div style="text-align: center; margin: 1rem 0;">
                                <button type="button" class="btn-add" onclick="addGreeting()">挨拶文を追加</button>
                                <button type="button" class="btn-restore-defaults" onclick="restoreDefaultGreetings()">５つの挨拶文例を再表示する</button>
                            </div>
                            <div id="greetings-list">
                                <?php foreach ($defaultGreetings as $index => $greeting): ?>
                                <div class="greeting-item" data-order="<?php echo $index; ?>">
                                    <div class="greeting-header">
                                        <span class="greeting-number"><?php echo $index + 1; ?></span>
                                        <div class="greeting-actions">
                                            <button type="button" class="btn-move-up" onclick="moveGreeting(<?php echo $index; ?>, 'up')" <?php echo $index === 0 ? 'disabled' : ''; ?>>↑</button>
                                            <button type="button" class="btn-move-down" onclick="moveGreeting(<?php echo $index; ?>, 'down')" <?php echo $index === 4 ? 'disabled' : ''; ?>>↓</button>
                                            <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>タイトル</label>
                                        <input type="text" name="greeting_title[]" class="form-control" value="<?php echo htmlspecialchars($greeting['title']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>本文</label>
                                        <textarea name="greeting_content[]" class="form-control" rows="4" required><?php echo htmlspecialchars($greeting['content']); ?></textarea>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </form>
                </div>

                <!-- Step 2: Company Profile -->
                <div id="company-profile-section" class="edit-section">
                    <h2>会社プロフィール部</h2>
                    <p class="step-description">会社情報を入力してください</p>
                    <form id="company-profile-form" class="edit-form">
                        <?php
                        // Japanese prefectures
                        $prefectures = [
                            '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
                            '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
                            '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
                            '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
                            '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
                            '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
                            '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'
                        ];
                        ?>
                        <div class="form-section">
                            <h3>宅建業者番号<span class="required-asterisk">※</span></h3>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>都道府県</label>
                                    <select name="real_estate_license_prefecture" id="license_prefecture" class="form-control" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($prefectures as $pref): ?>
                                        <option value="<?php echo htmlspecialchars($pref); ?>"><?php echo htmlspecialchars($pref); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>更新番号</label>
                                    <select name="real_estate_license_renewal_number" id="license_renewal" class="form-control" required>
                                        <option value="">選択してください</option>
                                        <?php for ($i = 1; $i <= 20; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>登録番号</label>
                                    <input type="text" name="real_estate_license_registration_number" id="license_registration" class="form-control" placeholder="例：12345" required>
                                    <!-- <button type="button" class="btn-outline" id="lookup-license" style="margin-top: 0.5rem;">住所を自動入力</button> -->
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>会社名 <span class="required">*</span></label>
                            <input type="text" name="company_name_profile" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label>郵便番号 <span class="required">*</span></label>
                            <input type="text" name="company_postal_code" id="company_postal_code" class="form-control" placeholder="例：123-4567" required>
                            <button type="button" class="btn-outline" id="lookup-address" style="margin-top: 0.5rem;">住所を自動入力</button>
                        </div>

                        <div class="form-group">
                            <label>住所 <span class="required">*</span></label>
                            <input type="text" name="company_address" id="company_address" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label>会社電話番号</label>
                            <input type="tel" name="company_phone" class="form-control" placeholder="例：03-1234-5678">
                        </div>

                        <div class="form-group">
                            <label>会社HP URL</label>
                            <input type="url" name="company_website" class="form-control" placeholder="https://example.com">
                        </div>

                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </form>
                </div>

                <!-- Step 3: Personal Information -->
                <div id="personal-info-section" class="edit-section">
                    <h2>個人情報</h2>
                    <p class="step-description">あなたの個人情報を入力してください</p>
                    <form id="personal-info-form" class="edit-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>部署</label>
                                <input type="text" name="branch_department" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>役職</label>
                                <input type="text" name="position" class="form-control">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>姓 <span class="required">*</span></label>
                                <input type="text" name="last_name" id="edit_last_name" class="form-control" required placeholder="例：山田">
                            </div>
                            <div class="form-group">
                                <label>名 <span class="required">*</span></label>
                                <input type="text" name="first_name" id="edit_first_name" class="form-control" required placeholder="例：太郎">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>姓（ローマ字）</label>
                                <small style="display: block; color: #666; margin-bottom: 0.5rem; font-size: 0.875rem;">最初の文字が小文字の場合は、自動的に大文字に変換されます。</small>
                                <input type="text" name="last_name_romaji" id="edit_last_name_romaji" class="form-control" placeholder="例：Yamada" inputmode="latin" autocomplete="family-name" autocapitalize="words" spellcheck="false">
                            </div>
                            <div class="form-group">
                                <label>名（ローマ字）</label>
                                <small style="display: block; color: #666; margin-bottom: 0.5rem; font-size: 0.875rem;">最初の文字が小文字の場合は、自動的に大文字に変換されます。</small>
                                <input type="text" name="first_name_romaji" id="edit_first_name_romaji" class="form-control" placeholder="例：Taro" inputmode="latin" autocomplete="given-name" autocapitalize="words" spellcheck="false">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>電話番号 <span class="required">*</span></label>
                            <input type="tel" name="mobile_phone" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label>生年月日</label>
                            <input type="date" name="birth_date" class="form-control">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>現在の居住地</label>
                                <input type="text" name="current_residence" class="form-control" placeholder="例：東京都渋谷区">
                            </div>
                            <div class="form-group">
                                <label>出身地</label>
                                <input type="text" name="hometown" class="form-control" placeholder="例：大阪府大阪市">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>出身校</label>
                            <input type="text" name="alma_mater" class="form-control" placeholder="例：○○大学 経済学部">
                        </div>

                        <div class="form-section">
                            <h3>資格</h3>
                            <div class="qualifications-section">
                                <div class="form-group">
                                    <label>主な資格（選択）</label>
                                    <div class="checkbox-list">
                                        <label class="checkbox-item">
                                            <input type="checkbox" name="qualification_takken" value="1">
                                            <span>宅地建物取引士</span>
                                        </label>
                                        <label class="checkbox-item">
                                            <input type="checkbox" name="qualification_kenchikushi" value="1">
                                            <span>建築士</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>その他の資格（自由入力）</label>
                                    <textarea name="qualifications_other" class="form-control" rows="2" placeholder="その他の資格を入力してください"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>趣味</label>
                            <textarea name="hobbies" class="form-control" rows="2" placeholder="趣味や興味があることを入力してください"></textarea>
                        </div>

                        <div class="form-section">
                            <h3>フリー入力欄</h3>
                            <p class="section-note">自由にアピールポイントや追加情報を入力できます。YouTubeのリンクなども貼り付けられます。</p>
                            <div class="form-group">
                                <label>テキスト・画像セット <button type="button" class="btn-add-small" onclick="addFreeInputPair()">追加</button></label>
                                <div id="free-input-pairs-container">
                                    <div class="free-input-pair-item">
                                        <!-- Text Input -->
                                        <div class="form-group">
                                            <label>テキスト</label>
                                        <textarea name="free_input_text[]" class="form-control" rows="4" placeholder="自由に入力してください。&#10;例：YouTubeリンク: https://www.youtube.com/watch?v=xxxxx"></textarea>
                                    </div>
                                        <!-- Image/Banner Input -->
                            <div class="form-group">
                                            <label>画像・バナー（リンク付き画像）</label>
                                        <div class="upload-area" data-upload-id="free_image_0">
                                            <input type="file" name="free_image[]" accept="image/*" style="display: none;">
                                            <div class="upload-preview"></div>
                                            <button type="button" class="btn-outline" onclick="this.closest('.upload-area').querySelector('input[type=\"file\"]').click()">
                                                画像をアップロード
                                            </button>
                                            <small>ファイルを選択するか、ここにドラッグ&ドロップしてください<br>対応形式：JPEG、PNG、GIF、WebP</small>
                                        </div>
                                        <div class="form-group" style="margin-top: 0.5rem;">
                                            <label>画像のリンク先URL（任意）</label>
                                            <input type="url" name="free_image_link[]" class="form-control" placeholder="https://example.com">
                                        </div>
                                        </div>
                                        <button type="button" class="btn-delete-small" onclick="removeFreeInputPair(this)" style="display: none;">削除</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </form>
                </div>

                <!-- Step 4: Tech Tools -->
                <div id="tech-tools-section" class="edit-section">
                    <h2>テックツール選択</h2>
                    <p class="step-description">表示させるテックツールを選択してください（最低2つ以上）</p>
                    <div id="tech-tools-list" class="tech-tools-grid">
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-primary" onclick="saveTechTools()">保存して次へ</button>
                    </div>
                </div>

                <!-- Step 5: Communication Functions -->
                <div id="communication-section" class="edit-section">
                    <h2>コミュニケーション機能部</h2>
                    <p class="step-description">メッセージアプリやSNSの連携を設定してください</p>
                    
                    <div class="form-section">
                        <h3>メッセージアプリ部</h3>
                        <p class="section-note">一番簡単につながる方法を教えてください。ここが重要になります。</p>
                        
                        <div class="communication-grid" id="message-apps-grid">
                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(0, 'up', 'message')" disabled>↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(0, 'down', 'message')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_line" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/line.png" alt="LINE">
                                    </div>
                                    <span>LINE</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="text" name="comm_line_id" class="form-control" placeholder="LINE IDまたはURL">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(1, 'up', 'message')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(1, 'down', 'message')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_messenger" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/messenger.png" alt="Messenger">
                                    </div>
                                    <span>Messenger</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="text" name="comm_messenger_id" class="form-control" placeholder="Messenger IDまたはURL">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(2, 'up', 'message')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(2, 'down', 'message')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_whatsapp" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/whatsapp.png" alt="WhatsApp">
                                    </div>
                                    <span>WhatsApp</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="text" name="comm_whatsapp_id" class="form-control" placeholder="WhatsApp IDまたはURL">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(3, 'up', 'message')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(3, 'down', 'message')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_plus_message" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/message.png" alt="+メッセージ">
                                    </div>
                                    <span>+メッセージ</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="text" name="comm_plus_message_id" class="form-control" placeholder="+メッセージ IDまたはURL">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(4, 'up', 'message')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(4, 'down', 'message')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_chatwork" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/chatwork.png" alt="Chatwork">
                                    </div>
                                    <span>Chatwork</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="text" name="comm_chatwork_id" class="form-control" placeholder="Chatwork IDまたはURL">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(5, 'up', 'message')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(5, 'down', 'message')" disabled>↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_andpad" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/andpad.png" alt="Andpad">
                                    </div>
                                    <span>Andpad</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="text" name="comm_andpad_id" class="form-control" placeholder="Andpad IDまたはURL">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>SNS部</h3>
                        <p class="section-note">SNSのリンク先を入力できます。</p>
                        
                        <div class="communication-grid" id="sns-grid">
                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(0, 'up', 'sns')" disabled>↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(0, 'down', 'sns')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_instagram" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/instagram.png" alt="Instagram">
                                    </div>
                                    <span>Instagram</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_instagram_url" class="form-control" placeholder="https://instagram.com/...">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(1, 'up', 'sns')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(1, 'down', 'sns')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_facebook" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/facebook.png" alt="Facebook">
                                    </div>
                                    <span>Facebook</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_facebook_url" class="form-control" placeholder="https://facebook.com/...">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(2, 'up', 'sns')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(2, 'down', 'sns')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_twitter" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/twitter.png" alt="X (Twitter)">
                                    </div>
                                    <span>X (Twitter)</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_twitter_url" class="form-control" placeholder="https://x.com/...">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(3, 'up', 'sns')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(3, 'down', 'sns')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_youtube" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/youtube.png" alt="YouTube">
                                    </div>
                                    <span>YouTube</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_youtube_url" class="form-control" placeholder="https://youtube.com/...">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(4, 'up', 'sns')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(4, 'down', 'sns')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_tiktok" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/tiktok.png" alt="TikTok">
                                    </div>
                                    <span>TikTok</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_tiktok_url" class="form-control" placeholder="https://tiktok.com/...">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(5, 'up', 'sns')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(5, 'down', 'sns')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_note" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/note.png" alt="note">
                                    </div>
                                    <span>note</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_note_url" class="form-control" placeholder="https://note.com/...">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(6, 'up', 'sns')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(6, 'down', 'sns')">↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_pinterest" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/pinterest.png" alt="Pinterest">
                                    </div>
                                    <span>Pinterest</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_pinterest_url" class="form-control" placeholder="https://pinterest.com/...">
                                </div>
                            </div>

                            <div class="communication-item" data-comm-type="sns">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(7, 'up', 'sns')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(7, 'down', 'sns')" disabled>↓</button>
                                </div>
                                <label class="communication-checkbox">
                                    <input type="checkbox" name="comm_threads" value="1">
                                    <div class="comm-icon">
                                        <img src="assets/images/icons/threads.png" alt="Threads">
                                    </div>
                                    <span>Threads</span>
                                </label>
                                <div class="comm-details" style="display: none;">
                                    <input type="url" name="comm_threads_url" class="form-control" placeholder="https://threads.net/...">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="btn-primary" onclick="saveCommunicationMethods()">保存して次へ</button>
                </div>
            </div>

            <div class="edit-sidebar-actions">
                <div style="display: flex; gap: 1rem; justify-content: center; flex-direction: column; padding-inline: 10px;">
                    <button type="button" id="direct-input-btn-pc" class="btn-direct-input btn-direct-input-pc">
                        <span class="direct-input-text">
                            <span class="direct-text">プレビュー</span>
                        </span>
                    </button>
                    <a href="auth/forgot-password.php" class="btn-secondary" style="text-align: center; padding: 0.75rem; text-decoration: none; display: inline-block; border-radius: 4px;">パスワードリセット</a>
                    <a href="auth/reset-email.php" class="btn-secondary" style="text-align: center; padding: 0.75rem; text-decoration: none; display: inline-block; border-radius: 4px;">メールアドレスリセット</a>
                    <?php if ($hasActiveSubscription): ?>
                    <button type="button" id="cancel-subscription-btn" class="btn-secondary" style="text-align: center; padding: 0.75rem; border-radius: 4px; cursor: pointer;">
                        利用を停止する
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($subscriptionInfo || (isset($hasCompletedPayment) && $hasCompletedPayment)): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 4px; font-size: 0.875rem;">
                    <div style="margin-bottom: 0.5rem;"><strong>サブスクリプション状況</strong></div>
                    <?php if ($subscriptionInfo): ?>
                    <div>ステータス: <span id="subscription-status"><?php
                        $statusLabels = [
                            'active' => 'アクティブ',
                            'trialing' => 'トライアル中',
                            'past_due' => '延滞中',
                            'incomplete' => '未完了',
                            'incomplete_expired' => '期限切れ',
                            'canceled' => 'キャンセル済み',
                            'unpaid' => '未払い'
                        ];
                        echo htmlspecialchars($statusLabels[$subscriptionInfo['status']] ?? $subscriptionInfo['status']);
                    ?></span></div>
                    <?php if ($subscriptionInfo['next_billing_date']): ?>
                    <div>次回請求日: <?php echo htmlspecialchars($subscriptionInfo['next_billing_date']); ?></div>
                    <?php endif; ?>
                    <?php if ($subscriptionInfo['cancelled_at']): ?>
                    <div style="color: #dc3545; margin-top: 0.5rem;">キャンセル予定: <?php echo htmlspecialchars($subscriptionInfo['cancelled_at']); ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div>ステータス: <span id="subscription-status">支払い完了（サブスクリプション作成中）</span></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Image Cropper Modal -->
    <div id="image-cropper-modal" class="modal-overlay" style="display: none; z-index: 10000; opacity: 1; visibility: visible;">
        <div class="modal-content" style="max-width: 90%; max-height: 90vh; overflow: auto; background: white; border-radius: 8px; padding: 0;">
            <div style="padding: 20px;">
                <h3 style="margin-bottom: 20px; color: #333;">画像をトリミング</h3>
                <p style="margin-bottom: 15px; color: #666; font-size: 14px;">
                    画像のサイズを調整し、必要な部分を選択してください。指でドラッグしてトリミングエリアを移動・拡大縮小できます。
                </p>
                <div style="width: 100%; max-width: 800px; margin: 0 auto; background: #f5f5f5; border-radius: 4px; padding: 10px; display: flex; justify-content: center; align-items: center;">
                    <img id="cropper-image" style="max-width: 100%; max-height: 60vh; display: block; object-fit: contain; width: auto; height: auto;">
                </div>
                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <button type="button" id="crop-cancel-btn" class="btn-secondary" style="padding: 10px 20px; width: auto; cursor: pointer;">キャンセル</button>
                    <button type="button" id="crop-confirm-btn" class="btn-primary" style="padding: 10px 20px; width: auto;">トリミングを適用</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cropper.js -->
    <script>
        // Make BASE_URL available to JavaScript
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
    <script src="assets/js/auto-save.js"></script>
    <script src="assets/js/edit.js"></script>
    <script src="assets/js/mobile-menu.js"></script>
    <script>
        // Direct Input button handler and mobile touch support
        document.addEventListener('DOMContentLoaded', function() {
            // SP version button (in header)
            const directInputBtn = document.getElementById('direct-input-btn');
            // PC version button (in sidebar)
            const directInputBtnPc = document.getElementById('direct-input-btn-pc');

            // Function to handle button click
            function handleDirectInputClick() {
                if (typeof showDirectInputModal === 'function') {
                    showDirectInputModal();
                }
            }

            if (directInputBtn) {
                directInputBtn.addEventListener('click', handleDirectInputClick);
                // Mobile: Add 'touched' class on first tap to keep expanded state
                if (window.innerWidth <= 768) {
                    directInputBtn.addEventListener('touchstart', function() {
                        this.classList.add('touched');
                    }, { once: true });
                }
            }

            if (directInputBtnPc) {
                directInputBtnPc.addEventListener('click', handleDirectInputClick);
            }
        });

        // Collect current form data from all forms
        function collectCurrentFormData() {
            const data = {};

            // Step 1: Header & Greeting form
            const headerGreetingForm = document.getElementById('header-greeting-form');
            if (headerGreetingForm) {
                const formData = new FormData(headerGreetingForm);
                for (let [key, value] of formData.entries()) {
                    if (value) data[key] = value;
                }

                // Collect greetings
                const greetingItems = document.querySelectorAll('#greetings-list .greeting-item, #greetings-container .greeting-item');
                const greetings = [];
                greetingItems.forEach((item, index) => {
                    if (item.dataset.cleared === 'true') return;
                    const titleInput = item.querySelector('input[name="greeting_title[]"]') || item.querySelector('.greeting-title input');
                    const contentTextarea = item.querySelector('textarea[name="greeting_content[]"]') || item.querySelector('.greeting-content textarea');
                    const title = titleInput ? (titleInput.value || '').trim() : '';
                    const content = contentTextarea ? (contentTextarea.value || '').trim() : '';
                    if (title || content) {
                        greetings.push({
                            title: title,
                            content: content,
                            display_order: index
                        });
                    }
                });
                if (greetings.length > 0) {
                    data.greetings = greetings;
                }

                // Get logo and photo from preview or existing data
                const logoPreview = document.querySelector('[data-upload-id="company_logo"] .upload-preview img');
                if (logoPreview && logoPreview.src) {
                    const logoPath = logoPreview.src.replace(window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/'), '');
                    if (logoPath && !logoPath.startsWith('data:')) {
                        data.company_logo = logoPath.replace(/^\.\.\//, '');
                    }
                }
                const photoPreview = document.querySelector('[data-upload-id="profile_photo"] .upload-preview img');
                if (photoPreview && photoPreview.src) {
                    const photoPath = photoPreview.src.replace(window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/'), '');
                    if (photoPath && !photoPath.startsWith('data:')) {
                        data.profile_photo = photoPath.replace(/^\.\.\//, '');
                    }
                }
            }

            // Step 2: Company Profile form
            const companyProfileForm = document.getElementById('company-profile-form');
            if (companyProfileForm) {
                const formData = new FormData(companyProfileForm);
                for (let [key, value] of formData.entries()) {
                    if (value) {
                        if (key === 'company_name_profile') {
                            data.company_name = String(value).trim();
                        } else {
                            data[key] = value;
                        }
                    }
                }
            }

            // Step 3: Personal Info form
            const personalInfoForm = document.getElementById('personal-info-form');
            if (personalInfoForm) {
                const formData = new FormData(personalInfoForm);
                for (let [key, value] of formData.entries()) {
                    if (value) data[key] = value;
                }

                // Combine names
                const lastName = data.last_name || '';
                const firstName = data.first_name || '';
                if (lastName || firstName) {
                    data.name = (lastName + ' ' + firstName).trim();
                }

                const lastNameRomaji = data.last_name_romaji || '';
                const firstNameRomaji = data.first_name_romaji || '';
                if (lastNameRomaji || firstNameRomaji) {
                    data.name_romaji = (lastNameRomaji + ' ' + firstNameRomaji).trim();
                }
            }

            // Step 4: Tech Tools (checkboxes)
            const techToolCheckboxes = document.querySelectorAll('#tech-tools-grid input[type="checkbox"]:checked');
            if (techToolCheckboxes.length > 0) {
                const techTools = [];
                techToolCheckboxes.forEach((checkbox, index) => {
                    techTools.push({
                        tool_type: checkbox.value,
                        display_order: index
                    });
                });
                data.tech_tools = techTools;
            }

            // Step 5: Communication methods
            const messageItems = document.querySelectorAll('#communication-messages .communication-item');
            const messages = [];
            messageItems.forEach((item, index) => {
                const labelInput = item.querySelector('input[name="message_label[]"]');
                const urlInput = item.querySelector('input[name="message_url[]"]');
                const label = labelInput ? (labelInput.value || '').trim() : '';
                const url = urlInput ? (urlInput.value || '').trim() : '';
                if (label || url) {
                    messages.push({
                        label: label,
                        url: url,
                        display_order: index
                    });
                }
            });
            if (messages.length > 0) {
                data.communication_methods = messages;
            }

            const snsItems = document.querySelectorAll('#communication-sns .communication-item');
            const sns = [];
            snsItems.forEach((item, index) => {
                const labelInput = item.querySelector('input[name="sns_label[]"]');
                const urlInput = item.querySelector('input[name="sns_url[]"]');
                const label = labelInput ? (labelInput.value || '').trim() : '';
                const url = urlInput ? (urlInput.value || '').trim() : '';
                if (label || url) {
                    sns.push({
                        label: label,
                        url: url,
                        display_order: index
                    });
                }
            });
            if (sns.length > 0) {
                if (!data.communication_methods) data.communication_methods = [];
                data.communication_methods = data.communication_methods.concat(sns);
            }

            // Free input pairs
            const freeInputPairs = document.querySelectorAll('.free-input-pair');
            const freeInputs = [];
            freeInputPairs.forEach((pair, index) => {
                const textInput = pair.querySelector('textarea[name="free_text[]"]');
                const imagePreview = pair.querySelector('.upload-preview img');
                const text = textInput ? (textInput.value || '').trim() : '';
                let imagePath = '';
                if (imagePreview && imagePreview.src) {
                    const path = imagePreview.src.replace(window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/'), '');
                    if (path && !path.startsWith('data:')) {
                        imagePath = path.replace(/^\.\.\//, '');
                    }
                }
                if (text || imagePath) {
                    freeInputs.push({
                        text: text,
                        image_path: imagePath,
                        display_order: index
                    });
                }
            });
            if (freeInputs.length > 0) {
                data.free_input = freeInputs;
            }

            return data;
        }

        // Direct Input Modal - Display card.php in modal
        async function showDirectInputModal() {
            // Load saved business card data to get slug and check if data exists
            let savedData = null;
            if (typeof loadBusinessCardData === 'function') {
                await loadBusinessCardData();
                // Check both window.businessCardData and the global businessCardData from edit.js
                savedData = window.businessCardData || (typeof businessCardData !== 'undefined' ? businessCardData : null);
                                } else {
                // Fallback: fetch data directly
                try {
                    const response = await fetch('../backend/api/business-card/get.php', {
                        method: 'GET',
                        credentials: 'include'
                    });
                    if (response.ok) {
                        const result = await response.json();
                        if (result.success) {
                            savedData = result.data;
                        }
                    }
                } catch (error) {
                    console.error('Error loading business card data:', error);
                }
            }

            // Check if we have any data to display
            const hasData = savedData && (
                savedData.company_name ||
                savedData.name ||
                (savedData.greetings && savedData.greetings.length > 0) ||
                savedData.company_logo ||
                savedData.profile_photo ||
                savedData.real_estate_license_prefecture ||
                savedData.company_address ||
                Object.keys(savedData).length > 5 // More than just id, user_id, etc.
            );

            if (!hasData || !savedData || !savedData.url_slug) {
                if (typeof showWarning === 'function') {
                    showWarning('表示するデータがありません。まず情報を入力してください。');
                } else {
                    alert('表示するデータがありません。まず情報を入力してください。');
                }
                return;
            }

            // Get the URL slug
            const urlSlug = savedData.url_slug;

            // Create modal overlay
            const modalOverlay = document.createElement('div');
            modalOverlay.className = 'modal-overlay direct-input-modal';
            modalOverlay.style.cssText = 'visibility: visible; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;';

            // Create modal content
            const modalContent = document.createElement('div');
            modalContent.className = 'direct-input-modal-content';
            modalContent.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: 100%; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column;';

            // Create iframe to load card.php with preview mode
            const iframe = document.createElement('iframe');
            iframe.src = `card.php?slug=${encodeURIComponent(urlSlug)}&preview=1`;
            iframe.style.cssText = 'width: 100%; height: 100%; border: none; flex: 1; min-height: 600px;';
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('scrolling', 'yes');

            // Close button
            const closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'direct-input-modal-close';
            closeButton.innerHTML = '×';
            closeButton.style.cssText = 'position: absolute; top: 1rem; right: 1rem; background: #fff; border: 2px solid #ddd; border-radius: 50%; width: 40px; height: 40px; cursor: pointer; font-size: 1.5rem; line-height: 1; display: flex; align-items: center; justify-content: center; color: #666; transition: all 0.3s; z-index: 10001;';
            closeButton.onmouseover = function() {
                this.style.background = '#f0f0f0';
                this.style.borderColor = '#999';
            };
            closeButton.onmouseout = function() {
                this.style.background = '#fff';
                this.style.borderColor = '#ddd';
            };

            modalContent.appendChild(iframe);
            modalContent.appendChild(closeButton);
            modalOverlay.appendChild(modalContent);
            document.body.appendChild(modalOverlay);

            // Show modal with animation
            setTimeout(() => {
                modalOverlay.style.opacity = '1';
            }, 10);

            // Close button handler
            closeButton.addEventListener('click', () => {
                    modalOverlay.style.opacity = '0';
                    setTimeout(() => {
                    if (document.body.contains(modalOverlay)) {
                        document.body.removeChild(modalOverlay);
                    }
                    }, 300);
                });

            // Close on overlay click
            modalOverlay.addEventListener('click', (e) => {
                if (e.target === modalOverlay) {
                    modalOverlay.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(modalOverlay)) {
                        document.body.removeChild(modalOverlay);
                        }
                    }, 300);
                }
            });

            // Close on Escape key
            const escapeHandler = (e) => {
                if (e.key === 'Escape') {
                    modalOverlay.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(modalOverlay)) {
                        document.body.removeChild(modalOverlay);
                        }
                    }, 300);
                    document.removeEventListener('keydown', escapeHandler);
                }
            };
            document.addEventListener('keydown', escapeHandler);
        }

        // Subscription cancellation handler for edit.php sidebar
        document.addEventListener('DOMContentLoaded', function() {
            const cancelBtn = document.getElementById('cancel-subscription-btn');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', async function() {
                    if (!confirm('利用を停止しますか？\n\n期間終了時に停止されます。即座に停止する場合は「OK」を押した後、確認画面で選択してください。')) {
                        return;
                    }

                    const cancelImmediately = confirm('即座にキャンセルしますか？\n\n「OK」: 即座にキャンセル\n「キャンセル」: 期間終了時にキャンセル');

                    cancelBtn.disabled = true;
                    cancelBtn.textContent = '処理中...';

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
                                showSuccess(result.message || '利用を停止しました', { autoClose: 5000 });
                            } else {
                                alert(result.message || '利用を停止しました');
                            }

                            const statusEl = document.getElementById('subscription-status');
                            if (statusEl) {
                                statusEl.textContent = 'キャンセル済み';
                                statusEl.style.color = '#dc3545';
                            }

                            cancelBtn.style.display = 'none';

                            setTimeout(() => {
                                window.location.reload();
                            }, 3000);
                        } else {
                            if (typeof showError === 'function') {
                                showError(result.message || '利用停止に失敗しました');
                            } else {
                                alert(result.message || '利用停止に失敗しました');
                            }
                            cancelBtn.disabled = false;
                            cancelBtn.textContent = '利用を停止する';
                        }
                    } catch (error) {
                        console.error('Error canceling subscription:', error);
                        if (typeof showError === 'function') {
                            showError('エラーが発生しました');
                        } else {
                            alert('エラーが発生しました');
                        }
                        cancelBtn.disabled = false;
                        cancelBtn.textContent = 'サブスクリプションをキャンセル';
                    }
                });
            }
        });
    </script>
    <script>
        // Step 1: Header & Greeting form submission
        document.addEventListener('DOMContentLoaded', function() {
            const headerGreetingForm = document.getElementById('header-greeting-form');
            if (headerGreetingForm) {
                headerGreetingForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(headerGreetingForm);
                    const data = {};
                    
                    // Get all fields
                    for (let [key, value] of formData.entries()) {
                        data[key] = value;
                    }
                    
                    // Handle greetings
                    const greetingItems = document.querySelectorAll('#greetings-list .greeting-item');
                    const greetings = [];
                    greetingItems.forEach((item, index) => {
                        // Skip cleared items (items that were deleted/cleared)
                        if (item.dataset.cleared === 'true') {
                            return;
                        }
                        
                        // Try to get values from different possible selectors
                        const titleInput = item.querySelector('input[name="greeting_title[]"]') || item.querySelector('.greeting-title');
                        const contentTextarea = item.querySelector('textarea[name="greeting_content[]"]') || item.querySelector('.greeting-content');
                        
                        const title = titleInput ? (titleInput.value || '').trim() : '';
                        const content = contentTextarea ? (contentTextarea.value || '').trim() : '';
                        
                        // Only add if both title and content have values
                        if (title && content) {
                            greetings.push({
                                title: title,
                                content: content,
                                display_order: index
                            });
                        }
                    });
                    data.greetings = greetings;
                    
                    // Helper function to convert data URL to blob
                    function dataURLtoBlob(dataurl, filename) {
                        const arr = dataurl.split(',');
                        const match = arr[0].match(/:(.*?);/);
                        const mime = match ? match[1] : 'image/png';
                        const bstr = atob(arr[1]);
                        let n = bstr.length;
                        const u8arr = new Uint8Array(n);
                        while (n--) {
                            u8arr[n] = bstr.charCodeAt(n);
                        }
                        return new File([u8arr], filename, { type: mime });
                    }

                    // Helper function to upload image
                    async function uploadImage(blobOrFile, fileType) {
                        const uploadData = new FormData();
                        uploadData.append('file', blobOrFile);
                        uploadData.append('file_type', fileType);

                        const uploadResponse = await fetch('../backend/api/business-card/upload.php', {
                            method: 'POST',
                            body: uploadData,
                            credentials: 'include'
                        });

                        const uploadResult = await uploadResponse.json();
                        if (uploadResult.success) {
                            // Extract relative path from absolute URL
                            let relativePath = uploadResult.data.file_path;
                            if (relativePath.startsWith('http://') || relativePath.startsWith('https://')) {
                                const urlParts = relativePath.split('/');
                                const backendIndex = urlParts.indexOf('backend');
                                if (backendIndex !== -1) {
                                    relativePath = urlParts.slice(backendIndex).join('/');
                                } else {
                                    const uploadsIndex = urlParts.indexOf('uploads');
                                    if (uploadsIndex !== -1) {
                                        relativePath = 'backend/' + urlParts.slice(uploadsIndex).join('/');
                                    }
                                }
                            }

                            // Update preview after successful upload
                            const uploadArea = document.querySelector(`[data-upload-id="${fileType === 'logo' ? 'company_logo' : 'profile_photo'}"]`);
                            if (uploadArea) {
                                const preview = uploadArea.querySelector('.upload-preview');
                                if (preview) {
                                    // Construct full URL for display
                                    let displayPath = relativePath;
                                    if (!displayPath.startsWith('http')) {
                                        if (typeof window !== 'undefined' && window.BASE_URL) {
                                            displayPath = window.BASE_URL + '/' + displayPath.replace(/^\/+/, '');
                                        } else {
                                            displayPath = '../' + displayPath;
                                        }
                                    }
                                    preview.innerHTML = `<img src="${displayPath}" alt="${fileType === 'logo' ? 'ロゴ' : 'プロフィール写真'}" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
                                }
                            }

                            return relativePath;
                        } else {
                            throw new Error(uploadResult.message || '画像のアップロードに失敗しました');
                        }
                    }

                    // Upload logo if cropped or new file selected
                    const logoUploadArea = document.querySelector('[data-upload-id="company_logo"]');
                    if (logoUploadArea) {
                        if (logoUploadArea.dataset.croppedBlobData) {
                            // Upload cropped image
                            try {
                                const blob = dataURLtoBlob(
                                    logoUploadArea.dataset.croppedBlobData,
                                    logoUploadArea.dataset.croppedFileName || 'logo.png'
                                );
                                data.company_logo = await uploadImage(blob, 'logo');
                                logoUploadArea.dataset.uploadedPath = data.company_logo;
                            } catch (error) {
                                console.error('Logo upload error:', error);
                                showError('ロゴのアップロードに失敗しました: ' + error.message);
                                return;
                            }
                        } else if (logoUploadArea.dataset.originalFileData) {
                            // Upload original file (no cropping)
                            try {
                                const fileInfo = JSON.parse(logoUploadArea.dataset.originalFile);
                                const blob = dataURLtoBlob(
                                    logoUploadArea.dataset.originalFileData,
                                    fileInfo.name
                                );
                                data.company_logo = await uploadImage(blob, 'logo');
                                logoUploadArea.dataset.uploadedPath = data.company_logo;
                            } catch (error) {
                                console.error('Logo upload error:', error);
                                showError('ロゴのアップロードに失敗しました: ' + error.message);
                                return;
                            }
                        } else if (logoUploadArea.dataset.uploadedPath) {
                            data.company_logo = logoUploadArea.dataset.uploadedPath;
                        } else if (window.businessCardData && window.businessCardData.company_logo) {
                            data.company_logo = window.businessCardData.company_logo;
                        }
                    }

                    // Upload profile photo if cropped or new file selected
                    const photoUploadArea = document.querySelector('[data-upload-id="profile_photo"]');
                    if (photoUploadArea) {
                        if (photoUploadArea.dataset.croppedBlobData) {
                            // Upload cropped image
                            try {
                                const blob = dataURLtoBlob(
                                    photoUploadArea.dataset.croppedBlobData,
                                    photoUploadArea.dataset.croppedFileName || 'photo.png'
                                );
                                data.profile_photo = await uploadImage(blob, 'photo');
                                photoUploadArea.dataset.uploadedPath = data.profile_photo;
                            } catch (error) {
                                console.error('Photo upload error:', error);
                                showError('プロフィール写真のアップロードに失敗しました: ' + error.message);
                                return;
                            }
                        } else if (photoUploadArea.dataset.originalFileData) {
                            // Upload original file (no cropping)
                            try {
                                const fileInfo = JSON.parse(photoUploadArea.dataset.originalFile);
                                const blob = dataURLtoBlob(
                                    photoUploadArea.dataset.originalFileData,
                                    fileInfo.name
                                );
                                data.profile_photo = await uploadImage(blob, 'photo');
                                photoUploadArea.dataset.uploadedPath = data.profile_photo;
                            } catch (error) {
                                console.error('Photo upload error:', error);
                                showError('プロフィール写真のアップロードに失敗しました: ' + error.message);
                                return;
                            }
                        } else if (photoUploadArea.dataset.uploadedPath) {
                            data.profile_photo = photoUploadArea.dataset.uploadedPath;
                        } else if (window.businessCardData && window.businessCardData.profile_photo) {
                            data.profile_photo = window.businessCardData.profile_photo;
                        }
                    }
                    
                    // Send to API
                    try {
                        const response = await fetch('../backend/api/business-card/update.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(data),
                            credentials: 'include'
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            // Update business card data and move to next step without reloading
                            if (typeof loadBusinessCardData === 'function') {
                                loadBusinessCardData().then(() => {
                                    // Move to next step (Step 2)
                                    setTimeout(() => {
                                        if (window.goToNextStep) {
                                            window.goToNextStep(1);
                                        }
                                    }, 300);
                                });
                            } else {
                                // Fallback: just move to next step
                                setTimeout(() => {
                                    if (window.goToNextStep) {
                                        window.goToNextStep(1);
                                    }
                                }, 300);
                            }
                        } else {
                            showError('保存に失敗しました: ' + result.message);
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        showError('エラーが発生しました');
                    }
                });
            }
            
            // Step 2: Company Profile form submission
            const companyProfileForm = document.getElementById('company-profile-form');
            if (companyProfileForm) {
                companyProfileForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    // Validate required fields for real estate license
                    const prefecture = document.getElementById('license_prefecture').value;
                    const renewal = document.getElementById('license_renewal').value;
                    const registration = document.getElementById('license_registration').value.trim();
                    
                    if (!prefecture || !renewal || !registration) {
                        showError('宅建業者番号（都道府県、更新番号、登録番号）は必須項目です。');
                        return;
                    }
                    
                    const formData = new FormData(companyProfileForm);
                    const data = {};
                    
                    for (let [key, value] of formData.entries()) {
                        data[key] = value;
                    }
                    
                    // Merge company_name from profile step and trim to prevent unwanted periods/whitespace
                    if (data.company_name_profile) {
                        data.company_name = String(data.company_name_profile).trim();
                        delete data.company_name_profile;
                    }
                    
                    try {
                        const response = await fetch('../backend/api/business-card/update.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(data),
                            credentials: 'include'
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            // Update business card data and move to next step without reloading
                            if (typeof loadBusinessCardData === 'function') {
                                loadBusinessCardData().then(() => {
                                    // Move to next step (Step 3)
                                    setTimeout(() => {
                                        if (window.goToNextStep) {
                                            window.goToNextStep(2);
                                        }
                                    }, 300);
                                });
                            } else {
                                // Fallback: just move to next step
                                setTimeout(() => {
                                    if (window.goToNextStep) {
                                        window.goToNextStep(2);
                                    }
                                }, 300);
                            }
                        } else {
                            showError('保存に失敗しました: ' + result.message);
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        showError('エラーが発生しました');
                    }
                });
            }
            
            // Step 3: Personal Information form submission
            const personalInfoForm = document.getElementById('personal-info-form');
            if (personalInfoForm) {
                personalInfoForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(personalInfoForm);
                    const data = {};
                    
                    for (let [key, value] of formData.entries()) {
                        data[key] = value;
                    }
                    
                    // Combine last_name and first_name
                    const lastName = data.last_name || '';
                    const firstName = data.first_name || '';
                    data.name = (lastName + ' ' + firstName).trim();
                    
                    // Combine romaji names
                    const lastNameRomaji = data.last_name_romaji || '';
                    const firstNameRomaji = data.first_name_romaji || '';
                    data.name_romaji = (lastNameRomaji + ' ' + firstNameRomaji).trim();
                    
                    // Handle qualifications
                    const qualifications = [];
                    if (formData.get('qualification_takken')) {
                        qualifications.push('宅地建物取引士');
                    }
                    if (formData.get('qualification_kenchikushi')) {
                        qualifications.push('建築士');
                    }
                    if (data.qualifications_other) {
                        qualifications.push(data.qualifications_other);
                    }
                    data.qualifications = qualifications.join('、');
                    delete data.qualification_takken;
                    delete data.qualification_kenchikushi;
                    delete data.qualifications_other;
                    
                    // Handle free input from paired items - collect all textarea values and images
                    const freeInputTexts = [];
                    const images = [];
                    
                    // Get all paired items
                    const pairedItems = document.querySelectorAll('#free-input-pairs-container .free-input-pair-item');

                    for (let i = 0; i < pairedItems.length; i++) {
                        const pairItem = pairedItems[i];

                        // Get text from this pair
                        const textarea = pairItem.querySelector('textarea[name="free_input_text[]"]');
                        if (textarea && textarea.value.trim() !== '') {
                            freeInputTexts.push(textarea.value.trim());
                        }

                        // Get image and link from this pair
                        const fileInput = pairItem.querySelector('input[type="file"]');
                        const linkInput = pairItem.querySelector('input[type="url"]');
                        const uploadArea = pairItem.querySelector('.upload-area');
                        const existingImage = uploadArea ? uploadArea.dataset.existingImage : '';
                        
                        let imagePath = existingImage || '';
                        
                        // If new file is selected, upload it
                        if (fileInput && fileInput.files && fileInput.files[0]) {
                            const uploadData = new FormData();
                            uploadData.append('file', fileInput.files[0]);
                            uploadData.append('file_type', 'free');
                            
                            try {
                                const uploadResponse = await fetch('../backend/api/business-card/upload.php', {
                                    method: 'POST',
                                    body: uploadData,
                                    credentials: 'include'
                                });
                                
                                const uploadResult = await uploadResponse.json();
                                if (uploadResult.success) {
                                    const fullPath = uploadResult.data.file_path;
                                    imagePath = fullPath.split('/php/')[1] || fullPath;
                                }
                            } catch (error) {
                                console.error('Upload error:', error);
                            }
                        }
                        
                        // Add image data (even if empty, to maintain pairing)
                        images.push({
                            image: imagePath,
                            link: linkInput ? linkInput.value.trim() : ''
                        });
                    }
                    
                    let freeInputData = {
                        texts: freeInputTexts.length > 0 ? freeInputTexts : [''],
                        images: images.length > 0 ? images : [{ image: '', link: '' }]
                    };
                    
                    data.free_input = JSON.stringify(freeInputData);
                    // Remove all free_input_text entries from data
                    Object.keys(data).forEach(key => {
                        if (key.startsWith('free_input_text')) {
                            delete data[key];
                        }
                    });
                    delete data.free_image_link;
                    delete data.last_name;
                    delete data.first_name;
                    delete data.last_name_romaji;
                    delete data.first_name_romaji;
                    
                    try {
                        const response = await fetch('../backend/api/business-card/update.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(data),
                            credentials: 'include'
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            // Update business card data and move to next step without reloading
                            if (typeof loadBusinessCardData === 'function') {
                                loadBusinessCardData().then(() => {
                                    // Move to next step (Step 4)
                                    setTimeout(() => {
                                        if (window.goToNextStep) {
                                            window.goToNextStep(3);
                                        }
                                    }, 300);
                                });
                            } else {
                                // Fallback: just move to next step
                                setTimeout(() => {
                                    if (window.goToNextStep) {
                                        window.goToNextStep(3);
                                    }
                                }, 300);
                            }
                        } else {
                            showError('保存に失敗しました: ' + result.message);
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        showError('エラーが発生しました');
                    }
                });
            }
            
            // Postal code lookup
            document.getElementById('lookup-address')?.addEventListener('click', async () => {
                const postalCode = document.getElementById('company_postal_code').value.replace(/-/g, '');
                
                if (!postalCode || postalCode.length !== 7) {
                    showWarning('7桁の郵便番号を入力してください');
                    return;
                }
                
                try {
                    const response = await fetch(`../backend/api/utils/postal-code-lookup.php?postal_code=${postalCode}`);
                    const result = await response.json();
                    
                    if (result.success) {
                        document.getElementById('company_address').value = result.data.address;
                    } else {
                        showError(result.message || '住所の取得に失敗しました');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showError('エラーが発生しました');
                }
            });
            
            // License lookup
            document.getElementById('lookup-license')?.addEventListener('click', async () => {
                const prefecture = document.getElementById('license_prefecture').value;
                const renewal = document.getElementById('license_renewal').value;
                const registration = document.getElementById('license_registration').value;
                
                if (!prefecture || !renewal || !registration) {
                    showWarning('都道府県、更新番号、登録番号をすべて入力してください');
                    return;
                }
                
                try {
                    const response = await fetch('../backend/api/utils/license-lookup.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            prefecture: prefecture,
                            renewal: renewal,
                            registration: registration
                        })
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        if (result.data.company_name) {
                            // Trim the company name to remove any accidental periods or whitespace
                            const companyName = String(result.data.company_name).trim();
                            document.querySelector('input[name="company_name_profile"]').value = companyName;
                        }
                        if (result.data.address) {
                            document.getElementById('company_address').value = result.data.address;
                        }
                    } else {
                        showError(result.message || '会社情報の取得に失敗しました');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showError('エラーが発生しました');
                }
            });
        });
        
        // 漢字からローマ字への自動変換機能（edit.php用）
        document.addEventListener('DOMContentLoaded', function() {
            const lastNameInput = document.getElementById('edit_last_name');
            const firstNameInput = document.getElementById('edit_first_name');
            const lastNameRomajiInput = document.getElementById('edit_last_name_romaji');
            const firstNameRomajiInput = document.getElementById('edit_first_name_romaji');
            
            // 簡易的な変換テーブル
            const nameConversionMap = {
                '山田': 'Yamada', '田中': 'Tanaka', '佐藤': 'Sato', '鈴木': 'Suzuki',
                '高橋': 'Takahashi', '伊藤': 'Ito', '渡辺': 'Watanabe', '中村': 'Nakamura',
                '小林': 'Kobayashi', '加藤': 'Kato', '吉田': 'Yoshida', '山本': 'Yamamoto',
                '松本': 'Matsumoto', '井上': 'Inoue', '木村': 'Kimura', '林': 'Hayashi',
                '斎藤': 'Saito', '清水': 'Shimizu', '山崎': 'Yamazaki', '中島': 'Nakajima',
                '前田': 'Maeda', '藤田': 'Fujita', '後藤': 'Goto', '近藤': 'Kondo',
                '太郎': 'Taro', '次郎': 'Jiro', '三郎': 'Saburo', '花子': 'Hanako',
                '一郎': 'Ichiro', '二郎': 'Jiro', '三郎': 'Saburo', '美咲': 'Misaki',
                'さくら': 'Sakura', 'あかり': 'Akari', 'ひなた': 'Hinata', 'みお': 'Mio'
            };
            
            function convertToRomaji(japanese) {
                if (!japanese) return '';
                if (nameConversionMap[japanese]) {
                    return nameConversionMap[japanese];
                }
                return '';
            }
            
            // ローマ字入力フィールドの最初の文字を大文字に変換する関数
            function capitalizeFirstLetterForEdit(input) {
                if (!input || !input.value) return;
                
                let value = input.value.trim();
                
                if (value.length > 0) {
                    // 最初の文字が小文字（a-z）の場合は大文字に変換
                    const firstChar = value.charAt(0);
                    if (firstChar >= 'a' && firstChar <= 'z') {
                        const cursorPosition = input.selectionStart || input.value.length;
                        value = firstChar.toUpperCase() + value.slice(1);
                        input.value = value;
                        // カーソル位置を復元
                        const newCursorPos = cursorPosition > 0 ? cursorPosition : value.length;
                        try {
                            input.setSelectionRange(newCursorPos, newCursorPos);
                        } catch (e) {
                            // Some browsers may not support setSelectionRange on all input types
                        }
                    }
                }
            }
            
            // ローマ字入力フィールドの大文字化機能を設定
            function setupRomajiAutoCapitalizeForEdit() {
                const romajiFields = [lastNameRomajiInput, firstNameRomajiInput];
                
                romajiFields.forEach(field => {
                    if (field) {
                        let isComposing = false; // Track IME composition state
                        
                        // Track composition start (IME input started)
                        field.addEventListener('compositionstart', function() {
                            isComposing = true;
                        });
                        
                        // Track composition end (IME input finished)
                        field.addEventListener('compositionend', function(e) {
                            isComposing = false;
                            // Apply capitalization after composition ends
                            setTimeout(() => capitalizeFirstLetterForEdit(e.target), 0);
                        });
                        
                        // Handle input event - skip during IME composition
                        field.addEventListener('input', function(e) {
                            // Skip if IME is composing or if event has isComposing flag
                            if (isComposing || e.isComposing) {
                                return;
                            }
                            // Use setTimeout to ensure the value is updated before capitalization
                            setTimeout(() => capitalizeFirstLetterForEdit(e.target), 0);
                        });
                        
                        // Handle keyup for more reliable capitalization on PC
                        field.addEventListener('keyup', function(e) {
                            // Skip during IME composition
                            if (isComposing || e.isComposing) {
                                return;
                            }
                            // Only capitalize on regular character keys (not special keys)
                            if (e.key.length === 1 && /[a-zA-Z]/.test(e.key)) {
                                setTimeout(() => capitalizeFirstLetterForEdit(e.target), 0);
                            }
                        });
                        
                        // Also apply on blur (when field loses focus)
                        field.addEventListener('blur', function(e) {
                            capitalizeFirstLetterForEdit(e.target);
                        });
                    }
                });
            }
            
            // ローマ字入力フィールドの大文字化機能を初期化
            setupRomajiAutoCapitalizeForEdit();
            
            if (lastNameInput && lastNameRomajiInput) {
                let lastNameTimeout;
                lastNameInput.addEventListener('input', function() {
                    clearTimeout(lastNameTimeout);
                    const value = this.value.trim();
                    if (!lastNameRomajiInput.value.trim() && value) {
                        lastNameTimeout = setTimeout(function() {
                            const romaji = convertToRomaji(value);
                            if (romaji) {
                                lastNameRomajiInput.value = romaji;
                                // 自動変換された値も大文字化する
                                capitalizeFirstLetterForEdit(lastNameRomajiInput);
                            }
                        }, 500);
                    }
                });
            }
            
            if (firstNameInput && firstNameRomajiInput) {
                let firstNameTimeout;
                firstNameInput.addEventListener('input', function() {
                    clearTimeout(firstNameTimeout);
                    const value = this.value.trim();
                    if (!firstNameRomajiInput.value.trim() && value) {
                        firstNameTimeout = setTimeout(function() {
                            const romaji = convertToRomaji(value);
                            if (romaji) {
                                firstNameRomajiInput.value = romaji;
                                // 自動変換された値も大文字化する
                                capitalizeFirstLetterForEdit(firstNameRomajiInput);
                            }
                        }, 500);
                    }
                });
            }
        });
        
        // ドラッグ&ドロップ機能の初期化（edit.php用）
        document.addEventListener('DOMContentLoaded', function() {
            // すべてのアップロードエリアにドラッグ&ドロップ機能を追�
            document.querySelectorAll('.upload-area').forEach(uploadArea => {
                const fileInput = uploadArea.querySelector('input[type="file"]');
                if (!fileInput) return;
                
                // ドラッグオーバー時の処理
                uploadArea.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadArea.classList.add('drag-over');
                });
                
                // ドラッグリーブ時の処理
                uploadArea.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadArea.classList.remove('drag-over');
                });
                
                // ドロップ時の処理
                uploadArea.addEventListener('drop', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadArea.classList.remove('drag-over');
                    
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const file = files[0];
                        // 画像ファイルかチェック
                        if (file.type.startsWith('image/')) {
                            fileInput.files = files;
                            // ファイル選択イベントをトリガー
                            const event = new Event('change', { bubbles: true });
                            fileInput.dispatchEvent(event);
                        } else {
                            if (typeof showWarning === 'function') {
                                showWarning('画像ファイルを選択してください');
                            } else {
                                alert('画像ファイルを選択してください');
                            }
                        }
                    }
                });
                
                // クリックでファイル選択も可能
                uploadArea.addEventListener('click', function(e) {
                    // ボタンやプレビュー画像をクリックした場合は除外
                    if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'IMG') {
                        fileInput.click();
                    }
                });
            });
        });

    </script>
</body>
</html>

