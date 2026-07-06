<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../../includes/chat-rag-helper.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../../includes/notification-helper.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
// 兄弟の crm/save.php と同様、クロスオリジン（apex⇄www 等）でもブロックされないようにする。
// 主要な修正は card.php 側の同一オリジン化だが、旧キャッシュのページが別ホストを呼んでも
// 壊れないよう、ここでも許可ヘッダを付ける。session_id はクエリ渡しで Cookie 非依存。
header('Access-Control-Allow-Origin: *');

startSessionIfNotStarted();

$sessionId = trim($_GET['session_id'] ?? $_GET['id'] ?? '');
if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT cs.id, cs.business_card_id, bc.user_id, bc.name AS card_holder_name, bc.company_name, bc.url_slug, bc.profile_photo
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ?
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    // 担当営業（名刺所有者）が日程調整画面を開いた → メール通知の未読解除。
    if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$session['user_id']) {
        notifyMarkRead($db, $sessionId, 'schedule');
    }

    $case = chatCrmLoadCase($db, $sessionId, (int)$session['business_card_id']);
    if (!$case) {
        $case = chatCrmDefaultCase();
        $case['customer_name'] = chatResolveCustomerNameForSession($db, $sessionId, (int)$session['business_card_id']) ?: '';
        chatCrmUpsertCase($db, $sessionId, (int)$session['business_card_id'], $case);
        $case = chatCrmLoadCase($db, $sessionId, (int)$session['business_card_id']);
    }

    $case['conditions_summary'] = chatCrmSummarizeConditions($case);
    $case['purchase_schedule'] = chatCrmCalculatePurchaseStages($case['progress']['target_date'] ?? null, $case['progress']['manual_overrides'] ?? []);
    $case['sale_schedule'] = chatCrmCalculateSaleStages($case['progress']['target_date'] ?? null, $case['progress']['manual_overrides'] ?? []);

    $case['tools'] = chatCrmLoadToolsForCard($db, (int)$session['business_card_id']);

    sendSuccessResponse([
        'session' => $session,
        'case' => $case,
        'can_use_chatbot' => canUseChatbot($session),
        'can_use_loan_sim' => canUseLoanSim($session),
    ], 'OK');
} catch (Exception $e) {
    error_log('chat crm get error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
