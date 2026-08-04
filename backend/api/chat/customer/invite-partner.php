<?php
/**
 * 顧客本人（primary）が「ご家族を招待」して、同じ案件に2人目を招く。
 * POST { session_id, visitor_id, email, name? }
 *   -> { invite_url, email, mail_sent, participant_count }
 *
 * 認証は send.php と同じ「SMS認証済み端末（device auth）」で行う。
 * 招待は "メール送信＋共有リンク" 方式（サーバーからのSMS自動送信は行わない）。
 * 2人目は返却された invite_url を開き、自分の電話番号でSMS認証して同じ案件へ合流する。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../../includes/session-participant-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$email = trim((string)($input['email'] ?? ''));
$name = trim((string)($input['name'] ?? ''));

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if ($visitorId === '' || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    sendErrorResponse('visitor_id is required', 400);
}
// 誤って @ を含む・含まないで入力されても許容する。
$email = ltrim($email, '@');
if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendErrorResponse('招待する方のメールアドレスを正しく入力してください', 400);
}
if (mb_strlen($name) > 50) {
    sendErrorResponse('お名前は50文字以内で入力してください', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    ensureChatDemoColumns($db);

    $stmt = $db->prepare("SELECT id, business_card_id, is_demo FROM chat_sessions WHERE id = ? LIMIT 1");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    $stmt = $db->prepare("SELECT * FROM business_cards WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$session['business_card_id']]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        sendErrorResponse('名刺が見つかりません', 404);
    }
    if (isDemoCard($card) && !empty($session['is_demo'])) {
        sendErrorResponse('体験版ではご家族の招待はご利用いただけません。', 403);
    }
    $cardSlug = (string)($card['url_slug'] ?? '');
    if ($cardSlug === '') {
        sendErrorResponse('名刺のURLが未発行のため、招待できません。', 409);
    }

    // 招待できるのはSMS認証済みの本人端末のみ（send.php と同じ device auth）。
    $deviceAuth = chatSessionDeviceAuth($db, $sessionId, $visitorId);
    if (!$deviceAuth) {
        sendErrorResponse('SMS認証の有効期限が切れています。もう一度SMS認証を行ってください。', 403);
    }

    $businessCardId = (int)$card['id'];

    // 本人（primary）の行を先に確定させる（端末の電話番号・お名前から）。
    $primaryPhone = trim((string)($deviceAuth['phone_normalized'] ?? ''));
    $primaryName = chatResolveCustomerNameForSession($db, $sessionId, $businessCardId);
    if ($primaryName === '') {
        $primaryName = chatCleanCustomerNameValue($deviceAuth['customer_name'] ?? '');
    }
    participantEnsurePrimary($db, $sessionId, $businessCardId, $primaryPhone, '', $primaryName, '');

    $result = participantCreatePartnerInvite($db, $sessionId, $businessCardId, $email, $name);
    if (!$result['ok']) {
        switch ($result['error']) {
            case 'partner_exists':
                sendErrorResponse('この案件にはすでにもうお一人が参加中です。1つの案件にご参加いただけるのは2名までです。', 409);
                break;
            case 'invalid_email':
                sendErrorResponse('招待する方のメールアドレスを正しく入力してください', 400);
                break;
            default:
                sendErrorResponse('招待の作成に失敗しました。時間をおいて再度お試しください。', 500);
        }
    }

    $inviteUrl = participantInviteUrl($cardSlug, $result['token']);
    $agentName = trim((string)($card['name'] ?? ''));
    $companyName = trim((string)($card['company_name'] ?? ''));
    $mailSent = participantSendInviteEmail($email, $primaryName, $agentName, $inviteUrl, $companyName);

    sendSuccessResponse([
        'invite_url' => $inviteUrl,
        'email' => $email,
        'mail_sent' => $mailSent,
        'participant_count' => participantCountActive($db, $sessionId),
    ], $mailSent
        ? 'ご家族へ招待メールを送信しました。届かない場合は下記のリンクを直接お伝えください。'
        : '招待を作成しました。メール送信に失敗したため、下記のリンクを直接お伝えください。');
} catch (Throwable $e) {
    error_log('invite-partner error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
