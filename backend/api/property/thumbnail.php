<?php
/**
 * 物件選定: 一覧サムネイル写真の変更（担当のみ）。
 * 「写真・資料」の中から、お客様の物件一覧に出す写真を担当者が選び直せるようにする。
 * POST(JSON) { property_id, image_id }   image_id = 0/null で自動選択（未設定）に戻す
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
$imageId = isset($input['image_id']) ? (int)$input['image_id'] : 0;
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);
    propertyVerifyAgentProperty($db, $propertyId, $userId);

    if ($imageId > 0) {
        // サムネイルにできるのは、この物件の「写真・資料」の画像だけ。
        // 販売図面は売主仲介会社情報を含むため不可。PDF等の資料も一覧に表示できないため不可。
        $stmt = $db->prepare("SELECT mime_type FROM property_images WHERE id = ? AND property_id = ? AND category = 'photo' LIMIT 1");
        $stmt->execute([$imageId, $propertyId]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$found) sendErrorResponse('この写真はサムネイルに設定できません', 404);
        $mime = (string)($found['mime_type'] ?? '');
        if ($mime !== '' && strpos($mime, 'image/') !== 0) sendErrorResponse('画像以外はサムネイルに設定できません', 400);
        $db->prepare("UPDATE properties SET thumbnail_image_id = ? WHERE id = ?")->execute([$imageId, $propertyId]);
    } else {
        // 未設定に戻す（写真の先頭が自動でサムネイルになる）。
        $db->prepare("UPDATE properties SET thumbnail_image_id = NULL WHERE id = ?")->execute([$propertyId]);
    }

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    sendSuccessResponse(['property' => propertySerialize($db, $row, true, true)],
        $imageId > 0 ? 'サムネイルを変更しました' : 'サムネイルの指定を解除しました');
} catch (Exception $e) {
    error_log('property thumbnail error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
