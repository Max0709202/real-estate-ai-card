<?php
/**
 * 物件画像の配信（認証付きプロキシ）。直リンク禁止。
 * GET ?id=<image_id>&session_id=&visitor_id=
 *  - 担当（ログイン＋名刺所有）または、当該物件のセッションの顧客のみ取得可能。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$imageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
if ($imageId <= 0) { http_response_code(400); echo 'bad request'; exit(); }

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare("
        SELECT pi.*, p.session_id AS prop_session, cs.visitor_identifier, bc.user_id
        FROM property_images pi
        JOIN properties p ON p.id = pi.property_id
        JOIN chat_sessions cs ON cs.id = p.session_id
        JOIN business_cards bc ON bc.id = pi.business_card_id
        WHERE pi.id = ? LIMIT 1
    ");
    $stmt->execute([$imageId]);
    $img = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$img) { http_response_code(404); echo 'not found'; exit(); }

    $authorized = false;
    startSessionIfNotStarted();
    if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$img['user_id']) {
        $authorized = true;
    }
    if (!$authorized && $sessionId !== '' && $sessionId === $img['prop_session']) {
        if (empty($img['visitor_identifier']) || $visitorId === '' || $img['visitor_identifier'] === $visitorId) {
            $authorized = true;
        }
    }
    if (!$authorized) { http_response_code(403); echo 'forbidden'; exit(); }

    $absPath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($img['stored_path'], '/');
    if (!is_file($absPath)) { http_response_code(404); echo 'file missing'; exit(); }

    $mime = $img['mime_type'] ?: 'application/octet-stream';
    $name = $img['original_name'] ?: ('file.' . pathinfo($absPath, PATHINFO_EXTENSION));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absPath));
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($absPath);
    exit();
} catch (Exception $e) {
    error_log('property image serve error: ' . $e->getMessage());
    http_response_code(500); echo 'server error'; exit();
}
