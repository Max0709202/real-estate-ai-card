<?php
/**
 * 物件選定: 物件URLの自動解析・登録（§2④ / §18）。
 * SUUMO / HOME'S / アットホーム / Yahoo!不動産 等のURLから情報を抽出して物件登録。
 *  - 顧客（visitor_id あり）: source='customer'（お客様から共有・オレンジ）
 *  - 担当（ログイン）: source='agent'
 * POST(JSON) { session_id, url, visitor_id? }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$sessionId = trim($input['session_id'] ?? '');
$url = trim($input['url'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
if ($sessionId === '') sendErrorResponse('session_id is required', 400);
if (!preg_match('#^https?://#i', $url)) sendErrorResponse('有効なURLを入力してください', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    if ($visitorId !== '') {
        $cardId = propertyVerifyCustomerSession($db, $sessionId, $visitorId);
        $source = 'customer'; $createdBy = 'customer'; $forAgent = false;
    } else {
        startSessionIfNotStarted();
        $userId = requireAuth();
        $cardId = propertyVerifyAgentSession($db, $sessionId, $userId);
        $source = 'agent'; $createdBy = 'agent'; $forAgent = true;
    }

    $res = propertyExtractFromUrl($url, ['session_id' => $sessionId, 'business_card_id' => $cardId]);
    if (!empty($res['error']) && empty($res['fields'])) {
        sendErrorResponse($res['error'], 502);
    }

    $propertyId = propertyCreate($db, [
        'business_card_id' => $cardId,
        'session_id' => $sessionId,
        'source' => $source,
        'source_media' => $res['media'] ?? 'other',
        'source_url' => $url,
        'created_by' => $createdBy,
        'ocr_status' => 'draft',
    ], $res['fields']);

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    sendSuccessResponse([
        'property' => propertySerialize($db, $row, $forAgent, true),
        'extract_error' => $res['error'] ?? null,
    ], 'URLから物件を登録しました');
} catch (Exception $e) {
    error_log('property analyze-url error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
