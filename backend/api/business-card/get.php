<?php
/**
 * Get Business Card API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'GET') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();

    $database = new Database();
    $db = $database->getConnection();

    // ビジネスカード取得
    $stmt = $db->prepare("
        SELECT bc.*, u.email, u.user_type, u.status as user_status
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        WHERE bc.user_id = ?
    ");
    
    $stmt->execute([$userId]);
    $businessCard = $stmt->fetch();
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (!$businessCard) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    // 挨拶文取得
    $stmt = $db->prepare("
        SELECT id, title, content, display_order
        FROM greeting_messages
        WHERE business_card_id = ?
        ORDER BY display_order ASC
    ");
    
    $stmt->execute([$businessCard['id']]);
    $greetings = $stmt->fetchAll();

    // テックツール取得
    $stmt = $db->prepare("
        SELECT id, tool_type, tool_url, display_order, is_active
        FROM tech_tool_selections
        WHERE business_card_id = ?
        ORDER BY display_order ASC
    ");
    
    $stmt->execute([$businessCard['id']]);
    $techTools = $stmt->fetchAll();

    // コミュニケーション方法取得
    $stmt = $db->prepare("
        SELECT id, method_type, method_name, method_url, method_id, is_active, display_order
        FROM communication_methods
        WHERE business_card_id = ?
        ORDER BY display_order ASC
    ");
    
    $stmt->execute([$businessCard['id']]);
    $communicationMethods = $stmt->fetchAll();

    // レスポンス組み立て
    $response = $businessCard;
    $response['greetings'] = $greetings;
    $response['tech_tools'] = $techTools;
    $response['communication_methods'] = $communicationMethods;

    sendSuccessResponse($response, 'ビジネスカード情報を取得しました');

} catch (Exception $e) {
    error_log("Get Business Card Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
    
}

