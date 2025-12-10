<?php
/**
 * Public Business Card Display Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';

$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('HTTP/1.0 404 Not Found');
    exit('Not Found');
}

$database = new Database();
$db = $database->getConnection();

// ビジネスカード情報取得
$stmt = $db->prepare("
    SELECT bc.*, u.status as user_status
    FROM business_cards bc
    JOIN users u ON bc.user_id = u.id
    WHERE bc.url_slug = ? AND u.status = 'active' AND bc.is_published = 1
");
$stmt->execute([$slug]);
$card = $stmt->fetch();

if (!$card) {
    header('HTTP/1.0 404 Not Found');
    exit('Not Found');
}

// アクセスログ記録
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$stmt = $db->prepare("INSERT INTO access_logs (business_card_id, ip_address, user_agent) VALUES (?, ?, ?)");
$stmt->execute([$card['id'], $ipAddress, $userAgent]);

// 挨拶文取得
$stmt = $db->prepare("SELECT title, content FROM greeting_messages WHERE business_card_id = ? ORDER BY display_order ASC");
$stmt->execute([$card['id']]);
$greetings = $stmt->fetchAll();

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

// コミュニケーション方法取得
$stmt = $db->prepare("SELECT method_type, method_name, method_url, method_id FROM communication_methods WHERE business_card_id = ? AND is_active = 1 ORDER BY display_order ASC");
$stmt->execute([$card['id']]);
$communicationMethods = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($card['name']); ?> - デジタル名刺</title>
    <link rel="stylesheet" href="assets/css/card.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>
<body>
    <div class="card-container">
        <!-- 名刺部 -->
        <section class="card-section">
            <div class="card-header">
                <?php if ($card['company_logo']): ?>
                <img src="<?php echo htmlspecialchars($card['company_logo']); ?>" alt="ロゴ" class="company-logo">
                <?php endif; ?>
                <h1 class="company-name"><?php echo htmlspecialchars($card['company_name'] ?? ''); ?></h1>
            </div>

            <div class="card-body">
                <?php if ($card['profile_photo']): ?>
                <img src="<?php echo htmlspecialchars($card['profile_photo']); ?>" alt="プロフィール写真" class="profile-photo">
                <?php endif; ?>

                <div class="person-info">
                    <h2 class="person-name"><?php echo htmlspecialchars($card['name']); ?></h2>
                    <?php if ($card['position']): ?>
                    <p class="person-position"><?php echo htmlspecialchars($card['position']); ?></p>
                    <?php endif; ?>
                    <?php if ($card['qualifications']): ?>
                    <p class="person-qualification"><?php echo htmlspecialchars($card['qualifications']); ?></p>
                    <?php endif; ?>
                </div>

                <!-- 挨拶文 -->
                <?php if (!empty($greetings)): ?>
                <div class="greetings-section">
                    <?php foreach ($greetings as $greeting): ?>
                    <div class="greeting-item">
                        <?php if (!empty($greeting['title'])): ?>
                        <h3><?php echo htmlspecialchars($greeting['title']); ?></h3>
                        <?php endif; ?>
                        <?php if (!empty($greeting['content'])): ?>
                        <p><?php echo nl2br(htmlspecialchars($greeting['content'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- QRコード -->
                <?php if (!empty($card['qr_code']) && $card['qr_code_issued']): ?>
                <div class="qr-code-section">
                    <h3>デジタル名刺のQRコード</h3>
                    <div class="qr-code-container">
                        <img src="<?php echo htmlspecialchars(BASE_URL . '/backend/' . $card['qr_code']); ?>" 
                             alt="QRコード" 
                             class="qr-code-image"
                             onerror="this.style.display='none'">
                        <p class="qr-code-description">このQRコードをスキャンして名刺を共有できます</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- コミュニケーション方法 -->
                <?php if (!empty($communicationMethods)): ?>
                <div class="communication-section">
                    <h3>連絡先</h3>
                    <div class="communication-buttons">
                        <?php foreach ($communicationMethods as $method): ?>
                        <?php
                        $methodIcons = [
                            'line' => '💬',
                            'messenger' => '💌',
                            'whatsapp' => '📱',
                            'instagram' => '📷',
                            'facebook' => '👥',
                            'twitter' => '🐦',
                            'youtube' => '📺'
                        ];
                        $icon = $methodIcons[$method['method_type']] ?? '📞';
                        ?>
                        <?php if ($method['method_url'] || $method['method_id']): ?>
                        <a href="<?php echo htmlspecialchars($method['method_url'] ?? '#'); ?>" class="comm-btn" target="_blank">
                            <span class="comm-icon"><?php echo $icon; ?></span>
                            <span><?php echo htmlspecialchars($method['method_name']); ?></span>
                        </a>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- テックツール部 -->
        <?php if (!empty($techTools)): ?>
        <section class="tech-tools-section">
            <h2>不動産テックツール</h2>
            <p class="section-description">物件の購入・売却に役立つツールをご利用いただけます</p>
            
            <div class="tech-tools-grid">
                <?php foreach ($techTools as $tool): ?>
                <a href="<?php echo htmlspecialchars($tool['tool_url']); ?>" class="tech-tool-card" target="_blank">
                    <div class="tool-icon">
                        <?php
                        $icons = [
                            'mdb' => '🏢',
                            'rlp' => '🤖',
                            'llp' => '🏞️',
                            'ai' => '📊',
                            'slp' => '🔍',
                            'olp' => '💼',
                            'alp' => '🔗'
                        ];
                        echo $icons[$tool['tool_type']] ?? '📋';
                        ?>
                    </div>
                    <h3><?php echo htmlspecialchars($tool['tool_name']); ?></h3>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- 編集リンク（管理者のみ） -->
        <div class="edit-link">
            <a href="<?php echo BASE_URL; ?>/frontend/edit.php">不動産AI名刺の編集はこちら</a>
        </div>
    </div>

    <script src="assets/js/card.js"></script>
</body>
</html>

