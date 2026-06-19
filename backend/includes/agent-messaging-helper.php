<?php
/**
 * 担当連絡（顧客↔担当エージェント）共通ヘルパー。
 * 既存の chat_sessions / chat_messages を共有チャネルとして使い、role='agent' を人間の担当発言とする。
 */

if (!function_exists('agentMsgVerifyOwnedSession')) {
    /**
     * セッションが指定ユーザー（担当）の名刺に属するか検証し、セッション情報を返す。
     * 属さなければ 403/404 で終了する（担当側エンドポイント用）。
     */
    function agentMsgVerifyOwnedSession(PDO $db, string $sessionId, int $userId): array
    {
        $stmt = $db->prepare("
            SELECT cs.id, cs.business_card_id, cs.handoff_mode,
                   bc.name AS card_holder_name, bc.company_name
            FROM chat_sessions cs
            JOIN business_cards bc ON bc.id = cs.business_card_id
            WHERE cs.id = ? AND bc.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId, $userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            sendErrorResponse('セッションが見つかりません', 404);
        }
        return $session;
    }
}

if (!function_exists('agentMsgInsertMessage')) {
    /**
     * メッセージを保存し、新しい message_id を返す。
     * $role: 'user' | 'agent' | 'bot' | 'system'
     * $channel: 'ai'（AIチャット） | 'contact'（担当連絡）
     */
    function agentMsgInsertMessage(PDO $db, string $sessionId, string $role, string $message, ?int $senderUserId = null, string $channel = 'contact'): int
    {
        $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, channel, sender_user_id, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$sessionId, $role, $channel, $senderUserId, $message]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('agentMsgMarkRead')) {
    /**
     * 指定セッションの、指定ロールの未読メッセージを既読化する。
     * 担当が顧客発言を読む場合は role='user'、顧客が担当発言を読む場合は role='agent'。
     */
    function agentMsgMarkRead(PDO $db, string $sessionId, string $role): int
    {
        $stmt = $db->prepare("UPDATE chat_messages SET read_at = CURRENT_TIMESTAMP WHERE session_id = ? AND role = ? AND read_at IS NULL");
        $stmt->execute([$sessionId, $role]);
        return $stmt->rowCount();
    }
}

if (!function_exists('agentMsgSetHandoff')) {
    /** ハンドオフ状態を設定（'bot' or 'agent'）。 */
    function agentMsgSetHandoff(PDO $db, string $sessionId, string $mode): void
    {
        if ($mode !== 'bot' && $mode !== 'agent') return;
        $stmt = $db->prepare("UPDATE chat_sessions SET handoff_mode = ? WHERE id = ?");
        $stmt->execute([$mode, $sessionId]);
    }
}

if (!function_exists('agentMsgLoadAttachments')) {
    /**
     * メッセージIDの配列に対する添付を message_id をキーにまとめて返す。
     */
    function agentMsgLoadAttachments(PDO $db, array $messageIds): array
    {
        $messageIds = array_values(array_filter(array_map('intval', $messageIds)));
        if (!$messageIds) return [];
        $place = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $db->prepare("
            SELECT id, message_id, kind, original_name, mime_type, byte_size, width, height
            FROM chat_message_attachments
            WHERE message_id IN ($place)
            ORDER BY id ASC
        ");
        $stmt->execute($messageIds);
        $byMessage = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mid = (int)$row['message_id'];
            $row['url'] = API_BASE_URL . '/chat/attachment/download.php?id=' . (int)$row['id'];
            $row['is_image'] = ($row['kind'] === 'image') ? 1 : 0;
            $byMessage[$mid][] = $row;
        }
        return $byMessage;
    }
}

if (!function_exists('agentMsgAttachMessageId')) {
    /**
     * 仮アップロード済みの添付（message_id=NULL）を、確定したメッセージに紐付ける。
     * 指定セッション・指定アップロード者のものだけを対象にする。
     */
    function agentMsgAttachMessageId(PDO $db, array $attachmentIds, int $messageId, string $sessionId, string $uploadedBy): void
    {
        $attachmentIds = array_values(array_filter(array_map('intval', $attachmentIds)));
        if (!$attachmentIds) return;
        $place = implode(',', array_fill(0, count($attachmentIds), '?'));
        $params = array_merge([$messageId], $attachmentIds, [$sessionId, $uploadedBy]);
        $stmt = $db->prepare("
            UPDATE chat_message_attachments
            SET message_id = ?
            WHERE id IN ($place) AND message_id IS NULL AND session_id = ? AND uploaded_by = ?
        ");
        $stmt->execute($params);
    }
}

if (!function_exists('agentMsgKindFromMime')) {
    /** MIME / 拡張子から添付種別を判定。 */
    function agentMsgKindFromMime(string $mime, string $filename): string
    {
        $mime = strtolower($mime);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (strpos($mime, 'image/') === 0) return 'image';
        if ($mime === 'application/pdf' || $ext === 'pdf') return 'pdf';
        if (in_array($ext, ['doc', 'docx'], true) || strpos($mime, 'word') !== false || strpos($mime, 'officedocument.wordprocessingml') !== false) return 'word';
        if (in_array($ext, ['xls', 'xlsx'], true) || strpos($mime, 'excel') !== false || strpos($mime, 'spreadsheetml') !== false) return 'excel';
        return 'other';
    }
}
