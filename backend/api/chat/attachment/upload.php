<?php
/**
 * 添付ファイルのアップロード（担当・顧客 共用）。
 * multipart/form-data: session_id, visitor_id?, uploaded_by(customer|agent), file
 * 画像は長辺2,000px以内へ自動リサイズ＋圧縮。PDF/Word/Excel は検疫後そのまま保存。
 * メッセージ確定前の仮登録（message_id=NULL）。後続の send で紐付ける。
 * -> { attachment_id, kind, original_name, url, is_image, width, height }
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/upload_security.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendErrorResponse('Method not allowed', 405); }

$sessionId = trim($_POST['session_id'] ?? '');
$visitorId = trim($_POST['visitor_id'] ?? '');
$uploadedBy = trim($_POST['uploaded_by'] ?? 'customer');
if ($uploadedBy !== 'agent') { $uploadedBy = 'customer'; }

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
    sendErrorResponse('ファイルが選択されていません', 400);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    sendErrorResponse('アップロードに失敗しました', 400);
}
if (($file['size'] ?? 0) > MAX_FILE_SIZE) {
    sendErrorResponse('ファイルサイズが大きすぎます（最大10MB）', 400);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!upload_security_check_rate_limit($ip)) {
    sendErrorResponse('アップロード回数が多すぎます。しばらくお待ちください。', 429);
}

$nameError = upload_security_validate_client_filename($file['name'] ?? '');
if ($nameError !== null) {
    sendErrorResponse($nameError, 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // セッションと所有・本人検証
    $stmt = $db->prepare("
        SELECT cs.id, cs.business_card_id, cs.visitor_identifier, bc.user_id
        FROM chat_sessions cs JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ? LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    if ($uploadedBy === 'agent') {
        require_once __DIR__ . '/../../middleware/auth.php';
        startSessionIfNotStarted();
        $userId = requireAuth();
        if ((int)$session['user_id'] !== (int)$userId) {
            sendErrorResponse('権限がありません', 403);
        }
    } else {
        if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
            $visitorId = '';
        }
        // 同一電話番号でSMS認証済みの別端末も許可する（複数端末での添付共有）。
        if (!chatSessionVisitorAuthorized($db, $sessionId, $visitorId, $session['visitor_identifier'])) {
            sendErrorResponse('セッションを確認できません', 403);
        }
    }

    $businessCardId = (int)$session['business_card_id'];

    // MIME検出（内容ベース）
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $detectedMime = $finfo ? (finfo_file($finfo, $file['tmp_name']) ?: '') : ($file['type'] ?? '');
    if ($finfo) finfo_close($finfo);
    $detectedMime = strtolower(trim($detectedMime));

    $kind = agentMsgKindFromMime($detectedMime, $file['name'] ?? '');

    // 動画ファイルは保存しない（現時点では非対応・保存容量節約のため）。MIMEと拡張子の両面で明示的にブロックする。
    $videoExts = ['mp4','mov','m4v','avi','wmv','flv','mkv','webm','3gp','3g2','mpeg','mpg','ts','m2ts','ogv'];
    $uploadExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (strpos($detectedMime, 'video/') === 0 || in_array($uploadExt, $videoExts, true)) {
        sendErrorResponse('動画ファイルは保存できません。画像・PDF・Word・Excelのみご利用いただけます。', 400);
    }

    // 許可判定
    $allowedDocMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // Office (docx/xlsx) は zip コンテナとして検出されることがある
        'application/zip', 'application/octet-stream', 'application/x-ole-storage',
    ];
    $isImage = in_array($detectedMime, ALLOWED_IMAGE_TYPES, true);
    if (!$isImage && !in_array($detectedMime, $allowedDocMimes, true)) {
        sendErrorResponse('対応していないファイル形式です（画像/PDF/Word/Excelのみ）', 400);
    }
    // 拡張子ホワイトリスト（zip/octet-stream は Office 拡張子のみ許可）
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExts = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx'];
    if (!in_array($ext, $allowedExts, true)) {
        sendErrorResponse('対応していない拡張子です', 400);
    }

    // 保存先ディレクトリ
    $relDir = 'chat/' . $businessCardId . '/' . $sessionId;
    $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
    if (!is_dir($absDir)) {
        if (!@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            sendErrorResponse('保存先を作成できませんでした', 500);
        }
    }

    // サーバ生成のファイル名
    $safeExt = $isImage ? (upload_security_mime_to_extension($detectedMime) ?: $ext) : $ext;
    $stored = bin2hex(random_bytes(16)) . '.' . $safeExt;
    $absPath = $absDir . '/' . $stored;
    $relPath = $relDir . '/' . $stored;

    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        sendErrorResponse('ファイルの保存に失敗しました', 500);
    }

    // ウイルススキャン（有効時）
    $scan = upload_security_clamav_scan($absPath);
    if (empty($scan['ok'])) {
        @unlink($absPath);
        sendErrorResponse(!empty($scan['infected']) ? 'ファイルから脅威が検出されました。' : 'ウイルススキャンに失敗しました。', 400);
    }

    $width = null; $height = null;
    if ($isImage) {
        $dimCheck = upload_security_validate_image_dimensions($absPath, $detectedMime);
        if (empty($dimCheck['ok'])) {
            @unlink($absPath);
            sendErrorResponse($dimCheck['message'] ?? '画像を確認できませんでした。', 400);
        }
        // 長辺2,000px以内へリサイズ＋圧縮（メタデータも除去）
        $resized = chatResizeImage($absPath, $detectedMime, 2000, 85);
        $width = $resized['width'] ?? null;
        $height = $resized['height'] ?? null;
    }

    $byteSize = filesize($absPath) ?: (int)$file['size'];

    $stmt = $db->prepare("
        INSERT INTO chat_message_attachments
            (message_id, session_id, business_card_id, uploaded_by, kind, original_name, stored_path, mime_type, byte_size, width, height)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $origName = mb_substr(basename(str_replace('\\', '/', $file['name'] ?? 'file')), 0, 200);
    $stmt->execute([$sessionId, $businessCardId, $uploadedBy, $kind, $origName, $relPath, $detectedMime, $byteSize, $width, $height]);
    $attachmentId = (int)$db->lastInsertId();

    upload_security_log_event('chat_attachment_uploaded', ['attachment_id' => $attachmentId, 'session' => $sessionId, 'kind' => $kind, 'by' => $uploadedBy]);

    sendSuccessResponse([
        'attachment_id' => $attachmentId,
        'kind' => $kind,
        'original_name' => $origName,
        'url' => API_BASE_URL . '/chat/attachment/download.php?id=' . $attachmentId,
        'is_image' => $isImage ? 1 : 0,
        'width' => $width,
        'height' => $height,
        'byte_size' => $byteSize,
    ], 'OK');
} catch (Exception $e) {
    error_log('chat attachment upload error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 画像を長辺 $maxEdge px 以内へリサイズし、メタデータを除去して上書き保存する。
 * Imagick があれば優先、なければ GD。返り値に最終 width/height。
 */
function chatResizeImage(string $path, string $mime, int $maxEdge, int $quality): array
{
    $info = @getimagesize($path);
    if ($info === false) return ['width' => null, 'height' => null];
    $w = (int)$info[0]; $h = (int)$info[1];

    // Imagick
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick($path);
            $img->setImageOrientation($img->getImageOrientation());
            if (method_exists($img, 'autoOrient')) { @$img->autoOrient(); }
            if ($w > $maxEdge || $h > $maxEdge) {
                $img->resizeImage($w >= $h ? $maxEdge : 0, $w >= $h ? 0 : $maxEdge, Imagick::FILTER_LANCZOS, 1);
            }
            $img->stripImage();
            if ($mime === 'image/jpeg' || $mime === 'image/webp') {
                $img->setImageCompressionQuality($quality);
            }
            $img->writeImage($path);
            $nw = $img->getImageWidth(); $nh = $img->getImageHeight();
            $img->clear(); $img->destroy();
            return ['width' => $nw, 'height' => $nh];
        } catch (Throwable $e) {
            // GD へフォールバック
        }
    }

    // GD
    if (!function_exists('imagecreatetruecolor')) {
        return ['width' => $w, 'height' => $h];
    }
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
        case 'image/png':  $src = @imagecreatefrompng($path); break;
        case 'image/gif':  $src = @imagecreatefromgif($path); break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        default: $src = false;
    }
    if (!$src) return ['width' => $w, 'height' => $h];

    $scale = ($w > $maxEdge || $h > $maxEdge) ? ($maxEdge / max($w, $h)) : 1.0;
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    switch ($mime) {
        case 'image/jpeg': imagejpeg($dst, $path, $quality); break;
        case 'image/png':  imagepng($dst, $path, 6); break;
        case 'image/gif':  imagegif($dst, $path); break;
        case 'image/webp': if (function_exists('imagewebp')) imagewebp($dst, $path, $quality); break;
    }
    imagedestroy($src);
    imagedestroy($dst);
    return ['width' => $nw, 'height' => $nh];
}
