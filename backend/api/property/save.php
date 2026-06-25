<?php
/**
 * 物件選定: 物件の新規登録 / 編集（§2②手動登録, §8 OCR確認保存, §11 基本情報編集）。
 * 編集はエージェントのみ可能（§11）。
 * POST(JSON) {
 *   property_id?,            // 指定で更新、無指定で新規
 *   session_id?,            // 新規時必須
 *   source?, source_media?, source_url?,
 *   confirm_ocr?,           // true で ocr_status='confirmed' に（§8）
 *   fields: { property_name, price_text, ... }
 * }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    if ($propertyId > 0) {
        // 更新
        $row = propertyVerifyAgentProperty($db, $propertyId, $userId);
        propertyApplyFields($db, $propertyId, $fields);
        if (!empty($input['confirm_ocr'])) {
            $db->prepare("UPDATE properties SET ocr_status = 'confirmed' WHERE id = ?")->execute([$propertyId]);
        }
    } else {
        // 新規
        $sessionId = trim($input['session_id'] ?? '');
        if ($sessionId === '') sendErrorResponse('session_id is required', 400);
        $cardId = propertyVerifyAgentSession($db, $sessionId, $userId);
        $propertyId = propertyCreate($db, [
            'business_card_id' => $cardId,
            'session_id' => $sessionId,
            'source' => ($input['source'] ?? 'agent') === 'customer' ? 'customer' : 'agent',
            'source_media' => $input['source_media'] ?? 'manual',
            'source_url' => $input['source_url'] ?? null,
            'created_by' => 'agent',
            'ocr_status' => !empty($input['confirm_ocr']) ? 'confirmed' : 'none',
        ], $fields);
    }

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    sendSuccessResponse(['property' => propertySerialize($db, $row, true, true)], '保存しました');
} catch (Exception $e) {
    error_log('property save error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
