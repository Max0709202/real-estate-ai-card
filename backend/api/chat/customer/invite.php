<?php
/**
 * エージェントが顧客ページ（AIエージェントページ）を事前に作成し、専用URLをメールで送る。
 * POST { business_card_id?, last_name, first_name, email_local, email_domain }
 *   -> { session_id, invite_url, customer_name, email }
 *
 * 顧客側のSMS認証を待たずに chat_sessions を先に作るため、エージェントは
 * 送信直後から「チャット履歴・顧客一覧」でその顧客を確認できる。
 * 入力された氏名・メールは chat_lead_contacts へは書かない（顧客本人の登録が正）。
 * 詳細は includes/customer-invitation-helper.php の冒頭コメントを参照。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/customer-invitation-helper.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$cardId = isset($input['business_card_id']) ? (int)$input['business_card_id'] : 0;
$lastName = trim((string)($input['last_name'] ?? ''));
$firstName = trim((string)($input['first_name'] ?? ''));
$emailLocal = trim((string)($input['email_local'] ?? ''));
$emailDomain = trim((string)($input['email_domain'] ?? ''));

if ($lastName === '' || $firstName === '') {
    sendErrorResponse('お客様のお名前（姓・名）を入力してください', 400);
}
if (mb_strlen($lastName) > 50 || mb_strlen($firstName) > 50) {
    sendErrorResponse('お名前は姓・名それぞれ50文字以内で入力してください', 400);
}
if ($emailLocal === '' || $emailDomain === '') {
    sendErrorResponse('メールアドレスを入力してください', 400);
}

// 「＠より前」「＠より後」を分けて受け取るため、結合してから検証する。
// 利用者が誤って @ を含めて入力した場合に二重にならないよう取り除く。
$email = ltrim($emailLocal, '@') . '@' . ltrim($emailDomain, '@');
if (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendErrorResponse('メールアドレスの形式が正しくありません', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // 自分の名刺だけを対象にする。未指定なら sessions.php と同じ「最初の1枚」。
    if ($cardId > 0) {
        $stmt = $db->prepare('SELECT * FROM business_cards WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$cardId, $userId]);
    } else {
        $stmt = $db->prepare('SELECT * FROM business_cards WHERE user_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$userId]);
    }
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        sendErrorResponse('名刺が見つかりません', 404);
    }
    if (!canUseChatbot($card)) {
        sendErrorResponse('この名刺ではAIエージェントをご利用いただけません。', 403);
    }
    $cardSlug = (string)($card['url_slug'] ?? '');
    if ($cardSlug === '') {
        sendErrorResponse('名刺のURLが未発行のため、顧客ページを作成できません。', 409);
    }

    $customerName = customerInviteFullName($lastName, $firstName);
    $agentName = trim((string)($card['name'] ?? ''));
    // 誰からのメールか分かるよう、エージェント名の前に名刺の社名を記載する。
    $companyName = trim((string)($card['company_name'] ?? ''));

    customerInviteEnsureTable($db);

    // 同じお客様へ何度も専用ページを作らない。二重送信や押し間違いで、
    // 中身の無い顧客ページが顧客一覧に並び、お客様にも同じ案内が何通も届くのを防ぐ。
    // お客様のご登録が済んだもの（registered）は対象外＝改めて案内を送れる。
    $stmt = $db->prepare(
        "SELECT invite_token FROM chat_customer_invitations
         WHERE business_card_id = ? AND email = ? AND status <> 'registered'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([(int)$card['id'], $email]);
    $existingToken = (string)($stmt->fetchColumn() ?: '');
    if ($existingToken !== '') {
        sendSuccessResponse([
            'session_id' => '',
            'invite_url' => customerInviteUrl($cardSlug, $existingToken),
            'customer_name' => $customerName,
            'email' => $email,
            'mail_sent' => false,
            'already_exists' => true,
        ], 'このメールアドレス宛の顧客ページは作成済みです。お客様がまだご登録されていないため、下記の専用URLをそのままご案内ください。');
    }

    // 顧客ページの実体＝チャットセッション。訪問者はまだ未確定なので
    // visitor_identifier は NULL のままにし、顧客が専用URLを開いた時に紐づける。
    $sessionId = generateChatSessionId();
    $token = customerInviteGenerateToken();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO chat_sessions (id, business_card_id, visitor_identifier) VALUES (?, ?, NULL)');
        $stmt->execute([$sessionId, (int)$card['id']]);

        $stmt = $db->prepare(
            'INSERT INTO chat_customer_invitations
                (session_id, business_card_id, invite_token, last_name, first_name, email, status, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, \'sent\', NOW())'
        );
        $stmt->execute([$sessionId, (int)$card['id'], $token, $lastName, $firstName, $email]);
        $invitationId = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $inviteUrl = customerInviteUrl($cardSlug, $token);
    $mailSent = customerInviteSendEmail($email, $agentName, $customerName, $inviteUrl, $invitationId, $companyName);

    sendSuccessResponse([
        'session_id' => $sessionId,
        'invite_url' => $inviteUrl,
        'customer_name' => $customerName,
        'email' => $email,
        'mail_sent' => $mailSent,
    ], $mailSent
        ? 'お客様へ専用ページのご案内メールを送信しました'
        : '顧客ページを作成しましたが、メール送信に失敗しました。専用URLを直接お伝えください。');
} catch (Throwable $e) {
    // Exception だけを捕まえると、未定義関数などの Error がそのまま致命的エラーになり
    // JSON が返らない（画面側は「送信に失敗しました」すら出せない）ため Throwable で受ける。
    error_log('Customer invite error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
