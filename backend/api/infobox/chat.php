<?php
/**
 * チャット（全員／個別）（仕様 第3章・画面16・16b）。
 *
 * GET  ?action=conversations&box_id=                     … 会話一覧と未読件数
 * GET  ?action=messages&box_id=&conversation_id=&after_id= … 投稿（after_id より新しいもの）。取得した分は既読にする
 * POST multipart {action:'send', box_id, conversation_id | partner_id, body, file, client_uid}
 * POST {action:'edit', box_id, message_id, body} / {action:'delete', box_id, message_id} … 投稿者本人のみ
 *
 * ・個別は同じBOXの有効な関係者2人だけが閲覧・投稿できる（所有者・管理者でも第三者は不可）。
 * ・全員は有効な関係者全員に公開。宛先を書いても非公開にはならない。
 * ・添付は会話の中に保管し、書類フォルダーへは自動保存しない。書類リンクを貼っても元書類の権限は増えない。
 * ・本文をメールで往復させない。
 */
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=UTF-8');

function iboxAllConversationId(PDO $db, int $boxId): int
{
    $stmt = $db->prepare("SELECT id FROM ibox_conversations WHERE box_id = ? AND kind = 'all' LIMIT 1");
    $stmt->execute([$boxId]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) return $id;
    $db->prepare("INSERT IGNORE INTO ibox_conversations (box_id, kind, p_low, p_high, created_at) VALUES (?, 'all', 0, 0, ?)")->execute([$boxId, iboxNow()]);
    $stmt->execute([$boxId]);
    return (int)$stmt->fetchColumn();
}

/** 閲覧者が参加できる会話か。 */
function iboxLoadConversation(PDO $db, int $boxId, int $conversationId, array $me): ?array
{
    $stmt = $db->prepare('SELECT * FROM ibox_conversations WHERE id = ? AND box_id = ? LIMIT 1');
    $stmt->execute([$conversationId, $boxId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv) return null;
    if ($conv['kind'] === 'direct' && !in_array((int)$me['id'], [(int)$conv['p_low'], (int)$conv['p_high']], true)) return null;
    return $conv;
}

function iboxUnreadCount(PDO $db, int $conversationId, int $participantId): int
{
    $stmt = $db->prepare('
        SELECT COUNT(*) FROM ibox_messages m
        LEFT JOIN ibox_message_reads r ON r.conversation_id = m.conversation_id AND r.participant_id = ?
        WHERE m.conversation_id = ? AND m.deleted_at IS NULL AND m.sender_id <> ? AND m.id > COALESCE(r.last_read_id, 0)
    ');
    $stmt->execute([$participantId, $conversationId, $participantId]);
    return (int)$stmt->fetchColumn();
}

function iboxMessagePayload(array $m, array $me, array $nameById, array $roleById, int $boxId): array
{
    $deleted = $m['deleted_at'] !== null;
    return [
        'id' => (int)$m['id'],
        'sender_id' => (int)$m['sender_id'],
        'sender_name' => $nameById[(int)$m['sender_id']] ?? '',
        'sender_role' => $roleById[(int)$m['sender_id']] ?? '',
        'is_mine' => (int)$m['sender_id'] === (int)$me['id'],
        'body' => $deleted ? '' : (string)($m['body'] ?? ''),
        'deleted' => $deleted,
        'edited' => $m['edited_at'] !== null,
        'attachment' => (!$deleted && !empty($m['attach_stored'])) ? [
            'name' => (string)$m['attach_name'],
            'mime' => (string)$m['attach_mime'],
            'url' => 'backend/api/infobox/file.php?kind=chat&box_id=' . $boxId . '&id=' . (int)$m['id'],
        ] : null,
        'created_at' => $m['created_at'],
        'client_uid' => (string)($m['client_uid'] ?? ''),
    ];
}

try {
    $db = iboxApiDb();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $viewer = iboxApiViewer($db, (int)($_GET['box_id'] ?? 0));
        $box = $viewer['box'];
        $me = $viewer['p'];
        $all = iboxParticipants($db, (int)$box['id'], true);
        $nameById = iboxApiNameMap($all);
        $roleById = [];
        foreach ($all as $pp) $roleById[(int)$pp['id']] = iboxParticipantRoleLabel($box, $pp);
        $action = (string)($_GET['action'] ?? 'conversations');

        if ($action === 'conversations') {
            $allId = iboxAllConversationId($db, (int)$box['id']);
            $stmt = $db->prepare("SELECT * FROM ibox_conversations WHERE box_id = ? AND kind = 'direct' AND (p_low = ? OR p_high = ?) ORDER BY id DESC");
            $stmt->execute([(int)$box['id'], (int)$me['id'], (int)$me['id']]);
            $last = $db->prepare('SELECT body, attach_name, created_at FROM ibox_messages WHERE conversation_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
            $directs = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $partnerId = (int)$c['p_low'] === (int)$me['id'] ? (int)$c['p_high'] : (int)$c['p_low'];
                $partner = iboxLoadParticipant($db, $partnerId);
                $last->execute([(int)$c['id']]);
                $lm = $last->fetch(PDO::FETCH_ASSOC);
                $directs[] = [
                    'id' => (int)$c['id'],
                    'partner_id' => $partnerId,
                    'partner_name' => $nameById[$partnerId] ?? '',
                    'partner_role' => $roleById[$partnerId] ?? '',
                    'partner_active' => $partner ? iboxAccessState($box, $partner)['ok'] : false,
                    'last_body' => $lm ? mb_substr((string)($lm['body'] !== '' && $lm['body'] !== null ? $lm['body'] : '（添付ファイル）'), 0, 40) : '',
                    'last_at' => $lm['created_at'] ?? $c['created_at'],
                    'unread' => iboxUnreadCount($db, (int)$c['id'], (int)$me['id']),
                ];
            }
            usort($directs, fn($a, $b) => strcmp((string)$b['last_at'], (string)$a['last_at']));
            $candidates = [];
            foreach (iboxParticipants($db, (int)$box['id']) as $pp) {
                if ((int)$pp['id'] === (int)$me['id'] || !iboxAccessState($box, $pp)['ok']) continue;
                $candidates[] = ['id' => (int)$pp['id'], 'name' => iboxParticipantName($pp), 'role_label' => iboxParticipantRoleLabel($box, $pp)];
            }
            sendSuccessResponse([
                'all' => ['id' => $allId, 'unread' => iboxUnreadCount($db, $allId, (int)$me['id'])],
                'directs' => $directs,
                'candidates' => $candidates,
                'notice' => iboxChatNotice(),
            ]);
        }

        if ($action === 'messages') {
            $conv = iboxLoadConversation($db, (int)$box['id'], (int)($_GET['conversation_id'] ?? 0), $me);
            if (!$conv) sendErrorResponse('会話が見つかりません。', 404);
            $afterId = max(0, (int)($_GET['after_id'] ?? 0));
            $stmt = $db->prepare('SELECT * FROM ibox_messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT 300');
            $stmt->execute([(int)$conv['id'], $afterId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $messages = array_map(fn($m) => iboxMessagePayload($m, $me, $nameById, $roleById, (int)$box['id']), $rows);
            if ($rows) {
                $maxId = (int)end($rows)['id'];
                $db->prepare('INSERT INTO ibox_message_reads (conversation_id, participant_id, last_read_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))')
                    ->execute([(int)$conv['id'], (int)$me['id'], $maxId]);
            }
            // 編集・削除の反映用に、直近の更新も返す
            $stmt = $db->prepare('SELECT * FROM ibox_messages WHERE conversation_id = ? AND id <= ? AND (edited_at IS NOT NULL OR deleted_at IS NOT NULL) ORDER BY id DESC LIMIT 100');
            $stmt->execute([(int)$conv['id'], $afterId]);
            $updates = array_map(fn($m) => iboxMessagePayload($m, $me, $nameById, $roleById, (int)$box['id']), $stmt->fetchAll(PDO::FETCH_ASSOC));
            $canSend = !$viewer['readonly'];
            if ($conv['kind'] === 'direct') {
                $partnerId = (int)$conv['p_low'] === (int)$me['id'] ? (int)$conv['p_high'] : (int)$conv['p_low'];
                $partner = iboxLoadParticipant($db, $partnerId);
                if (!$partner || !iboxAccessState($box, $partner)['ok']) $canSend = false;
            }
            sendSuccessResponse(['messages' => $messages, 'updates' => $updates, 'kind' => $conv['kind'], 'can_send' => $canSend]);
        }
        sendErrorResponse('不明な操作です', 400);
    }

    iboxApiRequirePost();
    $input = iboxApiInput();
    $action = (string)($input['action'] ?? '');
    $viewer = iboxApiViewer($db, (int)($input['box_id'] ?? 0), true);
    $box = $viewer['box'];
    $me = $viewer['p'];

    if ($action === 'send') {
        $conversationId = (int)($input['conversation_id'] ?? 0);
        if ($conversationId <= 0 && (int)($input['partner_id'] ?? 0) > 0) {
            // 同じ2人の既存会話があれば再利用する
            $partner = iboxLoadParticipant($db, (int)$input['partner_id']);
            if (!$partner || (int)$partner['box_id'] !== (int)$box['id'] || (int)$partner['id'] === (int)$me['id'] || !iboxAccessState($box, $partner)['ok']) {
                sendErrorResponse('相手を選択してください。', 400);
            }
            $low = min((int)$me['id'], (int)$partner['id']);
            $high = max((int)$me['id'], (int)$partner['id']);
            $db->prepare("INSERT IGNORE INTO ibox_conversations (box_id, kind, p_low, p_high, created_at) VALUES (?, 'direct', ?, ?, ?)")->execute([(int)$box['id'], $low, $high, iboxNow()]);
            $stmt = $db->prepare("SELECT id FROM ibox_conversations WHERE box_id = ? AND kind = 'direct' AND p_low = ? AND p_high = ? LIMIT 1");
            $stmt->execute([(int)$box['id'], $low, $high]);
            $conversationId = (int)$stmt->fetchColumn();
        }
        $conv = iboxLoadConversation($db, (int)$box['id'], $conversationId, $me);
        if (!$conv) sendErrorResponse('会話が見つかりません。', 404);
        if ($conv['kind'] === 'direct') {
            $partnerId = (int)$conv['p_low'] === (int)$me['id'] ? (int)$conv['p_high'] : (int)$conv['p_low'];
            $partner = iboxLoadParticipant($db, $partnerId);
            if (!$partner || !iboxAccessState($box, $partner)['ok']) sendErrorResponse('相手の参加が停止されているか利用期限を過ぎているため、送信できません。', 403);
        }

        $body = trim(str_replace("\0", '', (string)($input['body'] ?? '')));
        if (mb_strlen($body) > 5000) sendErrorResponse('本文は5000文字以内で入力してください。', 400);
        $clientUid = iboxApiOpKey($input['client_uid'] ?? '');
        if ($clientUid !== null) {
            // 送信失敗からの再送・連打は、同じ投稿として扱う
            $stmt = $db->prepare('SELECT id FROM ibox_messages WHERE conversation_id = ? AND sender_id = ? AND client_uid = ? LIMIT 1');
            $stmt->execute([(int)$conv['id'], (int)$me['id'], $clientUid]);
            if ($existing = $stmt->fetchColumn()) sendSuccessResponse(['message_id' => (int)$existing, 'conversation_id' => (int)$conv['id']], '送信しました');
        }

        $attach = [null, null, null, null];
        if (!empty($_FILES['file']) && (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $valid = iboxValidateUpload($_FILES['file']);
            if (!$valid['ok']) sendErrorResponse($valid['message'], 400);
            $stored = iboxRandomName($valid['ext']);
            if (!@move_uploaded_file($_FILES['file']['tmp_name'], iboxStorageDir((int)$box['id']) . '/' . $stored)) sendErrorResponse('添付ファイルを保存できませんでした。', 500);
            $attach = [$stored, $valid['name'], $valid['mime'], $valid['size']];
        }
        if ($body === '' && $attach[0] === null) sendErrorResponse('メッセージを入力してください。', 400);

        $db->prepare('INSERT INTO ibox_messages (conversation_id, box_id, sender_id, body, attach_stored, attach_name, attach_mime, attach_size, client_uid, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int)$conv['id'], (int)$box['id'], (int)$me['id'], $body, $attach[0], $attach[1], $attach[2], $attach[3], $clientUid, iboxNow()]);
        $messageId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ibox_message_reads (conversation_id, participant_id, last_read_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))')
            ->execute([(int)$conv['id'], (int)$me['id'], $messageId]);
        sendSuccessResponse(['message_id' => $messageId, 'conversation_id' => (int)$conv['id']], '送信しました');
    }

    if ($action === 'edit' || $action === 'delete') {
        $stmt = $db->prepare('SELECT * FROM ibox_messages WHERE id = ? AND box_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([(int)($input['message_id'] ?? 0), (int)$box['id']]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg || !iboxLoadConversation($db, (int)$box['id'], (int)$msg['conversation_id'], $me)) sendErrorResponse('投稿が見つかりません。', 404);
        if ((int)$msg['sender_id'] !== (int)$me['id']) sendErrorResponse('投稿の編集・削除は投稿者本人だけが行えます。', 403);
        if ($action === 'edit') {
            $body = trim((string)($input['body'] ?? ''));
            if ($body === '' && empty($msg['attach_stored'])) sendErrorResponse('メッセージを入力してください。', 400);
            if (mb_strlen($body) > 5000) sendErrorResponse('本文は5000文字以内で入力してください。', 400);
            $db->prepare('UPDATE ibox_messages SET body = ?, edited_at = ? WHERE id = ?')->execute([$body, iboxNow(), (int)$msg['id']]);
            sendSuccessResponse([], '編集しました');
        }
        $db->prepare('UPDATE ibox_messages SET deleted_at = ? WHERE id = ?')->execute([iboxNow(), (int)$msg['id']]);
        sendSuccessResponse([], '削除しました');
    }

    sendErrorResponse('不明な操作です', 400);
} catch (Throwable $e) {
    error_log('infobox/chat.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
