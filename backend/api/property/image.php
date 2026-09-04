<?php
/**
 * 物件画像の配信（認証付きプロキシ）。直リンク禁止。
 * GET ?id=<image_id>&session_id=&visitor_id=&variant=original|preview|masked
 * GET ?id=<image_id>&view_token=（物件提案メールのリンクから来た未認証の閲覧）
 *  - 担当（ログイン＋名刺所有）とその上長（統括・店長／閲覧のみ）: 既定で原本。variant=preview/masked も取得可。
 *  - 顧客: 販売図面はマスク確定済（masked）のみ取得可能（売主情報の自動非表示）。写真等は原本。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';

$imageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
$viewToken = trim($_GET['view_token'] ?? '');
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
    // 上長（統括（全閲覧）・マネージャー（店長））が「組織・配下顧客」から閲覧している場合。
    // 社内の方なので担当者と同じ扱い（原本）で通す。閲覧範囲内の担当者の物件だけが対象。
    if (!$isAgent && !empty($_SESSION['user_id'])) {
        require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
        if (orgCanViewMemberCustomers($db, (int)$_SESSION['user_id'], (int)$img['user_id'])) {
            $isAgent = true;
        }
    }

    // 同一電話番号でSMS認証済みの別端末も許可する（複数端末での物件画像共有）。
    if (!$isAgent && $sessionId !== '' && $sessionId === $img['prop_session']) {
        if (chatSessionVisitorAuthorized($db, $img['prop_session'], $visitorId, $img['visitor_identifier'])) {
            $isCustomer = true;
        }
    }
    // 物件提案メールのリンクから来た未認証の閲覧。トークンを発行した顧客の物件画像のみ許可。
    // 顧客扱いなので、販売図面は下でマスク確定済（customer_visible=1）だけが配信される。
    if (!$isAgent && !$isCustomer && $viewToken !== ''
        && propertyViewTokenSession($db, $viewToken) === (string)$img['prop_session']) {
        $isCustomer = true;
    }
    if (!$isAgent && !$isCustomer) { http_response_code(403); echo 'forbidden'; exit(); }

    $isFlyer = ($img['category'] ?? '') === 'flyer';

    // 配信するファイルと MIME を決定する。
    $relPath = $img['stored_path'];
    $mime = $img['mime_type'] ?: 'application/octet-stream';

    if ($isCustomer && $isFlyer) {
        // 顧客には、担当が編集・確認を完了して公開した（customer_visible=1）販売図面のみ配信する。
        // 編集未完了は配信しない（売主情報の漏えい防止）。マスク済PDF または マスク済サムネイル画像。
        if (($img['mask_status'] ?? 'none') !== 'masked' || (int)($img['customer_visible'] ?? 0) !== 1) {
            http_response_code(403); echo 'forbidden'; exit();
        }
        if ($variant === 'masked_thumb') {
            $thumb = $img['masked_thumb_path'] ?? null;
            if (empty($thumb) && !empty($img['masked_path'])) {
                $thumb = propertyMaskedThumbEnsure($db, $imageId, $img['masked_path'], (int)$img['business_card_id'], (int)$img['property_id']);
            }
            if (empty($thumb)) { http_response_code(404); echo 'no thumb'; exit(); }
            $relPath = $thumb; $mime = 'image/jpeg';
        } elseif (!empty($img['masked_path'])) {
            $relPath = $img['masked_path'];
            $mime = 'application/pdf';
        } else {
            http_response_code(403); echo 'forbidden'; exit();
        }
    } elseif ($isAgent && $variant === 'masked_thumb') {
        $thumb = $img['masked_thumb_path'] ?? null;
        if (empty($thumb) && !empty($img['masked_path'])) {
            $thumb = propertyMaskedThumbEnsure($db, $imageId, $img['masked_path'], (int)$img['business_card_id'], (int)$img['property_id']);
        }
        if (empty($thumb)) { http_response_code(404); echo 'no thumb'; exit(); }
        $relPath = $thumb; $mime = 'image/jpeg';
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
