<?php
/**
 * 添付ファイルの配信（認証付きプロキシ）。直リンク禁止。
 * GET ?id=<attachment_id>&session_id=&visitor_id=
 * 担当（ログイン＋名刺所有）または、当該セッションの顧客（session_id+visitor_id）のみ取得可能。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';

$attachmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
if ($attachmentId <= 0) {
    http_response_code(400); echo 'bad request'; exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT a.*, cs.visitor_identifier, bc.user_id
        FROM chat_message_attachments a
        JOIN chat_sessions cs ON cs.id = a.session_id
        JOIN business_cards bc ON bc.id = a.business_card_id
        WHERE a.id = ? LIMIT 1
    ");
    $stmt->execute([$attachmentId]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$att) { http_response_code(404); echo 'not found'; exit(); }

    // 認可: 担当（所有者）か、当該セッションの顧客か
    $authorized = false;

    // 担当としてのログインを確認
    startSessionIfNotStarted();
    if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$att['user_id']) {
        $authorized = true;
    }

    // 顧客としてのアクセス（session_id 一致＋（登録済みなら）visitor_id 一致）。
    // 同一電話番号でSMS認証済みの別端末も許可する（複数端末での添付共有）。
    if (!$authorized && $sessionId !== '' && $sessionId === $att['session_id']) {
        if (chatSessionVisitorAuthorized($db, $att['session_id'], $visitorId, $att['visitor_identifier'])) {
            $authorized = true;
        }
    }

    if (!$authorized) { http_response_code(403); echo 'forbidden'; exit(); }

    $absPath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($att['stored_path'], '/');
    if (!is_file($absPath)) { http_response_code(404); echo 'file missing'; exit(); }

    $mime = $att['mime_type'] ?: 'application/octet-stream';
    $isInline = (strpos($mime, 'image/') === 0) || $mime === 'application/pdf';
    $disposition = $isInline ? 'inline' : 'attachment';
    $name = $att['original_name'] ?: ('file.' . pathinfo($absPath, PATHINFO_EXTENSION));

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absPath));
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($absPath);
    exit();
} catch (Exception $e) {
    error_log('chat attachment download error: ' . $e->getMessage());
    http_response_code(500); echo 'server error'; exit();
}
