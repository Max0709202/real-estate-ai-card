<?php
/**
 * 物件画像の配信（認証付きプロキシ）。直リンク禁止。
 * GET ?id=<image_id>&session_id=&visitor_id=&variant=original|preview|masked
 *  - 担当（ログイン＋名刺所有）: 既定で原本。variant=preview/masked も取得可。
 *  - 顧客: 販売図面はマスク確定済（masked）のみ取得可能（売主情報の自動非表示）。写真等は原本。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$imageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
$variant = trim($_GET['variant'] ?? '');
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

    $isAgent = false;
    $isCustomer = false;
    startSessionIfNotStarted();
    if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$img['user_id']) {
        $isAgent = true;
    }
    if (!$isAgent && $sessionId !== '' && $sessionId === $img['prop_session']) {
        if (empty($img['visitor_identifier']) || $visitorId === '' || $img['visitor_identifier'] === $visitorId) {
            $isCustomer = true;
        }
    }
    if (!$isAgent && !$isCustomer) { http_response_code(403); echo 'forbidden'; exit(); }

    $isFlyer = ($img['category'] ?? '') === 'flyer';

    // 配信するファイルと MIME を決定する。
    $relPath = $img['stored_path'];
    $mime = $img['mime_type'] ?: 'application/octet-stream';

    if ($isCustomer && $isFlyer) {
        // 顧客には売主情報をマスク済の販売図面（PDF）のみ。未確定なら配信しない。
        if (($img['mask_status'] ?? 'none') !== 'masked' || empty($img['masked_path'])) {
            http_response_code(403); echo 'forbidden'; exit();
        }
        $relPath = $img['masked_path'];
        $mime = 'application/pdf';
    } elseif ($isAgent && $variant === 'masked' && !empty($img['masked_path'])) {
        $relPath = $img['masked_path'];
        $mime = 'application/pdf';
    } elseif ($variant === 'preview' && !empty($img['preview_path'])) {
        // プレビュー（ラスタJPEG）は担当のマスク編集用。顧客には許可しない。
        if (!$isAgent) { http_response_code(403); echo 'forbidden'; exit(); }
        $relPath = $img['preview_path'];
        $mime = 'image/jpeg';
    }

    $absPath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($relPath, '/');
    if (!is_file($absPath)) { http_response_code(404); echo 'file missing'; exit(); }

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
