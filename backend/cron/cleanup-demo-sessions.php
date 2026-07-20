<?php
/**
 * Cron Job: 体験版（デモ）名刺のチャットセッションを自動削除する。
 *
 * デモ名刺はSMS認証なしで誰でも試せるため、体験者ごとに使い捨てセッションが増え続ける。
 * expires_at（既定24時間・session/start.php で付与）を過ぎたものを本体ごと削除する。
 *
 * chat_messages / chat_leads / chat_lead_contacts / chat_crm_cases 等は
 * chat_sessions への FK が ON DELETE CASCADE のため、親行の削除で一緒に消える。
 * 添付の実ファイルは cleanup-chat-attachments.php が別途回収する。
 *
 * 日次のcron登録例（毎日 3:50）:
 *   50 3 * * * /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-demo-sessions.php
 *
 * 安全確認（何も削除せず対象件数だけを表示）:
 *   /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-demo-sessions.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-helpers.php';

date_default_timezone_set('Asia/Tokyo');
set_time_limit(300);

// 1回の実行で削除する上限（長時間のロックを避ける）
const DEMO_DELETE_BATCH = 500;

$dryRun = in_array('--dry-run', $argv, true);
$logFile = __DIR__ . '/../logs/demo-session-cleanup.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}

function demoCleanupLog($message) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    ensureChatDemoColumns($db);

    $stmt = $db->prepare("SELECT id FROM chat_sessions
        WHERE is_demo = 1 AND expires_at IS NOT NULL AND expires_at < NOW()
        ORDER BY expires_at ASC
        LIMIT " . DEMO_DELETE_BATCH);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$ids) {
        demoCleanupLog('No expired demo sessions.');
        exit(0);
    }

    if ($dryRun) {
        demoCleanupLog('Would delete ' . count($ids) . ' expired demo session(s).');
        exit(0);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $del = $db->prepare("DELETE FROM chat_sessions WHERE id IN ({$placeholders}) AND is_demo = 1");
    $del->execute($ids);
    demoCleanupLog('Deleted ' . $del->rowCount() . ' expired demo session(s).');
    exit(0);
} catch (Throwable $e) {
    demoCleanupLog('ERROR: ' . $e->getMessage());
    exit(1);
}
