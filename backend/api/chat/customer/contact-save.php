<?php
/**
 * 担当者が顧客詳細画面から、お客様の連絡先（顧客名・電話番号・メールアドレス）を修正する。
 * POST { session_id, customer_name, phone, email } -> { customer_name, phone, email }
 *
 * 入力間違いに気づいたときに担当者が直せるようにするための導線（2026/9/4 改善要望 1-3）。
 * ・更新するのは表示用の連絡先（chat_lead_contacts）と、ヒアリング情報（chat_leads）の
 *   氏名・電話・メールだけ。両方を揃えないと、画面の場所によって古い値が残ってしまう。
 * ・SMS認証の記録（chat_verified_phones）は書き換えない。ここは本人確認の根拠であり、
 *   担当者が入力した電話番号で他の端末が認証を通せてしまうため触ってはいけない。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';   // ensureChatLeadContactTable() / chatIntakeParseNameParts()
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php'; // agentMsgVerifyOwnedSession()
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim((string)($input['session_id'] ?? ''));
$customerName = trim((string)($input['customer_name'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));
$email = trim((string)($input['email'] ?? ''));

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if (mb_strlen($customerName) > 255) {
    sendErrorResponse('顧客名は255文字以内で入力してください', 400);
}
// 全角で入力されることがあるため半角へ寄せてから検証する。
$phone = $phone === '' ? '' : mb_convert_kana($phone, 'a');
if ($phone !== '' && !preg_match('/^[0-9+\-() 　]{6,50}$/u', $phone)) {
    sendErrorResponse('電話番号は数字・ハイフンで入力してください', 400);
}
if ($email !== '' && (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    sendErrorResponse('メールアドレスの形式が正しくありません', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // 自分の名刺のお客様だけを更新できる（他社・他担当の顧客は 404）。
    $session = agentMsgVerifyOwnedSession($db, $sessionId, (int)$userId);
    $businessCardId = (int)$session['business_card_id'];

    ensureChatLeadContactTable($db);

    // 表示用の連絡先。顧客名・電話・メール以外の列（連絡方法・LINE・同意など）は
    // お客様ご自身が登録した内容なので、ここでは触らずそのまま残す。
    $stmt = $db->prepare(
        "INSERT INTO chat_lead_contacts (session_id, business_card_id, customer_name, phone, email)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            customer_name = VALUES(customer_name),
            phone = VALUES(phone),
            email = VALUES(email),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        $sessionId,
        $businessCardId,
        $customerName !== '' ? $customerName : null,
        $phone !== '' ? $phone : null,
        $email !== '' ? $email : null,
    ]);

    // ヒアリング情報（chat_leads.structured_data）側の氏名・電話・メールも揃える。
    // ここを直さないと、AIの案内やヒアリング表示に修正前の値が残る。
    // ヒアリングがまだ無いお客様に、この操作で新しく作ることはしない。
    $stmt = $db->prepare('SELECT structured_data FROM chat_leads WHERE session_id = ? AND business_card_id = ? LIMIT 1');
    $stmt->execute([$sessionId, $businessCardId]);
    $rawStructured = $stmt->fetchColumn();
    if ($rawStructured !== false && $rawStructured !== null) {
        $structured = json_decode((string)$rawStructured, true);
        if (is_array($structured)) {
            $structured['customer_name'] = $customerName !== '' ? $customerName : null;
            // 姓・名は氏名から取り直す（元の姓名が残ると表示が食い違うため）。
            [$lastName, $firstName] = $customerName !== '' ? chatIntakeParseNameParts($customerName) : ['', ''];
            $structured['customer_last_name'] = $lastName !== '' ? $lastName : null;
            $structured['customer_first_name'] = $firstName !== '' ? $firstName : null;
            $structured['customer_phone'] = $phone !== '' ? $phone : null;
            $structured['customer_email'] = $email !== '' ? $email : null;
            $structured['_updated_at'] = date('c');

            $json = json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $stmt = $db->prepare('UPDATE chat_leads SET structured_data = ?, updated_at = CURRENT_TIMESTAMP WHERE session_id = ? AND business_card_id = ?');
                $stmt->execute([$json, $sessionId, $businessCardId]);
            }
        }
    }

    sendSuccessResponse([
        'session_id' => $sessionId,
        'customer_name' => $customerName,
        'phone' => $phone,
        'email' => $email,
    ], '顧客情報を更新しました');
} catch (Throwable $e) {
    error_log('customer contact save error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
