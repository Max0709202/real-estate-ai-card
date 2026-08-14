<?php
/**
 * Cron Job: ゴミ箱に入れたチャット履歴のうち、保持期間を過ぎたものを実体ごと削除する。
 *
 * 担当者が一覧から削除した履歴は chat_sessions.deleted_at を立てて隠すだけにしてあり、
 * 保持期間内（既定30日 / CHAT_SESSION_TRASH_RETENTION_DAYS）は My Page のゴミ箱から
 * 復元できる。期限を過ぎたものだけをここで完全に削除する。
 *
 * 日次のcron登録例（毎日 4:10）:
 *   10 4 * * * /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-deleted-chat-sessions.php
 *
 * 安全確認（何も削除せず対象件数だけを表示）:
 *   /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-deleted-chat-sessions.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-session-trash-helper.php';

date_default_timezone_set('Asia/Tokyo');
set_time_limit(300);

// 1回の実行で削除する上限（長時間のロックを避ける）
const TRASHED_SESSION_DELETE_BATCH = 200;

$dryRun = in_array('--dry-run', $argv, true);
$logFile = __DIR__ . '/../logs/deleted-chat-session-cleanup.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}

function trashedSessionCleanupLog($message) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    chatSessionTrashEnsureColumns($db);

    // 保持日数は chatSessionTrashRetentionDays() が 1〜365 の整数に丸めるため直接埋め込む
    // （native prepares では INTERVAL ? DAY のバインドが使えない）。
    $retentionDays = chatSessionTrashRetentionDays();
    $stmt = $db->prepare("SELECT id, business_card_id FROM chat_sessions
        WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL " . (int)$retentionDays . " DAY)
        ORDER BY deleted_at ASC
        LIMIT " . TRASHED_SESSION_DELETE_BATCH);
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$sessions) {
        trashedSessionCleanupLog('No trashed chat sessions past the ' . $retentionDays . '-day retention.');
        exit(0);
    }

    if ($dryRun) {
        trashedSessionCleanupLog('Would delete ' . count($sessions) . ' trashed chat session(s) past the ' . $retentionDays . '-day retention.');
        exit(0);
    }

    // 関連テーブルの用意はトランザクションの外で（DDLは暗黙コミットになるため）。
    chatSessionTrashEnsureRelatedTables($db);

    $deleted = 0;
    $failed = 0;
    foreach ($sessions as $session) {
        try {
            $db->beginTransaction();
            chatSessionTrashHardDelete($db, (string)$session['id'], (int)$session['business_card_id']);
            $db->commit();
            $deleted++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $failed++;
            trashedSessionCleanupLog('ERROR deleting session ' . $session['id'] . ': ' . $e->getMessage());
        }
    }

    trashedSessionCleanupLog('Deleted ' . $deleted . ' trashed chat session(s), ' . $failed . ' failed.');
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    trashedSessionCleanupLog('ERROR: ' . $e->getMessage());
    exit(1);
}
