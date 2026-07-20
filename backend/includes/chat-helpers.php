<?php
/**
 * Chatbot & plan gating helpers
 * - Resolve card by slug and check plan features
 * - can_use_chatbot, can_use_loan_sim derived from plan_type
 */

/**
 * Get business card by url_slug (for public card page / chat).
 * Returns card row or null.
 *
 * @param PDO $db
 * @param string $slug
 * @return array|null
 */
function getCardBySlugForChat($db, $slug) {
    $stmt = $db->prepare("
        SELECT bc.*, u.status as user_status
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        WHERE bc.url_slug = ? AND u.status = 'active'
    ");
    $stmt->execute([$slug]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    return $card ?: null;
}

/**
 * Whether the card's plan allows AI chatbot.
 * Standard plan = yes, Entry = no. If plan_type missing, default to standard (yes).
 *
 * @param array $card business_cards row (with plan_type key if present)
 * @return bool
 */
function canUseChatbot($card) {
    $plan = isset($card['plan_type']) ? $card['plan_type'] : 'standard';
    return strtolower($plan) === 'standard';
}

/**
 * Whether the card's plan allows loan simulator.
 *
 * @param array $card
 * @return bool
 */
function canUseLoanSim($card) {
    $plan = isset($card['plan_type']) ? $card['plan_type'] : 'standard';
    return strtolower($plan) === 'standard';
}

/**
 * 体験版（デモ）名刺かどうか。
 * デモ名刺ではSMS認証を求めず、訪問者ごとに使い捨てセッションを発行する。
 *
 * @param array $card business_cards row
 * @return bool
 */
function isDemoCard($card) {
    return !empty($card['is_demo']);
}

/**
 * デモセッションの有効期間（秒）。既定24時間。
 * 期限切れは backend/cron/cleanup-demo-sessions.php が削除する。
 *
 * @return int
 */
function chatDemoSessionTtlSeconds() {
    $ttl = (int)(getenv('CHAT_DEMO_SESSION_TTL') ?: 86400);
    return max(3600, min(604800, $ttl));
}

/**
 * デモ機能で使う列を実行時に補完する（migrations/20260720_add_demo_card.sql 未適用でも動くように）。
 * 他の ensure* ヘルパー同様、プレースホルダを使わない SHOW で introspect する
 * （native prepares 環境では `SHOW COLUMNS ... LIKE ?` が 1064 で落ち、移行が沈黙して失敗するため）。
 *
 * @param PDO $db
 * @return void
 */
function ensureChatDemoColumns($db) {
    static $done = false;
    if ($done) return;
    $done = true;

    $targets = [
        'business_cards' => [
            'is_demo' => "ALTER TABLE business_cards ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0",
        ],
        'chat_sessions' => [
            'is_demo' => "ALTER TABLE chat_sessions ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0",
            'expires_at' => "ALTER TABLE chat_sessions ADD COLUMN expires_at DATETIME NULL",
        ],
    ];

    foreach ($targets as $table => $columns) {
        $existing = [];
        try {
            foreach ($db->query("SHOW COLUMNS FROM {$table}") as $row) {
                $existing[$row['Field']] = true;
            }
        } catch (Throwable $e) {
            error_log($table . ' demo column introspection failed: ' . $e->getMessage());
            continue;
        }
        foreach ($columns as $column => $sql) {
            if (isset($existing[$column])) continue;
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                error_log($table . ' demo schema update failed for ' . $column . ': ' . $e->getMessage());
            }
        }
    }

    $existingIndexes = [];
    try {
        foreach ($db->query("SHOW INDEX FROM chat_sessions") as $row) {
            $existingIndexes[$row['Key_name']] = true;
        }
    } catch (Throwable $e) {
        error_log('chat_sessions demo index introspection failed: ' . $e->getMessage());
        return;
    }
    if (!isset($existingIndexes['idx_chat_sessions_demo_expiry'])) {
        try {
            $db->exec("ALTER TABLE chat_sessions ADD INDEX idx_chat_sessions_demo_expiry (is_demo, expires_at)");
        } catch (Throwable $e) {
            error_log('chat_sessions demo index update failed: ' . $e->getMessage());
        }
    }
}

/**
 * Generate a UUID v4 style id for chat session.
 *
 * @return string
 */
function generateChatSessionId() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function loadRecentChatMessagesForResume($db, $sessionId, $limit = 40) {
    if (!$db instanceof PDO || $sessionId === '') return [];
    $limit = max(1, min(100, (int)$limit));
    try {
        $stmt = $db->prepare("
            SELECT id, role, channel, message, created_at, edited_at, deleted_at
            FROM (
                SELECT id, role, channel, message, created_at, edited_at, deleted_at
                FROM chat_messages
                WHERE session_id = ?
                ORDER BY id DESC
                LIMIT {$limit}
            ) recent_messages
            ORDER BY id ASC
        ");
        $stmt->execute([$sessionId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // 担当連絡チャネルの添付（画像/PDF等）も再開時に復元できるよう補完する。
        if ($messages && function_exists('agentMsgLoadAttachments')) {
            $attach = agentMsgLoadAttachments($db, array_column($messages, 'id'));
            if ($attach) {
                foreach ($messages as &$m) { $m['attachments'] = $attach[(int)$m['id']] ?? []; }
                unset($m);
            }
        }
        // 取り消し済みメッセージは本文をプレースホルダに、編集済みはフラグを付ける。
        if ($messages && function_exists('agentMsgApplyEditState')) {
            foreach ($messages as &$m) { $m = agentMsgApplyEditState($m, false); }
            unset($m);
        }
        return $messages;
    } catch (Throwable $e) {
        error_log('Chat resume messages load error: ' . $e->getMessage());
        return [];
    }
}
