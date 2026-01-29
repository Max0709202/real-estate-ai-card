<?php
/**
 * Registration Page (Multi-step Form)
 * Note: Step 1 (Account Registration) is now in new_register.php
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

// 認証チェック - 未登録の場合はモーダルを表示
$isLoggedIn = !empty($_SESSION['user_id']);

$userType = $_GET['type'] ?? 'new'; // new, existing, free
$invitationToken = $_GET['token'] ?? '';
$isTokenBased = !empty($invitationToken);
$tokenValid = false;
$tokenData = null;

// 停止されたアカウントの検出と決済状況の確認
$isCanceledAccount = false;
$isActive = false; // 利用中かどうか
$needsPayment = true; // 支払いが必要かどうか（デフォルトは必要）
if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../backend/config/database.php';
        $database = new Database();
        $db = $database->getConnection();

        $userId = $_SESSION['user_id'];

        // サブスクリプションとビジネスカードの情報を取得
        $stmt = $db->prepare("
            SELECT s.status as subscription_status, s.next_billing_date, s.cancelled_at,
                   bc.card_status, bc.payment_status
            FROM users u
            LEFT JOIN subscriptions s ON u.id = s.user_id
            LEFT JOIN business_cards bc ON u.id = bc.user_id
            WHERE u.id = ?
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $accountStatus = $stmt->fetch();

        if ($accountStatus) {
            // サブスクリプションが停止されている、またはビジネスカードが停止されている場合
            if (($accountStatus['subscription_status'] === 'canceled') ||
                ($accountStatus['card_status'] === 'canceled')) {
                $isCanceledAccount = true;
                // 停止されたアカウントの場合、新規ユーザーとして扱う（初期費用含む会費を請求）
                $userType = 'new';
                $isActive = false;
                $needsPayment = true;
            } else {
                // 決済状況の判定
                $subscriptionStatus = $accountStatus['subscription_status'];
                $paymentStatus = $accountStatus['payment_status'] ?? 'UNUSED';

                // Check if subscription is active and payment is completed
                if ($subscriptionStatus && in_array($subscriptionStatus, ['active', 'trialing'])) {
                    if (in_array($paymentStatus, ['CR', 'BANK_PAID'])) {
                        // Check if next_billing_date is in the future
                        if ($accountStatus['next_billing_date']) {
                            $nextBillingDate = new DateTime($accountStatus['next_billing_date']);
                            $now = new DateTime();
                            if ($nextBillingDate > $now) {
                                $isActive = true;
                                $needsPayment = false;
                            } else {
                                // Subscription period has expired
                                $isActive = false;
                                $needsPayment = true;
                            }
                        } else {
                            // No next billing date but status is active - consider as active
                            $isActive = true;
                            $needsPayment = false;
                        }
                    } else {
                        // Active subscription but payment not completed
                        $isActive = false;
                        $needsPayment = true;
                    }
                } elseif ($subscriptionStatus) {
                    // Subscription exists but status is not active/trialing
                    if (in_array($subscriptionStatus, ['canceled', 'incomplete_expired', 'past_due', 'unpaid', 'incomplete'])) {
                        $isActive = false;
                        $needsPayment = true;
                    }
                    // Check if period has expired (next_billing_date is in the past)
                    if ($accountStatus['next_billing_date']) {
                        $nextBillingDate = new DateTime($accountStatus['next_billing_date']);
                        $now = new DateTime();
                        if ($nextBillingDate <= $now && in_array($paymentStatus, ['CR', 'BANK_PAID'])) {
                            $isActive = false;
                            $needsPayment = true;
                        }
                    }
                } else {
                    // No subscription info - check payment_status directly
                    if (in_array($paymentStatus, ['CR', 'BANK_PAID'])) {
                        // Payment completed but no subscription - show as active for now
                        $isActive = true;
                        $needsPayment = false;
                    } else {
                        $isActive = false;
                        $needsPayment = true;
                    }
                }

                // If payment_status is UNUSED or BANK_PENDING, always needs payment
                if (in_array($paymentStatus, ['UNUSED', 'BANK_PENDING'])) {
                    $isActive = false;
                    $needsPayment = true;
                }
            }
        } else {
            // No account status found - needs payment
            $isActive = false;
            $needsPayment = true;
        }
    } catch (Exception $e) {
        error_log("Error checking account status: " . $e->getMessage());
        $isActive = false;
        $needsPayment = true;
    }
} else {
    // Not logged in - needs payment (new user)
    $isActive = false;
    $needsPayment = true;
}

// Validate token if provided with enhanced security
if ($isTokenBased) {
    try {
        // Use enhanced validation that checks if token belongs to logged-in user
        $validationUrl = BASE_URL . '/backend/api/auth/validate-user-invitation-token.php?token=' . urlencode($invitationToken);
        if (!empty($userType) && in_array($userType, ['existing', 'free'])) {
            $validationUrl .= '&type=' . urlencode($userType);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $validationUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result && $result['success']) {
                $tokenValid = true;
                $tokenData = $result['data'];
                // Override userType from token/response if it's existing or free
                $responseUserType = $tokenData['user_type'] ?? $tokenData['role_type'] ?? null;
                if (in_array($responseUserType, ['existing', 'free'])) {
                    $userType = $responseUserType;
                }
            }
        } elseif ($httpCode === 403) {
            // Token doesn't belong to user - security violation
            error_log("Security: Token validation failed - token doesn't belong to user. User ID: " . ($_SESSION['user_id'] ?? 'not logged in'));
            $tokenValid = false;
        }
    } catch (Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
        $tokenValid = false;
    }
}

// Helper function to build URLs with token and type parameters
function buildUrlWithToken($baseUrl, $userType, $invitationToken) {
    if (!empty($invitationToken) && in_array($userType, ['existing', 'free'])) {
        return $baseUrl . '?type=' . urlencode($userType) . '&token=' . urlencode($invitationToken);
    }
    return $baseUrl;
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

// Japanese prefectures
$prefectures = [
    '国土交通大臣', '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
    '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
    '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
    '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
    '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
    '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
    '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'
];
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
    <title>アカウント作成 - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/register.css">
    <link rel="stylesheet" href="assets/css/card.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <!-- Cropper.js CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css">
</head>
<body>
    <?php
    $showNavLinks = false; // Hide nav links on edit page
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="register-container">
        <header class="register-header">
            <h1>デジタル名刺作成・編集</h1>
        </header>
        <div class="register-content" <?php if (!$isLoggedIn): ?>style="display: none;"<?php endif; ?>>
            <div class="register-steps">
                <div class="step-indicator">
                    <div class="step active" data-step="1">
                        <span class="step-number">1/6</span>
                        <span class="step-label">ヘッダー・挨拶</span>
                    </div>
                    <div class="step" data-step="2">
                        <span class="step-number">2/6</span>
                        <span class="step-label">会社プロフィール</span>
                    </div>
                    <div class="step" data-step="3">
                        <span class="step-number">3/6</span>
                        <span class="step-label">個人情報</span>
                    </div>
                    <div class="step" data-step="4">
                        <span class="step-number">4/6</span>
                        <span class="step-label">テックツール</span>
                    </div>
                    <div class="step" data-step="5">
                        <span class="step-number">5/6</span>
                        <span class="step-label">コミュニケーション</span>
                    </div>
                    <div class="step" data-step="6">
                        <span class="step-number">6/6</span>
                        <span class="step-label">決済</span>
                    </div>
                </div>
            </div>

            <!-- Preview Container -->
            <div id="preview-container" class="preview-container" style="display: none;">
                <div class="preview-header">
                    <button type="button" id="close-preview-btn" class="btn-close-preview">編集に戻る</button>
                </div>
                <div id="preview-content" class="preview-content"></div>
            </div>

            <div class="preview-btn-container preview-btn-desktop">
                <button type="button" id="preview-btn-desktop" class="btn-preview">プレビュー</button>
            </div>

            <!-- Step 1: Header & Greeting -->
            <div id="step-1" class="register-step active">
                <h1>ヘッダー・挨拶部</h1>
                <p class="step-description">会社情報とご挨拶文を入力してください</p>

                <form id="header-greeting-form" class="register-form">
                    <div class="form-group">
                        <label>会社名 <span class="required">*</span></label>
                        <input type="text" name="company_name" class="form-control" required>
                    </div>

                    <div class="form-section">
                        <h3>ロゴマーク</h3>
                        <div class="upload-area" id="logo-upload" data-upload-id="company_logo">
                            <input type="file" id="company_logo" name="company_logo" accept="image/*" style="display: none;">
                            <div class="upload-preview"></div>
                            <button type="button" class="btn-outline" onclick="document.getElementById('company_logo').click()">
                                ロゴをアップロード
                            </button>
                            <small>ファイルを選択するか、ここにドラッグ&ドロップしてください（自動でリサイズされます）<br>対応形式：JPEG、PNG、GIF、WebP</small>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>顔写真</h3>
                        <div class="upload-area" id="photo-upload-header" data-upload-id="profile_photo_header">
                            <input type="file" id="profile_photo_header" name="profile_photo" accept="image/*" style="display: none;">
                            <div class="upload-preview"></div>
                            <button type="button" class="btn-outline" onclick="document.getElementById('profile_photo_header').click()">
                                写真をアップロード
                            </button>
                            <small>ファイルを選択するか、ここにドラッグ&ドロップしてください（自動でリサイズされます）<br>対応形式：JPEG、PNG、GIF、WebP</small>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>ご挨拶</h3>
                        <p class="section-note">
                            ・５つの挨拶文例をそのまま使用できます。<br>
                            ・挨拶文の順序を上下のボタン・ドラッグで変更できます。
                        </p>
                        <div style="text-align: center; margin: 1rem 0; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 1rem;">
                            <button type="button" class="btn-add" onclick="addGreeting()">挨拶文を追加</button>
                            <button type="button" class="btn-restore-defaults" onclick="restoreDefaultGreetingsForRegister()">５つの挨拶文例を再表示する</button>
                        </div>
                        <div id="greetings-container">
                            <!-- Greetings will be populated by JavaScript from database -->
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </div>
                </form>
            </div>

            <!-- Step 2: Company Profile -->
            <div id="step-2" class="register-step">
                <h1>会社プロフィール部</h1>
                <p class="step-description">会社情報を入力してください</p>

                <form id="company-profile-form" class="register-form">
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
                        <?php if ($isTokenBased && in_array($userType, ['existing', 'free'])): ?>
                            <!-- Existing/Free users must enter registration number manually -->
                            <input type="text" name="real_estate_license_registration_number" id="license_registration" class="form-control" placeholder="登録番号を入力してください（例：12345）" required>
                            <small style="color: #718096; font-size: 12px; display: block; margin-top: 5px;">
                                登録番号を手動で入力してください
                            </small>
                        <?php else: ?>
                            <input type="text" name="real_estate_license_registration_number" id="license_registration" class="form-control" placeholder="例：12345" required>
                        <?php endif; ?>
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
                        <label>会社HP　URL（https://  から入力してください。）</label>
                        <input type="url" name="company_website" class="form-control" placeholder="https://example.com">
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="goToStep(1)">戻る</button>
                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </div>
                </form>
            </div>

            <!-- Step 3: Personal Information -->
            <div id="step-3" class="register-step">
                <h1>個人情報</h1>
                <p class="step-description">あなたの個人情報を入力してください</p>

                <form id="personal-info-form" class="register-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label>部署</label>
                            <input type="text" name="branch_department" class="form-control" placeholder="例：営業部">
                        </div>
                        <div class="form-group">
                            <label>役職</label>
                            <input type="text" name="position" class="form-control" placeholder="例：営業課長">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>姓 <span class="required">*</span></label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required placeholder="例：山田">
                        </div>
                        <div class="form-group">
                            <label>名 <span class="required">*</span></label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required placeholder="例：太郎">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>姓（ローマ字）</label>
                            <small style="display: block; color: #666; margin-bottom: 0.5rem; font-size: 0.875rem;">最初の文字が小文字の場合は、自動的に大文字に変換されます。</small>
                            <input type="text" name="last_name_romaji" id="last_name_romaji" class="form-control" placeholder="例：Yamada" inputmode="latin" autocomplete="family-name" autocapitalize="words" spellcheck="false">
                        </div>
                        <div class="form-group">
                            <label>名（ローマ字）</label>
                            <small style="display: block; color: #666; margin-bottom: 0.5rem; font-size: 0.875rem;">最初の文字が小文字の場合は、自動的に大文字に変換されます。</small>
                            <input type="text" name="first_name_romaji" id="first_name_romaji" class="form-control" placeholder="例：Taro" inputmode="latin" autocomplete="given-name" autocapitalize="words" spellcheck="false">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>電話番号 <span class="required">*</span></label>
                        <?php if ($isTokenBased && in_array($userType, ['existing', 'free'])): ?>
                            <!-- Existing/Free users must enter phone number manually -->
                            <input type="tel" name="mobile_phone" id="mobile_phone" class="form-control" required placeholder="例：090-1234-5678" autocomplete="tel">
                            <small style="color: #718096; font-size: 12px; display: block; margin-top: 5px;">
                                電話番号を手動で入力してください
                            </small>
                        <?php else: ?>
                            <!-- New users get auto-filled number -->
                            <input type="tel" name="mobile_phone" id="mobile_phone" class="form-control" required value="090-1234-5678">
                        <?php endif; ?>
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
                                        <input type="checkbox" name="qualification_kenchikushi_1" value="1">
                                        <span>一級建築士</span>
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="qualification_kenchikushi_2" value="1">
                                        <span>二級建築士</span>
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="qualification_kenchikushi_3" value="1">
                                        <span>木造建築士</span>
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
                            <label>テキスト・画像セット <button type="button" class="btn-add-small" onclick="addFreeInputPairForRegister()">追加</button></label>
                            <div id="free-input-pairs-container">
                                <div class="free-input-pair-item">
                                    <div class="free-input-pair-header">
                                        <span class="free-input-pair-number">1</span>
                                        <div class="free-input-pair-actions">
                                            <button type="button" class="btn-move-up" onclick="moveFreeInputPairForRegister(0, 'up')" disabled>↑</button>
                                            <button type="button" class="btn-move-down" onclick="moveFreeInputPairForRegister(0, 'down')" disabled>↓</button>
                                        </div>
                                        <button type="button" class="btn-delete-small" onclick="removeFreeInputPairForRegister(this)" style="display: none;">削除</button>
                                    </div>
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
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="goToStep(2)">戻る</button>
                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </div>
                </form>
            </div>

            <!-- Step 4: Tech Tools -->
            <div id="step-4" class="register-step">
                <h1>テックツール選択</h1>
                <p class="step-description">表示させるテックツールを選択してください（最低2つ以上）</p>

                <form id="tech-tools-form" class="register-form">
                    <?php
                    // Tool descriptions and banner images (same as card.php)
                    $toolInfo = [
                        'slp' => [
                            'description' => '<div id="cc-m-13442757931" class="j-module n j-text "><p>
    <span style="font-size: 14px;"><strong><span style="color: #000000;">AI評価付き『SelFin（セルフィン）』は消費者自ら</span></strong><span style="color: #ff0000;"><span style="font-weight: 700 !important;">「物件の資産性」を自動判定できる</span></span></span><span style="color: #000000;"><strong><span style="font-size: 14px;">ツールです。「価格の妥当性」「街力」「流動性」「耐震性」「管理費・修繕積立金の妥当性」を自動判定します。また物件提案ロボで配信される物件にはSelFin評価が付随します。&nbsp;</span></strong></span>
</p></div>',
                            'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/slp.jpg'
                        ],
                        'rlp' => [
                            'description' => '<div id="cc-m-13442765431" class="j-module n j-text "><p>
    <span style="font-size: 14px;"><span style="color: #000000;"><span style="color: #000000;"><strong>AI評価付き『物件提案ロボ』は</strong><strong>貴社顧客の希望条件に合致する不動産情<span style="color: #000000;">報を「</span></strong></span></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">御社名</span></span><strong><span style="color: #000000;">」で自動配信します。WEB上に登録になった</span></strong><span style="color: #000000; font-weight: 700 !important;"><span style="color: #ff0000;">新着不動産情報を２４時間以内に、毎日自動配信</span></span><span style="color: #000000;"><strong>するサービスです。</strong></span></span>
</p></div>',
                            'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/rlp.jpg'
                        ],
                        'llp' => [
                            'description' => '<div id="cc-m-13442765531" class="j-module n j-text "><p>
    <span style="font-size: 14px;"><span style="color: #000000;"><strong>『土地情報ロボ』は貴社顧客の希望条件に合致する不動産情報を「</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">御社名</span></span><span style="color: #000000;"><strong>」で自動配信します。WEB上に登録になった</strong></span><span style="color: #000000; font-weight: 700 !important;"><span style="color: #ff0000;">新着不動産情報を２４時間以内に、毎日自動配信</span></span><span style="color: #000000;"><strong>するサービスです。</strong></span></span>
</p></div>',
                            'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/llp.jpg'
                        ],
                        'mdb' => [
                            'description' => '<div id="cc-m-13442765731" class="j-module n j-text "><p>
    <span style="font-size: 14px;"><span style="color: #ff0000;"><strong>全国マンションデータベース（MDB)を売却案件の獲得の為に見せ方を変えたツール</strong></span><span style="color: #000000;"><strong>となります。大手仲介事業者のAI〇〇査定サイトのようなページとは異なり、</strong></span><span style="color: #ff0000;"><strong>誰でもマンションの価格だけは登録せずにご覧いただけるようなシステム</strong></span><strong><span style="color: #000000;">となっています。</span></strong></span>
</p></div>',
                            'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/mdb.jpg'
                        ],
                        'ai' => [
                            'description' => '<div id="cc-m-13442765731" class="j-module n j-text "><p>
    <span style="font-size: 14px;"><span style="color: #ff0000;"><strong>全国マンションデータベース（MDB)を売却案件の獲得の為に見せ方を変えたツール</strong></span><span style="color: #000000;"><strong>となります。大手仲介事業者のAI〇〇査定サイトのようなページとは異なり、</strong></span><span style="color: #ff0000;"><strong>誰でもマンションの価格だけは登録せずにご覧いただけるようなシステム</strong></span><strong><span style="color: #000000;">となっています。</span></strong></span>
</p></div>',
                            'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/ai.jpg'
                        ],
                        'olp' => [
                            'description' => '<div id="cc-m-13442765831" class="j-module n j-text "><p>
    <span style="font-size: 14px;"><span color="#000000" style="color: #000000;"><span style="color: #000000;"><strong>オーナーコネクトはマンション所有者様向けのサービスで、</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">誰でも簡単に自宅の資産状況を確認できます。</span></span></span><span style="color: #000000;"><strong><span color="#000000">登録されたマンションで新たに売り出し情報が出たらメールでお知らせいたします。</span></strong></span><span color="#000000" style="color: #000000;"><span style="color: #000000;"><strong>また、</strong></span><span style="font-weight: 700 !important;"><span style="color: #ff0000;">毎週自宅の資産状況をまとめたレポートメールも送信</span></span><strong><span style="color: #000000;">いたします。</span></strong></span></span>
</p></div>',
                            'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/olp.jpg'
                        ],
                        'alp' => [
                            'description' => '<div id="cc-m-13412853831" class="j-module n j-text" style="clear: both;">
    <p>
        <span style="font-size: 14px;"><strong><span style="color: #ff0000;">「SelFin Pro(セルフィンプロ)」</span><span style="color: #000000;">（AI・ロボット・ビッグデータ）はお客様との継続的な</span><span style="color: #ff0000;">コミュニケーションを自動化するWEBシステム</span><span style="color: #000000;">です。</span><span style="color: #ff0000;">全てのサービスが御社名で提供</span><span style="color: #000000;">されます。バックオフィスの自動化という後方支援を貴社の顧客・売上増加にご活用ください。</span></strong></span>
    </p>
</div>',
                            'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/alp.jpg'
                        ]
                    ];

                    $techToolsList = [
                        ['type' => 'mdb', 'id' => 'tool-mdb', 'name' => '全国マンションデータベース'],
                        ['type' => 'rlp', 'id' => 'tool-rlp', 'name' => '物件提案ロボ'],
                        ['type' => 'llp', 'id' => 'tool-llp', 'name' => '土地情報ロボ'],
                        ['type' => 'ai', 'id' => 'tool-ai', 'name' => 'AIマンション査定'],
                        ['type' => 'slp', 'id' => 'tool-slp', 'name' => 'セルフィン'],
                        ['type' => 'olp', 'id' => 'tool-olp', 'name' => 'オーナーコネクト'],
                        ['type' => 'alp', 'id' => 'tool-alp', 'name' => '統合LP']
                    ];
                    ?>
                    <div class="tech-tools-grid" id="tech-tools-grid">
                        <?php foreach ($techToolsList as $loopIndex => $tool):
                            $info = $toolInfo[$tool['type']] ?? [
                                'description' => '',
                                'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/default.jpg'
                            ];
                        ?>
                            <div class="tech-tool-banner-card register-tech-card" data-tool-type="<?php echo $tool['type']; ?>">
                                <div class="tech-tool-actions">
                                    <button type="button" class="btn-move-up" onclick="moveTechToolForRegister(<?php echo $loopIndex; ?>, 'up')" <?php echo $loopIndex === 0 ? 'disabled' : ''; ?>>↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveTechToolForRegister(<?php echo $loopIndex; ?>, 'down')" <?php echo $loopIndex === count($techToolsList) - 1 ? 'disabled' : ''; ?>>↓</button>
                                </div>
                                <input type="checkbox" id="<?php echo $tool['id']; ?>" name="tech_tools[]" value="<?php echo $tool['type']; ?>" class="tech-tool-checkbox">
                                <label for="<?php echo $tool['id']; ?>" class="tech-tool-label">
                                    <!-- Banner Header with Background Image -->
                                    <div class="tool-banner-header" style="background-image: url('<?php echo htmlspecialchars($info['banner_image']); ?>'); background-size: contain; background-position: center; background-repeat: no-repeat;">
                                    </div>

                                    <!-- Description -->
                                    <div class="tool-banner-content">
                                        <div class="tool-description"><?php echo $info['description']; ?></div>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="goToStep(3)">戻る</button>
                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </div>
                </form>
            </div>

            <!-- Step 5: Communication Functions -->
            <div id="step-5" class="register-step">
                <h1>コミュニケーション機能部</h1>
                <p class="step-description">メッセージアプリやSNSの連携を設定してください</p>

                <form id="communication-form" class="register-form">
                    <div class="form-section">
                        <h3>メッセージアプリ部</h3>
                        <p class="section-note">一番簡単につながる方法を教えてください。ここが重要になります。</p>

                        <div class="communication-grid" id="message-apps-grid" style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(0, 'up', 'message')" disabled>↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(0, 'down', 'message')">↓</button>
                                </div>
                                <div class="comm-details-wrapper">
                                    <label class="communication-checkbox">
                                        <input type="checkbox" name="comm_line" value="1">
                                        <div class="comm-icon">
                                            <img src="assets/images/icons/line.png" alt="LINE">
                                        </div>
                                        <span>LINE</span>
                                    </label>
                                    <div class="comm-details">
                                        <input type="text" name="comm_line_id" class="form-control" placeholder="QRコードのリンクを入力">
                                    </div>
                                    <div class="comm-help-button-wrapper">
                                        <button type="button" class="comm-help-button" data-help-type="line">設定方法</button>
                                    </div>
                                </div>
                            </div>
                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(1, 'up', 'message')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(1, 'down', 'message')">↓</button>
                                </div>
                                <div class="comm-details-wrapper">
                                    <label class="communication-checkbox">
                                        <input type="checkbox" name="comm_messenger" value="1">
                                        <div class="comm-icon">
                                            <img src="assets/images/icons/messenger.png" alt="Messenger">
                                        </div>
                                        <span>Messenger</span>
                                    </label>
                                    <div class="comm-details">
                                        <input type="text" name="comm_messenger_id" class="form-control" placeholder="プロフィールURLを入力">
                                    </div>
                                    <div class="comm-help-button-wrapper">
                                        <button type="button" class="comm-help-button" data-help-type="facebook">設定方法</button>
                                    </div>
                                </div>
                            </div>
                            <div class="communication-item" data-comm-type="message">
                                <div class="comm-actions">
                                    <button type="button" class="btn-move-up" onclick="moveCommunicationItem(2, 'up', 'message')">↑</button>
                                    <button type="button" class="btn-move-down" onclick="moveCommunicationItem(2, 'down', 'message')" disabled>↓</button>
                                </div>
                                <div class="comm-details-wrapper">
                                    <label class="communication-checkbox">
                                        <input type="checkbox" name="comm_chatwork" value="1">
                                        <div class="comm-icon">
                                            <img src="assets/images/icons/chatwork.png" alt="Chatwork">
                                        </div>
                                        <span>Chatwork</span>
                                    </label>
                                    <div class="comm-details">
                                        <input type="text" name="comm_chatwork_id" class="form-control" placeholder="チャットワークIDを入力">
                                    </div>
                                    <div class="comm-help-button-wrapper">
                                        <button type="button" class="comm-help-button" data-help-type="chatwork">設定方法</button>
                                    </div>
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

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="goToStep(4)">戻る</button>
                        <button type="submit" class="btn-primary">保存して次へ</button>
                    </div>
                </form>
            </div>

            <!-- Step 6: Preview & Payment -->
            <div id="step-6" class="register-step">
                <h1>決済</h1>
                <!-- <p class="step-description">入力内容を確認してください</p> -->

                <!-- <div id="preview-area" class="preview-area"> -->
                    <!-- Preview will be loaded here -->
                <!-- </div> -->

                <div class="payment-section">
                    <h3>お支払方法</h3>
                    <div class="payment-options">
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="credit_card" checked>
                            <span>クレジットカード決済</span>
                        </label>
                        <?php if ($userType !== 'free'): ?>
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="bank_transfer">
                            <span>お振込み</span>
                        </label>
                        <?php endif; ?>
                    </div>

                    <div class="payment-amount">
                        <?php if ($userType === 'new' || $isCanceledAccount): ?>
                        <p>初期費用: ¥30,000（税別）</p>
                        <p>月額費用: ¥500（税別）</p>
                        <?php if ($isCanceledAccount): ?>
                        <p style="color: #666; font-size: 0.9rem; margin-top: 0.5rem;">※停止されたアカウントの復活には、新規登録と同じ初期費用と月額費用がかかります。</p>
                        <?php endif; ?>
                        <?php elseif ($userType === 'existing'): ?>
                        <p>初期費用: ¥20,000（税別）</p>
                        <?php else: ?>
                        <p>無料</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="goToStep(5)">戻る</button>
                    <?php if ($isActive): ?>
                        <button type="button" id="submit-payment" class="btn-primary" style="background: #007bff; cursor: default;" disabled>利用中</button>
                    <?php else: ?>
                        <button type="button" id="submit-payment" class="btn-primary">この内容で進める</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="preview-btn-container preview-btn-mobile">
                <button type="button" id="preview-btn-mobile" class="btn-preview">プレビュー</button>
            </div>
        </div>
    </div>

    <!-- Registration Required Modal -->
    <?php if (!$isLoggedIn): ?>
    <div id="registration-modal" class="registration-modal" style="display: block;">
        <div class="modal-content">
            <div class="modal-body">
                <p>まずはご登録ください。</p>
            </div>
            <div class="modal-footer">
                <button type="button" id="modal-confirm-btn" class="btn-primary">確認する</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Image Cropper Modal -->
    <div id="image-cropper-modal" class="modal-overlay" style="display: none; z-index: 10000; opacity: 1; visibility: visible;">
        <div class="modal-content" style="max-width: 90%; max-height: 90vh; overflow: auto;">
            <div style="padding: 20px;">
                <h3 style="margin-bottom: 20px;">画像をトリミング</h3>
                <p style="margin-bottom: 15px; color: #666; font-size: 14px;">
                    画像のサイズを調整し、必要な部分を選択してください。指でドラッグしてトリミングエリアを移動・拡大縮小できます。
                </p>
                <div style="width: 100%; max-width: 800px; margin: 0 auto; background: #f5f5f5; border-radius: 4px; padding: 10px; display: flex; justify-content: center; align-items: center;">
                    <img id="cropper-image" style="max-width: 100%; max-height: 60vh; display: block; object-fit: contain; width: auto; height: auto;">
                </div>
                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                    <button type="button" id="crop-cancel-btn" class="btn-secondary" style="padding: 10px 20px; width: auto; cursor: pointer;">キャンセル</button>
                    <button type="button" id="crop-confirm-btn" class="btn-primary" style="padding: 10px 20px; width: auto;">トリミングを適用</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/modal.js"></script>
    <!-- Cropper.js -->
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
    <script>
        // Make BASE_URL available to JavaScript
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>
    <script src="assets/js/auto-save.js"></script>
    <script src="assets/js/register.js"></script>
    <script src="assets/js/mobile-menu.js"></script>
    <script>
        // Token and user type configuration
        window.invitationToken = <?php echo json_encode($invitationToken); ?>;
        window.userType = <?php echo json_encode($userType); ?>;
        window.isTokenBased = <?php echo json_encode($isTokenBased); ?>;
        window.tokenValid = <?php echo json_encode($tokenValid); ?>;
        window.tokenData = <?php echo json_encode($tokenData); ?>;
        window.isCanceledAccount = <?php echo json_encode($isCanceledAccount); ?>;

        // Helper function to build URLs with token and type parameters
        function buildUrlWithToken(baseUrl) {
            if (window.invitationToken && (window.userType === 'existing' || window.userType === 'free')) {
                return baseUrl + '?type=' + encodeURIComponent(window.userType) + '&token=' + encodeURIComponent(window.invitationToken);
            }
            return baseUrl;
        }

        // Validate token on page load if present
        document.addEventListener('DOMContentLoaded', function() {
            if (window.isTokenBased && !window.tokenValid) {
                showError('無効な招待リンクです。管理者にお問い合わせください。');
                // Optionally redirect after 3 seconds
                setTimeout(function() {
                    window.location.href = buildUrlWithToken('login.php');
                }, 3000);
            }

            // For existing/free users, ensure phone number field is empty and required
            if (window.isTokenBased && (window.userType === 'existing' || window.userType === 'free')) {
                const phoneInput = document.getElementById('mobile_phone');
                if (phoneInput && phoneInput.value === '090-1234-5678') {
                    phoneInput.value = '';
                    phoneInput.placeholder = '電話番号を入力してください（例：090-1234-5678）';
                }

                // Ensure license registration number is also manual entry
                const licenseRegInput = document.getElementById('license_registration');
                if (licenseRegInput) {
                    licenseRegInput.placeholder = '登録番号を入力してください（例：12345）';
                }
            }
        });
    </script>
    <script>
        // Modal functionality
        document.getElementById('modal-confirm-btn')?.addEventListener('click', function() {
            window.location.href = buildUrlWithToken('login.php');
        });

        // ドラッグ&ドロップ機能の初期化
        document.addEventListener('DOMContentLoaded', function() {
            // すべてのアップロードエリアにドラッグ&ドロップ機能を追�
            document.querySelectorAll('.upload-area').forEach(uploadArea => {
                const fileInput = uploadArea.querySelector('input[type="file"]');
                if (!fileInput) return;

                // ドラッグエンター時の処理（ブラウザのデフォルト動作を防止）
                uploadArea.addEventListener('dragenter', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                });

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
                        if (file && file.type && file.type.startsWith('image/')) {
                            // より確実な方法でファイルを設定
                            try {
                                // DataTransferオブジェクトを使用してファイルを設定
                                const dataTransfer = new DataTransfer();
                                dataTransfer.items.add(file);
                                fileInput.files = dataTransfer.files;

                                // ファイル選択イベントをトリガー
                                const event = new Event('change', { bubbles: true, cancelable: true });
                                fileInput.dispatchEvent(event);
                            } catch (error) {
                                // フォールバック: 直接代入を試行
                                console.warn('DataTransfer not supported, using fallback:', error);
                                try {
                                    fileInput.files = files;
                                    const event = new Event('change', { bubbles: true, cancelable: true });
                                    fileInput.dispatchEvent(event);
                                } catch (fallbackError) {
                                    console.error('File assignment failed:', fallbackError);
                                    if (typeof showError === 'function') {
                                        showError('ファイルの読み込みに失敗しました。もう一度お試しください。');
                                    } else {
                                        alert('ファイルの読み込みに失敗しました。もう一度お試しください。');
                                    }
                                }
                            }
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

        // 漢字からローマ字への自動変換機能
        // 簡易版：よく使われる名前の変換テーブルを使用
        document.addEventListener('DOMContentLoaded', function() {
            const lastNameInput = document.getElementById('last_name');
            const firstNameInput = document.getElementById('first_name');
            const lastNameRomajiInput = document.getElementById('last_name_romaji');
            const firstNameRomajiInput = document.getElementById('first_name_romaji');

            // 簡易的な変換テーブル（よく使われる名前の例）
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

            // 漢字からローマ字への簡易変換関数
            function convertToRomaji(japanese) {
                if (!japanese) return '';

                // 変換テーブルに存在する場合はそれを使用
                if (nameConversionMap[japanese]) {
                    return nameConversionMap[japanese];
                }

                // ひらがな・カタカナの場合はそのまま返す（後で変換可能）
                // 漢字の場合は空文字を返す（ユーザーが手動で入力する必要がある）
                return '';
            }

            // 姓の入力時に姓（ローマ字）を自動入力
            if (lastNameInput && lastNameRomajiInput) {
                let lastNameTimeout;
                lastNameInput.addEventListener('input', function() {
                    clearTimeout(lastNameTimeout);
                    const value = this.value.trim();

                    // 姓（ローマ字）が空の場合のみ自動入力
                    if (!lastNameRomajiInput.value.trim() && value) {
                        lastNameTimeout = setTimeout(function() {
                            const romaji = convertToRomaji(value);
                            if (romaji) {
                                lastNameRomajiInput.value = romaji;
                            }
                        }, 500); // 500ms後に変換を試みる
                    }
                });
            }

            // 名の入力時に名（ローマ字）を自動入力
            if (firstNameInput && firstNameRomajiInput) {
                let firstNameTimeout;
                firstNameInput.addEventListener('input', function() {
                    clearTimeout(firstNameTimeout);
                    const value = this.value.trim();

                    // 名（ローマ字）が空の場合のみ自動入力
                    if (!firstNameRomajiInput.value.trim() && value) {
                        firstNameTimeout = setTimeout(function() {
                            const romaji = convertToRomaji(value);
                            if (romaji) {
                                firstNameRomajiInput.value = romaji;
                            }
                        }, 500); // 500ms後に変換を試みる
                    }
                });
            }
        });
    </script>
    <script>
        // Handle tech tool checkbox selection styling
        document.addEventListener('DOMContentLoaded', function() {
            const techToolCheckboxes = document.querySelectorAll('.register-tech-card .tech-tool-checkbox');

            techToolCheckboxes.forEach(checkbox => {
                // Initial state
                updateCardSelection(checkbox);

                // Listen for changes
                checkbox.addEventListener('change', function() {
                    updateCardSelection(this);
                });
            });

            function updateCardSelection(checkbox) {
                const card = checkbox.closest('.register-tech-card');
                if (checkbox.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            }
        });
    </script>
    <style>
        .registration-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .registration-modal .modal-content {
            background-color: #fff;
            margin: 15% auto;
            padding: 0;
            border: none;
            width: 90%;
            max-width: 500px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .registration-modal .modal-body {
            padding: 2.5rem;
            text-align: center;
            font-size: 1.2rem;
            color: #333;
            line-height: 1.6;
        }
        .registration-modal .modal-footer {
            padding: 0 2.5rem 2.5rem;
            text-align: center;
        }
        .registration-modal .modal-footer .btn-primary {
            min-width: 150px;
            padding: 0.75rem 2rem;
        }
    </style>
</body>
</html>
