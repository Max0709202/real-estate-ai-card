<?php
/**
 * 案件の参加者一覧の取得と、2人目（partner）の参加解除。
 *   GET  ?session_id=&visitor_id=            -> { participants:[...], can_invite, self_role }
 *   POST { session_id, visitor_id, action:'remove' } -> 2人目の参加を解除（primary のみ）
 *
 * 認証は send.php と同じ SMS認証済み端末（device auth）で行う。
 * 参加解除は本人（primary）だけが実行できる。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../../includes/session-participant-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $sessionId = trim($input['session_id'] ?? '');
    $visitorId = trim($input['visitor_id'] ?? '');
    $action = trim((string)($input['action'] ?? ''));
} else {
    $sessionId = trim($_GET['session_id'] ?? '');
    $visitorId = trim($_GET['visitor_id'] ?? '');
    $action = '';
}

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if ($visitorId === '' || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    sendErrorResponse('visitor_id is required', 400);
}

/**
 * 参加者一覧を、機微情報を落として整形する（氏名・ロール・状態・自分かどうか）。
 * メール・電話番号はそのまま返さず、マスクした表示だけを返す。
 */
function participantsPublicList(PDO $db, string $sessionId, string $selfPhone): array
{
    $rows = participantListActive($db, $sessionId);
    $out = [];
    foreach ($rows as $p) {
        $phone = trim((string)($p['phone_normalized'] ?? ''));
        $email = trim((string)($p['email'] ?? ''));
        $out[] = [
            'id' => (int)$p['id'],
            'role' => (string)$p['role'],
            'status' => (string)$p['status'],
            'display_name' => chatCleanCustomerNameValue($p['display_name'] ?? ''),
            'email_masked' => $email !== '' ? participantsMaskEmail($email) : '',
            'is_self' => ($selfPhone !== '' && $phone !== '' && $phone === $selfPhone),
        ];
    }
    return $out;
}

/** メールアドレスを a***@example.com のようにマスクする。 */
function participantsMaskEmail(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false) return '';
    $local = substr($email, 0, $at);
    $domain = substr($email, $at);
    $head = mb_substr($local, 0, 1);
    return $head . str_repeat('*', max(1, mb_strlen($local) - 1)) . $domain;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // SMS認証済み端末のみ（send.php と同じ device auth）。
    $deviceAuth = chatSessionDeviceAuth($db, $sessionId, $visitorId);
    if (!$deviceAuth) {
        sendErrorResponse('SMS認証の有効期限が切れています。もう一度SMS認証を行ってください。', 403);
    }
    $selfPhone = trim((string)($deviceAuth['phone_normalized'] ?? ''));

    // 操作している端末が primary か partner かを判定する。
    $selfRole = '';
    if ($selfPhone !== '') {
        $stmt = $db->prepare("SELECT role FROM chat_session_participants WHERE session_id = ? AND phone_normalized = ? AND status <> 'removed' LIMIT 1");
        $stmt->execute([$sessionId, $selfPhone]);
        $selfRole = (string)($stmt->fetchColumn() ?: '');
    }

    if ($method === 'POST' && $action === 'remove') {
        if ($selfRole !== 'primary') {
            sendErrorResponse('参加の解除は、最初にご登録された方のみ行えます。', 403);
        }
        $ok = participantRemovePartner($db, $sessionId);
        sendSuccessResponse([
            'removed' => $ok,
            'participants' => participantsPublicList($db, $sessionId, $selfPhone),
            'self_role' => $selfRole,
        ], $ok ? 'ご家族の参加を解除しました。' : '解除できる参加者が見つかりませんでした。');
    }

    $participants = participantsPublicList($db, $sessionId, $selfPhone);
    $hasPartner = false;
    foreach ($participants as $p) {
        if ($p['role'] === 'partner') { $hasPartner = true; break; }
    }
    sendSuccessResponse([
        'participants' => $participants,
        'self_role' => $selfRole,
        // 招待できるのは本人（primary）で、まだ2人目がいない場合のみ。
        'can_invite' => ($selfRole === 'primary' || $selfRole === '') && !$hasPartner,
    ], 'OK');
} catch (Throwable $e) {
    error_log('participants error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
