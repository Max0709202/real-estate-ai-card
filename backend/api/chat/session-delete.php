<?php
/**
 * Delete one chat session for the current user's business card. My Page use.
 * POST { "id": "session_id" }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../includes/loan-simulation-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$sessionId = trim($input['id'] ?? $input['session_id'] ?? '');

if ($sessionId === '') {
    sendErrorResponse('id (session_id) is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT cs.id, cs.business_card_id
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ? AND bc.user_id = ?");
    $stmt->execute([$sessionId, $userId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    $businessCardId = (int)$session['business_card_id'];

    ensureChatVerifiedPhonesTable($db);
    ensureLoanSimulationInputsTable($db);

    $db->beginTransaction();

    $stmt = $db->prepare('UPDATE chat_verified_phones SET last_session_id = NULL WHERE last_session_id = ? AND business_card_id = ?');
    $stmt->execute([$sessionId, $businessCardId]);

    $deleteStatements = [
        ['DELETE FROM loan_simulation_inputs WHERE session_id = ? AND business_card_id = ?', [$sessionId, $businessCardId]],
        ['DELETE FROM chat_openai_usage WHERE session_id = ? AND business_card_id = ?', [$sessionId, $businessCardId]],
        ['DELETE FROM chat_session_memory WHERE session_id = ? AND business_card_id = ?', [$sessionId, $businessCardId]],
        ['DELETE FROM chat_lead_contacts WHERE session_id = ? AND business_card_id = ?', [$sessionId, $businessCardId]],
        ['DELETE FROM chat_leads WHERE session_id = ? AND business_card_id = ?', [$sessionId, $businessCardId]],
        ['DELETE FROM chat_messages WHERE session_id = ?', [$sessionId]],
        ['DELETE FROM chat_sessions WHERE id = ? AND business_card_id = ?', [$sessionId, $businessCardId]],
    ];

    foreach ($deleteStatements as $deleteStatement) {
        [$sql, $params] = $deleteStatement;
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
        }
    }

    $db->commit();

    sendSuccessResponse(['session_id' => $sessionId], 'チャット履歴を削除しました');
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Chat session delete error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
