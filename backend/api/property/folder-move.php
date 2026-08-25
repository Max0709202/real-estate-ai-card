<?php
/**
 * 物件選定: 物件をフォルダーへ格納 / フォルダーから出す（担当者のみ）。
 * POST(JSON) { property_id, folder_id }  … folder_id が 0 / null / 空ならフォルダーから出す
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
$folderId = isset($input['folder_id']) ? (int)$input['folder_id'] : 0;
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $property = propertyVerifyAgentProperty($db, $propertyId, $userId);

    if ($folderId > 0) {
        $folder = propertyVerifyAgentFolder($db, $folderId, $userId);
        // フォルダーは顧客（セッション）単位。別のお客様のフォルダーへは格納できない。
        if ((string)$folder['session_id'] !== (string)$property['session_id']) {
            sendErrorResponse('フォルダーが見つかりません', 404);
        }
        $db->prepare("UPDATE properties SET folder_id = ? WHERE id = ?")->execute([$folderId, $propertyId]);
        sendSuccessResponse([
            'property_id' => $propertyId,
            'folder_id' => $folderId,
            'folder_name' => $folder['name'],
        ], '「' . $folder['name'] . '」に格納しました');
    }

    $db->prepare("UPDATE properties SET folder_id = NULL WHERE id = ?")->execute([$propertyId]);
    sendSuccessResponse([
        'property_id' => $propertyId,
        'folder_id' => null,
        'folder_name' => null,
    ], 'フォルダーから出しました');
} catch (Exception $e) {
    error_log('property folder-move error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
