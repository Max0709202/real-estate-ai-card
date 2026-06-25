<?php
/**
 * 物件選定: 物件画像のアップロード（§14 販売図面 / §15 写真・資料）。
 * 写真・資料は最大10枚、アップロード時に自動リサイズ。担当のみ。
 * multipart/form-data: property_id, category(flyer|photo), subcategory?, files[]（または file）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/upload_security.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

startSessionIfNotStarted();
$userId = requireAuth();

$propertyId = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
$category = ($_POST['category'] ?? 'photo') === 'flyer' ? 'flyer' : 'photo';
$subcategory = trim($_POST['subcategory'] ?? '');
$subcategory = $subcategory === '' ? null : mb_substr($subcategory, 0, 30);
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

$files = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $n = count($_FILES['files']['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $files[] = [
            'name' => $_FILES['files']['name'][$i], 'type' => $_FILES['files']['type'][$i],
            'tmp_name' => $_FILES['files']['tmp_name'][$i], 'error' => $_FILES['files']['error'][$i],
            'size' => $_FILES['files']['size'][$i],
        ];
    }
} elseif (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
    $files[] = $_FILES['file'];
}
if (!$files) sendErrorResponse('ファイルがありません', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);
    $row = propertyVerifyAgentProperty($db, $propertyId, $userId);
    $cardId = (int)$row['business_card_id'];

    // 写真・資料は最大10枚（§15）
    if ($category === 'photo') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM property_images WHERE property_id = ? AND category = 'photo'");
        $stmt->execute([$propertyId]);
        $existing = (int)$stmt->fetchColumn();
        $remaining = max(0, 10 - $existing);
        if ($remaining <= 0) sendErrorResponse('写真・資料は最大10枚までです', 400);
        if (count($files) > $remaining) $files = array_slice($files, 0, $remaining);
    }

    $saved = [];
    $errors = [];
    foreach ($files as $f) {
        $r = propertyStoreUploadedFile($db, $f, $propertyId, $cardId, $category, $subcategory);
        if (!empty($r['error'])) { $errors[] = $r['error']; continue; }
        // 販売図面は売主情報マスクのプレビュー生成＋AI提案を行う（担当の確認待ち）
        if ($category === 'flyer') {
            propertyFlyerProcessUploaded($db, (int)$r['id'], $r['abs_path'], !empty($r['is_pdf']), $cardId, $propertyId);
        }
        unset($r['abs_path']);
        $saved[] = $r;
    }
    if (!$saved) sendErrorResponse($errors ? $errors[0] : '保存できませんでした', 400);

    sendSuccessResponse(['images' => $saved, 'errors' => $errors], '保存しました');
} catch (Exception $e) {
    error_log('property image-upload error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
