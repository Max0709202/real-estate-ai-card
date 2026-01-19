<?php
/**
 * Public Business Card Display Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';

/**
 * Convert URLs in text to clickable links
 * @param string $text The text to process
 * @return string Text with URLs converted to clickable links (with HTML escaped)
 */
function linkifyUrlsInText($text) {
    if (empty($text)) return $text;
    
    // Pattern to match URLs (http://, https://, or www.)
    $pattern = '/(\b(?:https?:\/\/|www\.)[^\s<>"\'{}|\\^`\[\]]+)/i';
    
    // Find all URLs and store them with placeholders
    $urls = [];
    $placeholders = [];
    $counter = 0;
    
    $text = preg_replace_callback($pattern, function($matches) use (&$urls, &$placeholders, &$counter) {
        $url = $matches[1];
        $placeholder = '___URL_PLACEHOLDER_' . $counter . '___';
        
        // Normalize URL (add http:// if it starts with www.)
        $href = $url;
        if (preg_match('/^www\./i', $url)) {
            $href = 'http://' . $url;
        }
        
        $urls[$counter] = [
            'original' => $url,
            'href' => $href
        ];
        $placeholders[] = $placeholder;
        $counter++;
        
        return $placeholder;
    }, $text);
    
    // Escape the entire text (placeholders are safe, they won't be escaped in a way that breaks them)
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    
    // Replace placeholders with properly escaped links
    foreach ($placeholders as $index => $placeholder) {
        if (isset($urls[$index])) {
            $hrefEscaped = htmlspecialchars($urls[$index]['href'], ENT_QUOTES, 'UTF-8');
            $textEscaped = htmlspecialchars($urls[$index]['original'], ENT_QUOTES, 'UTF-8');
            $link = '<a href="' . $hrefEscaped . '" target="_blank" rel="noopener noreferrer">' . $textEscaped . '</a>';
            $escaped = str_replace(htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'), $link, $escaped);
        }
    }
    
    return $escaped;
}

$slug = $_GET['slug'] ?? '';
$preview = isset($_GET['preview']) && $_GET['preview'] === '1';

if (empty($slug)) {
    header('HTTP/1.0 404 Not Found');
    exit('Not Found');
}

$database = new Database();
$db = $database->getConnection();

// ビジネスカード情報取得
$stmt = $db->prepare("
    SELECT bc.*, u.status as user_status, bc.payment_status
    FROM business_cards bc
    JOIN users u ON bc.user_id = u.id
    WHERE bc.url_slug = ? AND u.status = 'active'
");
$stmt->execute([$slug]);
$card = $stmt->fetch();

if (!$card) {
    header('HTTP/1.0 404 Not Found');
    exit('Not Found');
}

// Check payment status and publication status
// Card can only be viewed if payment_status is CR, BANK_PAID, or ST, and is_published is 1
// However, allow preview mode when preview=1 parameter is set (for edit page)
if (!$preview && (!in_array($card['payment_status'], ['CR', 'BANK_PAID', 'ST']) || $card['is_published'] == 0)) {
    // Display custom message instead of 404
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>名刺を表示できません</title>
    <style>
        body {
            font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .message-container {
            background: #fff;
            border-radius: 12px;
            padding: 3rem;
            max-width: 500px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .message-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
        }
        .message-title {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 1rem;
            font-weight: bold;
        }
        .message-description {
            color: #666;
            line-height: 1.8;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="message-container">
        <div class="message-icon">⚠️</div>
        <h1 class="message-title">この名刺は現在ご利用いただけません</h1>
        <p class="message-description">
            <?php if (!in_array($card['payment_status'], ['CR', 'BANK_PAID', 'ST'])): ?>
                入金確認が完了していないため、名刺を表示することができません。<br>
                名刺のご利用には入金確認が必要です。
            <?php elseif ($card['is_published'] == 0): ?>
                この名刺は現在非公開のため表示できません。
            <?php endif; ?>
        </p>
    </div>
</body>
</html>
    <?php
    exit();
}

// アクセスログ記録
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$stmt = $db->prepare("INSERT INTO access_logs (business_card_id, ip_address, user_agent) VALUES (?, ?, ?)");
$stmt->execute([$card['id'], $ipAddress, $userAgent]);

// 挨拶文取得（ユーザーが入力した順序で取得）
$stmt = $db->prepare("SELECT title, content FROM greeting_messages WHERE business_card_id = ? ORDER BY display_order ASC");
$stmt->execute([$card['id']]);
$greetings = $stmt->fetchAll();

// ページネーション設定
$greetingsPerPage = 1; // 1ページあたりの挨拶文数
$currentPage = isset($_GET['greeting_page']) ? max(1, intval($_GET['greeting_page'])) : 1;
$totalGreetings = count($greetings);
$totalPages = $totalGreetings > 0 ? ceil($totalGreetings / $greetingsPerPage) : 1;
$currentPage = min($currentPage, $totalPages); // 最大ページ数を超えないように

// 現在のページに表示する挨拶文を取得
$greetingsForCurrentPage = [];
if ($totalGreetings > 0) {
    $startIndex = ($currentPage - 1) * $greetingsPerPage;
    $greetingsForCurrentPage = array_slice($greetings, $startIndex, $greetingsPerPage);
}

// テックツール取得
$stmt = $db->prepare("SELECT tool_type, tool_url FROM tech_tool_selections WHERE business_card_id = ? AND is_active = 1 ORDER BY display_order ASC");
$stmt->execute([$card['id']]);
$techTools = $stmt->fetchAll();

// ツール名のマッピング（generate-urls.phpと同じ定義）
$toolNames = [
    'mdb' => '全国マンションデータベース',
    'rlp' => '物件提案ロボ',
    'llp' => '土地情報ロボ',
    'ai' => 'AIマンション査定',
    'slp' => 'セルフィン',
    'olp' => 'オーナーコネクト',
    'alp' => '統合LP'
];

// ツール名を追加
foreach ($techTools as &$tool) {
    $tool['tool_name'] = $toolNames[$tool['tool_type']] ?? 'テックツール';
}
unset($tool);

// コミュニケーション方法取得 - Message Apps first, then SNS
// Mapping from method_type to icon filename (some method types don't match icon filenames)
$iconMapping = [
    'plus_message' => 'message',  // plus_message uses message.png
    // All other types use their method_type as the filename
];

// Function to get icon filename from method_type
function getIconFilename($methodType, $iconMapping) {
    return $iconMapping[$methodType] ?? $methodType;
}

// Message app types (safe - hardcoded list)
$messageAppTypes = ['line', 'messenger', 'whatsapp', 'plus_message', 'chatwork', 'andpad'];
$placeholders = implode(',', array_fill(0, count($messageAppTypes), '?'));
$stmt = $db->prepare("SELECT method_type, method_name, method_url, method_id FROM communication_methods WHERE business_card_id = ? AND is_active = 1 AND method_type IN ($placeholders) ORDER BY display_order ASC");
$stmt->execute(array_merge([$card['id']], $messageAppTypes));
$messageApps = $stmt->fetchAll();
// Reverse message apps for display (same as tech tools)
// $messageApps = array_reverse($messageApps);

// SNS types (safe - hardcoded list)
$snsTypes = ['instagram', 'facebook', 'twitter', 'youtube', 'tiktok', 'note', 'pinterest', 'threads'];
$placeholders = implode(',', array_fill(0, count($snsTypes), '?'));
$stmt = $db->prepare("SELECT method_type, method_name, method_url, method_id FROM communication_methods WHERE business_card_id = ? AND is_active = 1 AND method_type IN ($placeholders) ORDER BY display_order ASC");
$stmt->execute(array_merge([$card['id']], $snsTypes));
$snsApps = $stmt->fetchAll();
// Reverse SNS apps for display (same as tech tools)
// $snsApps = array_reverse($snsApps);

// Combine: Message Apps first, then SNS
$communicationMethods = array_merge($messageApps, $snsApps);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($card['name']); ?> - デジタル名刺</title>
    <link rel="stylesheet" href="assets/css/card.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body>
    <div class="card-container">
        <!-- 名刺部 -->
        <section class="card-section">
            <div class="card-header">
                <?php if (!empty($card['company_logo'])): ?>
                    <?php 
                    $logoPath = trim($card['company_logo']);
                    // Add BASE_URL if path doesn't start with http
                    if (!empty($logoPath) && !preg_match('/^https?:\/\//', $logoPath)) {
                        // Remove BASE_URL if already included to avoid duplication
                        $baseUrlPattern = preg_quote(BASE_URL, '/');
                        if (preg_match('/^' . $baseUrlPattern . '/i', $logoPath)) {
                            // Already contains BASE_URL, use as is
                        } else {
                        $logoPath = BASE_URL . '/' . ltrim($logoPath, '/');
                        }
                    }
                    ?>
                    <?php if (!empty($logoPath)): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="ロゴ" class="company-logo" onerror="this.style.display='none';">
                    <?php endif; ?>
                <?php endif; ?>
                <h1 class="company-name"><?php echo htmlspecialchars($card['company_name'] ?? ''); ?></h1>
            </div>
            <hr>

            <div class="card-body">
                <!-- プロフィール写真と挨拶文のセクション -->
                <div class="profile-greeting-section">
                    <?php if (!empty($card['profile_photo'])): ?>
                        <div class="profile-photo-container">
                            <?php 
                            $photoPath = trim($card['profile_photo']);
                            // Add BASE_URL if path doesn't start with http
                            if (!empty($photoPath) && !preg_match('/^https?:\/\//', $photoPath)) {
                                // Remove BASE_URL if already included to avoid duplication
                                $baseUrlPattern = preg_quote(BASE_URL, '/');
                                if (preg_match('/^' . $baseUrlPattern . '/i', $photoPath)) {
                                    // Already contains BASE_URL, use as is
                                } else {
                                $photoPath = BASE_URL . '/' . ltrim($photoPath, '/');
                                }
                            }
                            ?>
                            <?php if (!empty($photoPath)): ?>
                                <img src="<?php echo htmlspecialchars($photoPath); ?>" alt="プロフィール写真" class="profile-photo" onerror="this.style.display='none';">
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="greeting-content">
                        <?php if (!empty($greetingsForCurrentPage)): ?>
                            <div class="greeting-pagination-container" data-total-pages="<?php echo $totalPages; ?>" data-current-page="<?php echo $currentPage; ?>">
                                <?php foreach ($greetingsForCurrentPage as $greeting): ?>
                                    <div class="greeting-item greeting-page-item">
                                        <?php if (!empty($greeting['title'])): ?>
                                            <h3 class="greeting-title"><?php echo htmlspecialchars($greeting['title']); ?></h3>
                                        <?php endif; ?>
                                        <?php if (!empty($greeting['content'])): ?>
                                            <p class="greeting-text"><?php echo nl2br(htmlspecialchars($greeting['content'])); ?></p>
                                <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($totalPages > 1): ?>
                                    <div class="greeting-pagination-controls">
                                        <button type="button" class="greeting-pagination-btn greeting-prev-btn"
                                                <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>
                                                data-page="<?php echo $currentPage - 1; ?>">
                                            <span>‹</span> 前へ
                                        </button>

                                        <div class="greeting-pagination-info">
                                            <span class="greeting-page-number"><?php echo $currentPage; ?></span>
                                            <span class="greeting-page-separator">/</span>
                                            <span class="greeting-total-pages"><?php echo $totalPages; ?></span>
                                        </div>

                                        <button type="button" class="greeting-pagination-btn greeting-next-btn"
                                                <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>
                                                data-page="<?php echo $currentPage + 1; ?>">
                                            次へ <span>›</span>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr>
            <div class="card-body">
                <!-- 個人情報セクション - Responsive Two-Column Layout -->
                <div class="info-responsive-grid">
                    <!-- 会社名 -->
                    <div class="info-section company-info">
                        <h3>会社名</h3>
                        <p><?php echo htmlspecialchars($card['company_name'] ?? ''); ?></p>
                    </div>

                    <?php if ($card['real_estate_license_prefecture'] || $card['real_estate_license_renewal_number'] || $card['real_estate_license_registration_number']): ?>
                        <div class="info-section">
                            <h3>宅建業番号</h3>
                            <p>
                                <?php
                                if ($card['real_estate_license_prefecture']) {
                                    echo htmlspecialchars($card['real_estate_license_prefecture']);
                                    if ($card['real_estate_license_renewal_number']) {
                                        echo '(' . htmlspecialchars($card['real_estate_license_renewal_number']) . ')';
                                    }
                                    if ($card['real_estate_license_registration_number']) {
                                        echo '第' . htmlspecialchars($card['real_estate_license_registration_number']) . '号';
                                    }
                                }
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($card['company_postal_code'] || $card['company_address']): ?>
                        <div class="info-section">
                            <h3>所在地</h3>
                            <?php if ($card['company_postal_code']): ?>
                                <p>〒<?php echo htmlspecialchars($card['company_postal_code']); ?></p>
                            <?php endif; ?>
                            <?php if ($card['company_address']): ?>
                                <p><?php echo htmlspecialchars($card['company_address']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($card['company_phone']): ?>
                        <div class="info-section">
                            <h3>会社電話番号</h3>
                            <p><?php echo htmlspecialchars($card['company_phone']); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($card['company_website']): ?>
                        <div class="info-section">
                            <h3>HP</h3>
                            <p><a href="<?php echo htmlspecialchars($card['company_website']); ?>"
                                    target="_blank"><?php echo htmlspecialchars($card['company_website']); ?></a></p>
                        </div>
                    <?php endif; ?>

                    <!-- 部署 / 役職 -->
                    <?php if ($card['branch_department'] || $card['position']): ?>
                        <div class="info-section">
                            <h3>部署 / 役職</h3>
                            <p>
                                <?php
                                $dept_position = array_filter([
                                    $card['branch_department'] ?? '',
                                    $card['position'] ?? ''
                                ]);
                                echo htmlspecialchars(implode(' / ', $dept_position));
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- 名前 -->
                    <div class="info-section person-name-section">
                        <h3>名前</h3>
                        <p class="person-name-large">
                            <?php echo htmlspecialchars($card['name']); ?>
                            <?php if ($card['name_romaji']): ?>
                                <span class="name-romaji">(<?php echo htmlspecialchars($card['name_romaji']); ?>)</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- 携帯番号 -->
                    <div class="info-section">
                        <h3>携帯番号</h3>
                        <p><?php echo htmlspecialchars($card['mobile_phone']); ?></p>
                    </div>

                    <?php if ($card['birth_date']): ?>
                        <div class="info-section">
                            <h3>生年月日</h3>
                            <p><?php echo date('Y年m月d日', strtotime($card['birth_date'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($card['current_residence'] || $card['hometown']): ?>
                        <div class="info-section">
                            <h3>現在の居住地 / 出身地</h3>
                            <p>
                                <?php
                                $residence_parts = array_filter([
                                    $card['current_residence'] ?? '',
                                    $card['hometown'] ?? ''
                                ]);
                                echo htmlspecialchars(implode(' / ', $residence_parts));
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($card['alma_mater']): ?>
                        <div class="info-section">
                            <h3>出身校</h3>
                            <p><?php echo nl2br(htmlspecialchars($card['alma_mater'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($card['qualifications']): ?>
                        <div class="info-section">
                            <h3>資格</h3>
                            <p><?php echo nl2br(htmlspecialchars($card['qualifications'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($card['hobbies']): ?>
                        <div class="info-section">
                            <h3>趣味</h3>
                            <p><?php echo nl2br(htmlspecialchars($card['hobbies'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                    <?php if ($card['free_input']): ?>
                        <div class="info-section free-input-section">
                            <h3>その他</h3>
                            <div class="free-input-content">
                                <?php
                                // Try to decode JSON
                                $freeInputData = json_decode($card['free_input'], true);

                                if (json_last_error() === JSON_ERROR_NONE && is_array($freeInputData)) {
                                    // Check for new format with texts and images arrays
                                    if (isset($freeInputData['texts']) && isset($freeInputData['images'])) {
                                        // New format: paired items
                                        $texts = $freeInputData['texts'] ?? [];
                                        $images = $freeInputData['images'] ?? [];

                                        // Get the maximum count to handle all pairs
                                        $pairCount = max(count($texts), count($images));

                                        // Display each pair
                                        for ($i = 0; $i < $pairCount; $i++) {
                                            $text = isset($texts[$i]) ? trim($texts[$i]) : '';
                                            $imageData = isset($images[$i]) ? $images[$i] : ['image' => '', 'link' => ''];
                                            $imagePath = $imageData['image'] ?? '';
                                            $imageLink = $imageData['link'] ?? '';

                                            // Skip if both text and image are empty
                                            if (empty($text) && empty($imagePath) && empty($imageLink)) {
                                                continue;
                                            }

                                            echo '<div class="free-input-pair">';

                                            // Display text if exists
                                            if (!empty($text)) {
                                                echo '<div class="free-input-text-wrapper">';
                                                echo '<p class="free-input-text">' . nl2br(linkifyUrlsInText($text)) . '</p>';
                                                echo '</div>';
                                            }

                                            // Display image if exists
                                            if (!empty($imagePath)) {
                                                echo '<div class="free-input-image-wrapper">';
                                                // Add BASE_URL if the path doesn't start with http
                                                if (!preg_match('/^https?:\/\//', $imagePath)) {
                                                    $imagePath = BASE_URL . '/' . ltrim($imagePath, '/');
                                                }

                                                // If there's a link, wrap image in anchor tag
                                                if (!empty($imageLink)) {
                                                    echo '<a href="' . htmlspecialchars($imageLink) . '" target="_blank" rel="noopener noreferrer" class="free-input-image-link">';
                                                }

                                                echo '<img src="' . htmlspecialchars($imagePath) . '" alt="アップロード画像" class="free-input-image">';

                                                if (!empty($imageLink)) {
                                                    echo '</a>';
                                                }

                                                echo '</div>';
                                            }

                                            // Display image_link if exists (and image is not set)
                                            if (empty($imagePath) && !empty($imageLink)) {
                                                echo '<div class="free-input-link-wrapper">';
                                                echo '<a href="' . htmlspecialchars($imageLink) . '" target="_blank" rel="noopener noreferrer" class="free-input-link">';

                                                // Check if it's an image URL to display thumbnail
                                                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                                $urlExtension = strtolower(pathinfo(parse_url($imageLink, PHP_URL_PATH), PATHINFO_EXTENSION));

                                                if (in_array($urlExtension, $imageExtensions)) {
                                                    echo htmlspecialchars($imageLink);
                                                } else {
                                                    echo htmlspecialchars($imageLink);
                                                }

                                                echo '</a>';
                                                echo '</div>';
                                            }

                                            echo '</div>';
                                        }
                                    } else {
                                        // Old format: single text, image, and image_link
                                        echo '<div class="free-input-pair">';
                            
                                    // Display text if exists
                                    if (!empty($freeInputData['text'])) {
                                            echo '<div class="free-input-text-wrapper">';
                                        echo '<p class="free-input-text">' . nl2br(linkifyUrlsInText($freeInputData['text'])) . '</p>';
                                            echo '</div>';
                                    }

                                    // Display embedded image if exists
                                    if (!empty($freeInputData['image'])) {
                                            echo '<div class="free-input-image-wrapper">';
                                        $imagePath = $freeInputData['image'];
                                        // Add BASE_URL if the path doesn't start with http
                                        if (!preg_match('/^https?:\/\//', $imagePath)) {
                                            $imagePath = BASE_URL . '/' . ltrim($imagePath, '/');
                                        }
                                            echo '<img src="' . htmlspecialchars($imagePath) . '" alt="アップロード画像" class="free-input-image">';
                                        echo '</div>';
                                    }

                                    // Display image_link if exists
                                    if (!empty($freeInputData['image_link'])) {
                                            echo '<div class="free-input-link-wrapper">';
                                            echo '<a href="' . htmlspecialchars($freeInputData['image_link']) . '" target="_blank" rel="noopener noreferrer" class="free-input-link">';

                                        // Check if it's an image URL to display thumbnail
                                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                        $urlExtension = strtolower(pathinfo(parse_url($freeInputData['image_link'], PHP_URL_PATH), PATHINFO_EXTENSION));

                                        if (in_array($urlExtension, $imageExtensions)) {
                                                echo htmlspecialchars($freeInputData['image_link']);
                                        } else {
                                            echo htmlspecialchars($freeInputData['image_link']);
                                        }

                                        echo '</a>';
                                            echo '</div>';
                                        }

                                        echo '</div>';
                                    }

                                } else {
                                    // Not JSON or invalid JSON - display as plain text
                                    echo '<div class="free-input-pair">';
                                    echo '<div class="free-input-text-wrapper">';
                                    echo '<p class="free-input-text">' . nl2br(linkifyUrlsInText($card['free_input'])) . '</p>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <hr>

                <!-- テックツール部 -->
                <?php if (!empty($techTools)): ?>
                    <section class="tech-tools-section">
                        <h2>不動産テックツール</h2>
                        <p class="section-description">物件の購入・売却に役立つツールをご利用いただけます</p>

                        <div class="tech-tools-grid">
                            <?php 
                            // Tool descriptions and banner images
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
                            
                            foreach ($techTools as $tool): 
                                $info = $toolInfo[$tool['tool_type']] ?? [
                                    'description' => '',
                                    'banner_image' => BASE_URL . '/frontend/assets/images/tech_banner/default.jpg'
                                ];
                            ?>
                                <div class="tech-tool-banner-card">
                                    <!-- Banner Header with Background Image -->
                                    <div class="tool-banner-header" style="background-image: url('<?php echo htmlspecialchars($info['banner_image']); ?>'); background-size: contain; background-position: center; background-repeat: no-repeat;">
                                    </div>
                                    
                                    <!-- Description -->
                                    <div class="tool-banner-content">
                                        <div class="tool-description"><?php echo $info['description']; ?></div>
                                        
                                        <!-- Button -->
                                        <a href="<?php echo htmlspecialchars($tool['tool_url']); ?>" 
                                           class="tool-details-button" 
                                           target="_blank">
                                            詳細はこちら
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <!-- コミュニケーション方法 -->
                <?php
                // Helper function to get link URL for message apps
                function getMessageAppLinkUrl($method) {
                    $linkUrl = '';
                    // For message apps, prefer method_id if it's a URL, otherwise use method_url
                    if (!empty($method['method_id']) && (strpos($method['method_id'], 'http://') === 0 || strpos($method['method_id'], 'https://') === 0)) {
                        $linkUrl = trim($method['method_id']);
                    } elseif (!empty($method['method_url'])) {
                        $linkUrl = trim($method['method_url']);
                    } elseif (!empty($method['method_id'])) {
                        // method_id might be an ID that needs to be formatted
                        $linkUrl = trim($method['method_id']);
                    }
                    return $linkUrl;
                }

                // Helper function to get link URL for SNS apps
                function getSnsAppLinkUrl($method) {
                    $linkUrl = '';
                    // For SNS apps, use method_url
                    if (!empty($method['method_url'])) {
                        $linkUrl = trim($method['method_url']);
                    } elseif (!empty($method['method_id'])) {
                        $linkUrl = trim($method['method_id']);
                    }
                    return $linkUrl;
                }

                // Filter out empty methods and collect valid ones (only those with non-empty URLs)
                $validMessageApps = [];
                foreach ($messageApps as $method) {
                    $linkUrl = getMessageAppLinkUrl($method);
                    // Only include if linkUrl is not empty after trimming
                    if (!empty($linkUrl)) {
                        $validMessageApps[] = $method;
                    }
                }

                $validSnsApps = [];
                foreach ($snsApps as $method) {
                    $linkUrl = getSnsAppLinkUrl($method);
                    // Only include if linkUrl is not empty after trimming
                    if (!empty($linkUrl)) {
                        $validSnsApps[] = $method;
                    }
                }

                // Only show section if there are valid communication methods
                if (!empty($validMessageApps) || !empty($validSnsApps)):
                ?>
                    <hr>
                    <div class="communication-section">
                        <h3>コミュニケーション方法</h3>
                        
                        <!-- Combined Message Apps and SNS Section -->
                            <div class="communication-grid">
                            <!-- Message Apps (displayed first) -->
                            <?php foreach ($validMessageApps as $method): ?>
                                <?php $linkUrl = getMessageAppLinkUrl($method); ?>
                                <?php if (!empty($linkUrl)): ?>
                                        <div class="comm-card">
                                            <!-- Message App Logo -->
                                            <div class="comm-logo">
                                                <?php $iconFile = getIconFilename($method['method_type'], $iconMapping); ?>
                                                <img src="<?php echo BASE_URL; ?>/frontend/assets/images/icons/<?php echo htmlspecialchars($iconFile); ?>.png"
                                                     alt="<?php echo htmlspecialchars($method['method_name']); ?>"
                                                 loading="lazy"
                                                 onerror="this.style.display='none'; this.parentElement.innerHTML='<span style=\'font-size: 1.2rem; font-weight: 600; color: #333;\'><?php echo htmlspecialchars($method['method_name']); ?></span>';">
                                            </div>

                                            <!-- Details Button -->
                                                <a href="<?php echo htmlspecialchars($linkUrl); ?>" 
                                                   class="comm-details-button" 
                                           target="_blank"
                                           rel="noopener noreferrer">
                                                    詳細はこちら
                                                </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                        
                            <!-- SNS Apps (displayed second) -->
                            <?php foreach ($validSnsApps as $method): ?>
                                <?php $linkUrl = getSnsAppLinkUrl($method); ?>
                                <?php if (!empty($linkUrl)): ?>
                                        <div class="comm-card">
                                            <!-- SNS Logo -->
                                            <div class="comm-logo">
                                                <?php $iconFile = getIconFilename($method['method_type'], $iconMapping); ?>
                                                <img src="<?php echo BASE_URL; ?>/frontend/assets/images/icons/<?php echo htmlspecialchars($iconFile); ?>.png"
                                                     alt="<?php echo htmlspecialchars($method['method_name']); ?>"
                                                 loading="lazy"
                                                 onerror="this.style.display='none'; this.parentElement.innerHTML='<span style=\'font-size: 1.2rem; font-weight: 600; color: #333;\'><?php echo htmlspecialchars($method['method_name']); ?></span>';">
                                            </div>

                                            <!-- Details Button -->
                                                <a href="<?php echo htmlspecialchars($linkUrl); ?>" 
                                                   class="comm-details-button" 
                                           target="_blank"
                                           rel="noopener noreferrer">
                                                    詳細はこちら
                                                </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php if (!$preview): ?>
        <hr>
        <!-- QRコード -->
        <?php if (!empty($card['qr_code']) && $card['qr_code_issued']): ?>
            <div class="qr-code-section">
                <div class="qr-code-container">
                    <img src="<?php echo htmlspecialchars(BASE_URL . '/backend/' . $card['qr_code']); ?>" alt="QRコード"
                    class="qr-code-image" onerror="this.style.display='none'">
                    <div class="qr-code-content">
                        <h3>デジタル名刺のQRコード</h3>
                        <p class="qr-code-description">このQRコードをスキャンして名刺を共有できます</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
        <!-- 編集リンク（管理者のみ） -->
        <div class="edit-link">
            <a href="<?php echo BASE_URL; ?>/frontend/edit.php">不動産AI名刺の編集はこちら</a>
        </div>
    </div>
    <script>
        // Greeting pagination functionality
        (function() {
            const container = document.querySelector('.greeting-pagination-container');
            if (!container) return;

            const prevBtn = container.querySelector('.greeting-prev-btn');
            const nextBtn = container.querySelector('.greeting-next-btn');
            const currentPage = parseInt(container.dataset.currentPage) || 1;
            const totalPages = parseInt(container.dataset.totalPages) || 1;

            // Function to navigate to a specific page
            function goToPage(page) {
                if (page < 1 || page > totalPages) return;

                const url = new URL(window.location.href);
                url.searchParams.set('greeting_page', page);
                window.location.href = url.toString();
            }

            // Previous button
            if (prevBtn) {
                prevBtn.addEventListener('click', function() {
                    if (!this.disabled && currentPage > 1) {
                        goToPage(currentPage - 1);
                    }
                });
            }

            // Next button
            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    if (!this.disabled && currentPage < totalPages) {
                        goToPage(currentPage + 1);
                    }
                });
            }

            // Keyboard navigation
            document.addEventListener('keydown', function(e) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

                if (e.key === 'ArrowLeft' && currentPage > 1) {
                    goToPage(currentPage - 1);
                } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
                    goToPage(currentPage + 1);
                }
            });

            // Touch swipe support for mobile
            let touchStartX = 0;
            let touchEndX = 0;

            container.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });

            container.addEventListener('touchend', function(e) {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipe();
            }, { passive: true });

            function handleSwipe() {
                const swipeThreshold = 50; // Minimum swipe distance
                const diff = touchStartX - touchEndX;

                if (Math.abs(diff) > swipeThreshold) {
                    if (diff > 0 && currentPage < totalPages) {
                        // Swipe left - next page
                        goToPage(currentPage + 1);
                    } else if (diff < 0 && currentPage > 1) {
                        // Swipe right - previous page
                        goToPage(currentPage - 1);
                    }
                }
            }
        })();
    </script>
</body>

</html>