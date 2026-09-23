<?php
/**
 * 内見日程調整: 鍵の受け渡しに添えた写真・資料。
 * アップロード  POST multipart { t=<売主用トークン>, file }
 * 閲覧         GET ?id=<添付ID>&t=<売主用トークン>  /  GET ?id=<添付ID>（担当ログイン）
 *
 * 買主には公開しない。買主用のトークンでは参照できない。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/upload_security.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/viewing-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

try {
    $db = (new Database())->getConnection();
    viewingEnsureTables($db);
    viewingAttachmentEnsureTable($db);

    /* ---- アップロード（売主仲介会社が回答画面から添付する） ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        $ref = viewingTokenLookup($db, trim((string)($_POST['t'] ?? '')));
        if ($ref === null || $ref['audience'] !== 'seller') sendErrorResponse('このURLは無効です', 403);
        $case = viewingLoad($db, $ref['viewing_id']);
        if (!$case || !viewingIsOpen($case)) sendErrorResponse('この内見は受け付けを終了しています', 409);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) sendErrorResponse('ファイルがありません', 400);

        $r = viewingStoreAttachment($db, $_FILES['file'], (int)$case['id'], 'seller');
        if (!empty($r['error'])) sendErrorResponse($r['error'], 400);
        viewingLogEvent($db, (int)$case['id'], 'attachment_added', ['actor' => 'seller']);
        sendSuccessResponse(['attachments' => viewingAttachments($db, (int)$case['id'])], '添付しました');
    }

    /* ---- 閲覧 ---- */
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) { http_response_code(400); exit(); }
    $stmt = $db->prepare("SELECT a.*, v.property_id FROM property_viewing_attachments a
                          JOIN property_viewings v ON v.id = a.viewing_id WHERE a.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit(); }

    $token = trim((string)($_GET['t'] ?? ''));
    if ($token !== '') {
        $ref = viewingTokenLookup($db, $token);
        if ($ref === null || $ref['audience'] !== 'seller' || $ref['viewing_id'] !== (int)$row['viewing_id']) {
            http_response_code(403); exit();
        }
    } else {
        startSessionIfNotStarted();
        $userId = requireAuth();
        propertyVerifyAgentProperty($db, (int)$row['property_id'], $userId);
    }

    $path = rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$row['stored_path'], '/');
    if (!is_file($path)) { http_response_code(404); exit(); }
    header('Content-Type: ' . ((string)$row['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode((string)$row['original_name']) . '"');
    header('Cache-Control: private, max-age=300');
    readfile($path);
} catch (Exception $e) {
    error_log('viewing-attachment error: ' . $e->getMessage());
    http_response_code(500);
}
