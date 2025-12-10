<?php
/**
 * Get Public Business Card API (QR Code Access)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'GET') {
        sendErrorResponse('Method not allowed', 405);
    }

    $urlSlug = $_GET['slug'] ?? '';

    if (empty($urlSlug)) {
        sendErrorResponse('URLスラッグが必要です', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // ビジネスカード取得（公開用）
    $stmt = $db->prepare("
        SELECT bc.*, u.status as user_status
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        WHERE bc.url_slug = ? AND u.status = 'active' AND bc.is_published = 1
    ");
    
    $stmt->execute([$urlSlug]);
    $businessCard = $stmt->fetch();

    if (!$businessCard) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    // アクセスログ記録
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $db->prepare("
        INSERT INTO access_logs (business_card_id, ip_address, user_agent)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$businessCard['id'], $ipAddress, $userAgent]);

    // 挨拶文取得
    $stmt = $db->prepare("
        SELECT id, title, content, display_order
        FROM greeting_messages
        WHERE business_card_id = ?
        ORDER BY display_order ASC
    ");
    
    $stmt->execute([$businessCard['id']]);
    $greetings = $stmt->fetchAll();

    // テックツール取得（有効なもののみ）
    $stmt = $db->prepare("
        SELECT id, tool_type, tool_name, tool_url, display_order
        FROM tech_tool_selections
        WHERE business_card_id = ? AND is_active = 1
        ORDER BY display_order ASC
    ");
    
    $stmt->execute([$businessCard['id']]);
    $techTools = $stmt->fetchAll();

    // コミュニケーション方法取得（有効なもののみ）
    $stmt = $db->prepare("
        SELECT id, method_type, method_name, method_url, method_id, display_order
        FROM communication_methods
        WHERE business_card_id = ? AND is_active = 1
        ORDER BY display_order ASC
    ");
    
    $stmt->execute([$businessCard['id']]);
    $communicationMethods = $stmt->fetchAll();

    // レスポンス組み立て
    $response = $businessCard;
    $response['greetings'] = $greetings;
    $response['tech_tools'] = $techTools;
    $response['communication_methods'] = $communicationMethods;

    sendSuccessResponse($response);

} catch (Exception $e) {
    error_log("Public Business Card Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

