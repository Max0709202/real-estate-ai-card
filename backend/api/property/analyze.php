<?php
/**
 * 物件選定: 販売図面の自動解析（§7 OCR）→ 物件をドラフト登録（§2① / §8）。
 * 担当が販売図面（画像/PDF）をアップロードすると、AIで情報を抽出し
 * ocr_status='draft' で物件を作成。販売図面は flyer 画像として保存する。
 * multipart/form-data: session_id, files[]（または file）
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

$sessionId = trim($_POST['session_id'] ?? '');
if ($sessionId === '') sendErrorResponse('session_id is required', 400);

// $_FILES を正規化（files[] / file の両対応）
$files = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $n = count($_FILES['files']['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $files[] = [
            'name' => $_FILES['files']['name'][$i],
            'type' => $_FILES['files']['type'][$i],
            'tmp_name' => $_FILES['files']['tmp_name'][$i],
            'error' => $_FILES['files']['error'][$i],
            'size' => $_FILES['files']['size'][$i],
        ];
    }
} elseif (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
    $files[] = $_FILES['file'];
}
if (!$files) sendErrorResponse('販売図面ファイルがありません', 400);
if (count($files) > 4) $files = array_slice($files, 0, 4);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);
    $cardId = propertyVerifyAgentSession($db, $sessionId, $userId);

    // ドラフト物件を作成（後で画像保存・フィールド適用）
    $propertyId = propertyCreate($db, [
        'business_card_id' => $cardId,
        'session_id' => $sessionId,
        'source' => 'agent',
        'source_media' => 'flyer',
        'created_by' => 'agent',
        'ocr_status' => 'draft',
    ]);

    // 販売図面を flyer として保存
    $stored = [];
    foreach ($files as $f) {
        $r = propertyStoreUploadedFile($db, $f, $propertyId, $cardId, 'flyer');
        if (empty($r['error'])) $stored[] = $r;
    }
    if (!$stored) {
        $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$propertyId]);
        sendErrorResponse('ファイルを保存できませんでした（画像/PDFのみ）', 400);
    }

    // OCR対象の画像パスを用意（画像はそのまま、PDFは先頭ページをラスタライズ）
    $ocrPaths = [];
    $tmpToClean = [];
    foreach ($stored as $r) {
        if (!empty($r['is_image'])) {
            $ocrPaths[] = $r['abs_path'];
        } elseif (!empty($r['is_pdf'])) {
            $tmp = propertyRasterizePdfFirstPage($r['abs_path']);
            if ($tmp) { $ocrPaths[] = $tmp; $tmpToClean[] = $tmp; }
        }
    }

    $ocrError = null;
    if ($ocrPaths) {
        $ocr = propertyExtractFromImages($ocrPaths, ['session_id' => $sessionId, 'business_card_id' => $cardId]);
        foreach ($tmpToClean as $t) @unlink($t);
        if (!empty($ocr['fields'])) {
            propertyApplyFields($db, $propertyId, $ocr['fields']);
        }
        $ocrError = $ocr['error'] ?? null;
    } else {
        $ocrError = 'PDFを画像化できなかったため自動抽出をスキップしました。手入力で編集してください。';
    }

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    sendSuccessResponse([
        'property' => propertySerialize($db, $row, true, true),
        'ocr_error' => $ocrError,
    ], $ocrError ? '保存しました（自動抽出に一部失敗）' : 'AIが情報を読み取りました');
} catch (Exception $e) {
    error_log('property analyze error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
