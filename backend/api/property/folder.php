<?php
/**
 * 物件選定: 提案物件フォルダーの作成・名前変更・削除（担当者のみ）。
 * 担当者が「2026年8月21日ご案内物件」のような名前のフォルダーを作り、提案物件を格納できるようにする。
 * POST(JSON) { session_id, name }                    … 作成（action 省略時）
 * POST(JSON) { folder_id, name, action: 'rename' }   … 名前変更
 * POST(JSON) { folder_id, action: 'delete' }         … 削除（中の物件は削除せずフォルダー未格納に戻す）
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
$action = trim((string)($input['action'] ?? 'create'));
$folderId = isset($input['folder_id']) ? (int)$input['folder_id'] : 0;

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    if ($action === 'delete') {
        if ($folderId <= 0) sendErrorResponse('folder_id is required', 400);
        $folder = propertyVerifyAgentFolder($db, $folderId, $userId);
        // 中の物件は消さず、フォルダー未格納（一覧ではフォルダーの下）に戻す。
        $db->prepare("UPDATE properties SET folder_id = NULL WHERE folder_id = ?")->execute([$folderId]);
        $db->prepare("DELETE FROM property_folders WHERE id = ?")->execute([$folderId]);
        sendSuccessResponse([
            'folders' => propertyFoldersFor($db, (string)$folder['session_id'], true),
        ], 'フォルダーを削除しました');
    }

    // 作成・名前変更に共通のフォルダー名チェック。
    $name = propertyNormalizeFolderName((string)($input['name'] ?? ''));
    if ($name === '') sendErrorResponse('フォルダー名を入力してください', 400);
    $max = propertyFolderNameMaxLength();
    if (mb_strlen($name) > $max) sendErrorResponse('フォルダー名は' . $max . '字以内で入力してください', 400);

    if ($action === 'rename') {
        if ($folderId <= 0) sendErrorResponse('folder_id is required', 400);
        $folder = propertyVerifyAgentFolder($db, $folderId, $userId);
        $db->prepare("UPDATE property_folders SET name = ? WHERE id = ?")->execute([$name, $folderId]);
        sendSuccessResponse([
            'folder' => ['id' => $folderId, 'name' => $name],
            'folders' => propertyFoldersFor($db, (string)$folder['session_id'], true),
        ], 'フォルダー名を変更しました');
    }

    $sessionId = trim((string)($input['session_id'] ?? ''));
    if ($sessionId === '') sendErrorResponse('session_id is required', 400);
    $businessCardId = propertyVerifyAgentSession($db, $sessionId, $userId);

    $stmt = $db->prepare("INSERT INTO property_folders (business_card_id, session_id, name) VALUES (?, ?, ?)");
    $stmt->execute([$businessCardId, $sessionId, $name]);
    $newId = (int)$db->lastInsertId();

    sendSuccessResponse([
        'folder' => ['id' => $newId, 'name' => $name, 'property_count' => 0],
        'folders' => propertyFoldersFor($db, $sessionId, true),
    ], 'フォルダーを作成しました');
} catch (Exception $e) {
    error_log('property folder error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
