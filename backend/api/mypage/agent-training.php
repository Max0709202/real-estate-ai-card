<?php
/**
 * My page API for user-managed agent training data:
 * - custom RAG rows
 * - prohibited words
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-rag-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

function agentTrainingBusinessCardId(PDO $db, $userId) {
    $stmt = $db->prepare('SELECT id FROM business_cards WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$userId]);
    $id = $stmt->fetchColumn();
    if (!$id) sendErrorResponse('ビジネスカードが見つかりません', 404);
    return (int)$id;
}

function agentTrainingReadInput() {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    return is_array($input) ? $input : [];
}

function agentTrainingBool($value, $default = true) {
    if ($value === null || $value === '') return $default ? 1 : 0;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1;
}

try {
    $userId = requireAuth();
    $db = (new Database())->getConnection();
    ensureChatRagTables($db);
    $businessCardId = agentTrainingBusinessCardId($db, $userId);

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'all';
        $data = [];

        if ($type === 'all' || $type === 'rag') {
            $stmt = $db->prepare("SELECT id, title, content, source_note, enabled, created_at, updated_at
                FROM agent_custom_rag_items
                WHERE business_card_id = ?
                ORDER BY updated_at DESC, id DESC
                LIMIT 500");
            $stmt->execute([$businessCardId]);
            $data['rag_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($type === 'all' || $type === 'prohibited') {
            $stmt = $db->prepare("SELECT id, word, replacement, note, enabled, created_at, updated_at
                FROM agent_prohibited_words
                WHERE business_card_id = ?
                ORDER BY updated_at DESC, id DESC
                LIMIT 500");
            $stmt->execute([$businessCardId]);
            $data['prohibited_words'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        sendSuccessResponse($data, 'OK');
    }

    if ($method === 'POST') {
        $input = agentTrainingReadInput();
        $type = $input['type'] ?? '';
        $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
        if (!in_array($type, ['rag', 'prohibited'], true)) {
            sendErrorResponse('type is required', 400);
        }
        if (empty($items)) {
            sendErrorResponse('登録するデータがありません', 400);
        }
        if (count($items) > 300) {
            sendErrorResponse('一度に登録できる件数は300件までです', 400);
        }

        $db->beginTransaction();
        $created = 0;
        $skipped = 0;

        if ($type === 'rag') {
            $stmt = $db->prepare("INSERT INTO agent_custom_rag_items
                (business_card_id, title, content, source_note, enabled)
                VALUES (?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $title = trim((string)($item['title'] ?? ''));
                $content = trim((string)($item['content'] ?? ''));
                $sourceNote = trim((string)($item['source_note'] ?? ''));
                if ($title === '' && $content !== '') $title = mb_substr($content, 0, 40);
                if ($title === '' || $content === '') {
                    $skipped++;
                    continue;
                }
                $stmt->execute([
                    $businessCardId,
                    mb_substr($title, 0, 255),
                    $content,
                    $sourceNote !== '' ? mb_substr($sourceNote, 0, 255) : null,
                    agentTrainingBool($item['enabled'] ?? true, true),
                ]);
                $created++;
            }
        } else {
            $stmt = $db->prepare("INSERT INTO agent_prohibited_words
                (business_card_id, word, replacement, note, enabled)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE replacement = VALUES(replacement), note = VALUES(note), enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP");
            foreach ($items as $item) {
                $word = trim((string)($item['word'] ?? ''));
                if ($word === '') {
                    $skipped++;
                    continue;
                }
                $replacement = trim((string)($item['replacement'] ?? ''));
                $note = trim((string)($item['note'] ?? ''));
                $stmt->execute([
                    $businessCardId,
                    mb_substr($word, 0, 255),
                    $replacement !== '' ? mb_substr($replacement, 0, 255) : null,
                    $note !== '' ? mb_substr($note, 0, 255) : null,
                    agentTrainingBool($item['enabled'] ?? true, true),
                ]);
                $created++;
            }
        }

        $db->commit();
        sendSuccessResponse(['created' => $created, 'skipped' => $skipped], '登録しました');
    }

    if ($method === 'DELETE') {
        $input = agentTrainingReadInput();
        $type = $input['type'] ?? '';
        $id = (int)($input['id'] ?? 0);
        if (!in_array($type, ['rag', 'prohibited'], true) || $id <= 0) {
            sendErrorResponse('type and id are required', 400);
        }
        $table = $type === 'rag' ? 'agent_custom_rag_items' : 'agent_prohibited_words';
        $stmt = $db->prepare("DELETE FROM {$table} WHERE id = ? AND business_card_id = ?");
        $stmt->execute([$id, $businessCardId]);
        sendSuccessResponse(['deleted' => $stmt->rowCount()], '削除しました');
    }

    sendErrorResponse('Method not allowed', 405);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('Agent training API error: ' . $e->getMessage());
    sendErrorResponse(ENVIRONMENT === 'development' ? $e->getMessage() : 'サーバーエラーが発生しました', 500);
}
