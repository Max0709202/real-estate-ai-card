<?php
/**
 * Get one chat session with messages and lead. My Page use.
 * GET ?id=session_id
 * Only allowed if session's business_card belongs to current user.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-rag-helper.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../includes/loan-simulation-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = requireAuth();

$sessionId = trim($_GET['id'] ?? '');

if ($sessionId === '') {
    sendErrorResponse('id (session_id) is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT cs.id, cs.business_card_id, cs.last_seen_at, cs.created_at,
               bc.name as card_holder_name
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ? AND bc.user_id = ?
    ");
    $stmt->execute([$sessionId, $userId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    $stmt = $db->prepare("SELECT id, role, message, created_at FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC");
    $stmt->execute([$sessionId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT structured_data, consent_given, created_at, updated_at FROM chat_leads WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lead && !empty($lead['structured_data'])) {
        $lead['structured_data'] = json_decode($lead['structured_data'], true);
        if (is_array($lead['structured_data'])) {
            $lead['classified_data'] = chatIntakeClassifiedLeadItems($lead['structured_data']);
        }
    }

    $stmt = $db->prepare("SELECT memory_json, last_summary, updated_at FROM chat_session_memory WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $memory = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($memory && !empty($memory['memory_json'])) {
        $memory['memory_json'] = json_decode($memory['memory_json'], true);
    }

    ensureChatLeadContactTable($db);
    $stmt = $db->prepare("SELECT customer_name, contact_method, contact_value, phone, email, line_id, raw_contact, consent_given, created_at, updated_at FROM chat_lead_contacts WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $loanSimulation = loanSimulationFetchForSession($db, $sessionId, (int)$session["business_card_id"]);
    $loanSimulationData = null;
    if ($loanSimulation && loanSimulationHasDisplayValues($loanSimulation)) {
        $loanSimulationData = [
            "updated_at" => $loanSimulation["updated_at"] ?? null,
            "summary" => loanSimulationDisplaySummary($loanSimulation, 8),
            "groups" => loanSimulationDisplayGroups($loanSimulation),
        ];
    }

    $data = [
        'session' => $session,
        'messages' => $messages,
        'lead' => $lead,
        'memory' => $memory,
        'contact' => $contact,
        'loan_simulation' => $loanSimulationData,
        'registered_phones' => chatRegisteredPhonesForCard($db, (int)$session['business_card_id'], 100),
    ];
    sendSuccessResponse($data, 'OK');
} catch (Exception $e) {
    error_log('Chat session detail error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
