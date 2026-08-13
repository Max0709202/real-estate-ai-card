<?php
/**
 * チャット履歴の「ゴミ箱」（ソフト削除・復元）ヘルパー。
 *
 * 担当者が一覧から履歴を削除しても即座には消さず、chat_sessions.deleted_at を立てて
 * 担当側の一覧から隠すだけにする。誤って削除した場合は保持期間内なら復元できる。
 * 保持期間を過ぎたものは backend/cron/cleanup-deleted-chat-sessions.php が実体を削除する。
 *
 * お客様側には影響しない：セッション行もメッセージ行もそのまま残るため、お客様の
 * チャット画面は今までどおり開け、過去のやり取りも消えない。お客様から新しい
 * メッセージが届いた時点で、そのセッションは自動的にゴミ箱から一覧へ戻す
 * （chatSessionTrashRestoreOnCustomerActivity）。
 */

if (!function_exists('chatSessionTrashRetentionDays')) {
    /**
     * ゴミ箱の保持日数（この日数を過ぎた履歴は cron が完全に削除する）。既定30日。
     *
     * @return int
     */
    function chatSessionTrashRetentionDays()
    {
        $days = (int)(getenv('CHAT_SESSION_TRASH_RETENTION_DAYS') ?: 30);
        return max(1, min(365, $days));
    }
}

if (!function_exists('chatSessionTrashEnsureColumns')) {
    /**
     * ソフト削除用の列を実行時に補完する
     * （migrations/20260813_add_chat_session_soft_delete.sql 未適用でも動くように）。
     * 他の ensure* ヘルパー同様、プレースホルダを使わない SHOW で introspect する
     * （native prepares 環境では `SHOW COLUMNS ... LIKE ?` が 1064 で落ちるため）。
     *
     * @param PDO $db
     * @return void
     */
    function chatSessionTrashEnsureColumns($db)
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $existing = [];
        try {
            foreach ($db->query('SHOW COLUMNS FROM chat_sessions') as $row) {
                $existing[$row['Field']] = true;
            }
        } catch (Throwable $e) {
            error_log('chat_sessions trash column introspection failed: ' . $e->getMessage());
            return;
        }

        $columns = [
            'deleted_at' => 'ALTER TABLE chat_sessions ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
            'deleted_by_user_id' => 'ALTER TABLE chat_sessions ADD COLUMN deleted_by_user_id INT NULL DEFAULT NULL',
        ];
        foreach ($columns as $column => $sql) {
            if (isset($existing[$column])) continue;
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                error_log('chat_sessions trash schema update failed for ' . $column . ': ' . $e->getMessage());
            }
        }

        $existingIndexes = [];
        try {
            foreach ($db->query('SHOW INDEX FROM chat_sessions') as $row) {
                $existingIndexes[$row['Key_name']] = true;
            }
        } catch (Throwable $e) {
            error_log('chat_sessions trash index introspection failed: ' . $e->getMessage());
            return;
        }
        if (!isset($existingIndexes['idx_chat_sessions_deleted_at'])) {
            try {
                $db->exec('ALTER TABLE chat_sessions ADD INDEX idx_chat_sessions_deleted_at (deleted_at)');
            } catch (Throwable $e) {
                error_log('chat_sessions trash index update failed: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('chatSessionTrashPurgeAt')) {
    /**
     * 完全削除される日時（deleted_at + 保持日数）を返す。
     *
     * @param string|null $deletedAt
     * @return string|null 'Y-m-d H:i:s'
     */
    function chatSessionTrashPurgeAt($deletedAt)
    {
        if (empty($deletedAt)) return null;
        $time = strtotime((string)$deletedAt);
        if ($time === false) return null;
        return date('Y-m-d H:i:s', $time + (chatSessionTrashRetentionDays() * 86400));
    }
}

if (!function_exists('chatSessionTrashDaysLeft')) {
    /**
     * 復元できる残り日数（切り上げ）。期限切れは0を返す。
     *
     * @param string|null $deletedAt
     * @return int
     */
    function chatSessionTrashDaysLeft($deletedAt)
    {
        $purgeAt = chatSessionTrashPurgeAt($deletedAt);
        if ($purgeAt === null) return 0;
        $remain = strtotime($purgeAt) - time();
        return $remain <= 0 ? 0 : (int)ceil($remain / 86400);
    }
}

if (!function_exists('chatSessionTrashRestoreOnCustomerActivity')) {
    /**
     * お客様の新しい発言でゴミ箱から自動復帰させる。
     * 担当者が削除した後もお客様は今までどおり送信できるため、そのまま隠したままだと
     * 担当者が新着に気付けない。届いた時点で一覧へ戻す。
     *
     * @param PDO $db
     * @param string $sessionId
     * @return bool 復帰させたら true
     */
    function chatSessionTrashRestoreOnCustomerActivity($db, $sessionId)
    {
        if ($sessionId === '') return false;
        chatSessionTrashEnsureColumns($db);
        try {
            $stmt = $db->prepare('UPDATE chat_sessions SET deleted_at = NULL, deleted_by_user_id = NULL WHERE id = ? AND deleted_at IS NOT NULL');
            $stmt->execute([$sessionId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('chat session trash auto-restore failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('chatSessionTrashEnsureRelatedTables')) {
    /**
     * 実体削除で触る関連テーブルを用意する。
     * CREATE TABLE は暗黙コミットを起こすため、必ずトランザクションを開始する前に呼ぶこと。
     *
     * @param PDO $db
     * @return void
     */
    function chatSessionTrashEnsureRelatedTables($db)
    {
        static $done = false;
        if ($done) return;
        $done = true;

        // 実体削除でしか使わない依存はここで読み込む（ポーリング等のホットパスに載せない）。
        require_once __DIR__ . '/chat-phone-helper.php';
        require_once __DIR__ . '/loan-simulation-helper.php';

        ensureChatVerifiedPhonesTable($db);
        ensureLoanSimulationInputsTable($db);
    }
}

if (!function_exists('chatSessionTrashHardDelete')) {
    /**
     * セッションと関連データを実体ごと削除する（ゴミ箱からの完全削除・保持期限切れの掃除用）。
     * 呼び出し側でトランザクションを張り、その前に chatSessionTrashEnsureRelatedTables() を
     * 済ませておくこと（トランザクション内でDDLを流すと暗黙コミットになるため）。
     *
     * @param PDO $db
     * @param string $sessionId
     * @param int $businessCardId
     * @return void
     */
    function chatSessionTrashHardDelete($db, $sessionId, $businessCardId)
    {
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
            list($sql, $params) = $deleteStatement;
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S02') {
                    throw $e;
                }
            }
        }
    }
}
