<?php
/**
 * Generate Tech Tool URLs API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $database = new Database();
    $db = $database->getConnection();

    // ビジネスカード取得
    $stmt = $db->prepare("SELECT id, url_slug, user_id FROM business_cards WHERE user_id = ?");
    $stmt->execute([$userId]);
    $businessCard = $stmt->fetch();

    if (!$businessCard) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    // ユーザータイプとERA会員情報を取得
    $stmt = $db->prepare("SELECT user_type, is_era_member FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $urlIdentifier = $businessCard['url_slug'];
    $isEraMember = $user['is_era_member'] ?? 0;

    // ERA会員かどうかでベースURLを変更
    $selfInBase = $isEraMember ? 'https://era.self-in.com/' : 'https://self-in.com/';
    $selfInNetBase = $isEraMember ? 'https://era.self-in.net/' : 'https://self-in.net/';

    // テックツールURL生成
    $techTools = [
        [
            'tool_type' => 'mdb',
            'tool_name' => '全国マンションデータベース',
            'tool_url' => $selfInBase . $urlIdentifier . '/mdb/'
        ],
        [
            'tool_type' => 'rlp',
            'tool_name' => '物件提案ロボ',
            'tool_url' => $selfInNetBase . 'rlp/index.php?id=' . $urlIdentifier . '/'
        ],
        [
            'tool_type' => 'llp',
            'tool_name' => '土地情報ロボ',
            'tool_url' => $selfInNetBase . 'llp/index.php?id=' . $urlIdentifier . '/'
        ],
        [
            'tool_type' => 'ai',
            'tool_name' => 'AIマンション査定',
            'tool_url' => $selfInBase . $urlIdentifier . '/ai/'
        ],
        [
            'tool_type' => 'slp',
            'tool_name' => 'セルフィン',
            'tool_url' => $selfInNetBase . 'slp/index.php?id=' . $urlIdentifier . '/'
        ],
        [
            'tool_type' => 'olp',
            'tool_name' => 'オーナーコネクト',
            'tool_url' => $selfInNetBase . 'olp/index.php?id=' . $urlIdentifier . '/'
        ],
        [
            'tool_type' => 'alp',
            'tool_name' => '統合LP',
            'tool_url' => $selfInNetBase . 'alp/index.php?id=' . $urlIdentifier . '/'
        ]
    ];

    // 選択されたツールのURLを返す
    $selectedTools = $input['selected_tools'] ?? [];
    $result = [];

    foreach ($techTools as $tool) {
        if (in_array($tool['tool_type'], $selectedTools)) {
            $result[] = $tool;
        }
    }

    sendSuccessResponse([
        'tech_tools' => $result,
        'url_identifier' => $urlIdentifier
    ], 'テックツールURLを生成しました');

} catch (Exception $e) {
    error_log("Generate Tech Tool URLs Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

