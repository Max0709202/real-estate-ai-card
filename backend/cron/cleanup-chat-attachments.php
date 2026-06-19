<?php
/**
 * Cron Job: チャット添付ファイルの自動クリーンアップ（保存容量の最小化）。
 *
 * 削除対象:
 *   1) 一時アップロード（message_id IS NULL）で 24時間以上 経過したもの（送信されず放置されたファイル）
 *   2) 大型の添付（既定 1MB 超）で、所属セッションが 3か月以上 アクセスされていないもの
 *      （メッセージ本文・小さい画像は残し、容量を食う大型ファイルのみ削除する）
 *
 * いずれも実ファイルとDB行（chat_message_attachments）の両方を削除する。
 *
 * 日次のcron登録例（共有ホスティングのcrontab、毎日 3:30）:
 *   30 3 * * * /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-chat-attachments.php
 *
 * 安全確認（何も削除せず対象だけを表示）:
 *   /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-chat-attachments.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Tokyo');
set_time_limit(300);

// ===== しきい値（必要に応じて調整） =====
const ORPHAN_MAX_AGE_HOURS = 24;       // 送信されず放置された一時アップロードの猶予
const INACTIVE_MONTHS      = 3;        // この期間アクセスのないセッションが対象
const LARGE_BYTES          = 1048576;  // 「大型」と判定するサイズ（1MB）

$dryRun = in_array('--dry-run', $argv, true);
$logFile = __DIR__ . '/../logs/chat-attachment-cleanup.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}

function attachCleanupLog($message) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

/**
 * 添付の実ファイルとDB行を削除する。戻り値: [deleted(bool), bytes(int)]
 */
function attachCleanupRemove(PDO $db, array $row, bool $dryRun): array {
    $bytes = (int)($row['byte_size'] ?? 0);
    $absPath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$row['stored_path'], '/');
    $label = 'id=' . $row['id'] . ' size=' . $bytes . ' path=' . $row['stored_path'];

    if ($dryRun) {
        attachCleanupLog('Would delete: ' . $label);
        return [true, $bytes];
    }

    // 先にファイルを削除（存在しなくてもDB行は消す）。
    if (is_file($absPath)) {
        if (!@unlink($absPath)) {
            attachCleanupLog('Failed to delete file (keep DB row): ' . $label);
            return [false, 0];
        }
    }
    try {
        $stmt = $db->prepare('DELETE FROM chat_message_attachments WHERE id = ?');
        $stmt->execute([(int)$row['id']]);
        attachCleanupLog('Deleted: ' . $label);
        return [true, $bytes];
    } catch (Throwable $e) {
        attachCleanupLog('DB delete failed: ' . $label . ' err=' . $e->getMessage());
        return [false, 0];
    }
}

attachCleanupLog('Starting chat attachment cleanup' . ($dryRun ? ' (dry-run)' : ''));

$deleted = 0;
$freedBytes = 0;

try {
    $db = (new Database())->getConnection();

    // 1) 放置された一時アップロード（未送信）
    $stmt = $db->prepare("
        SELECT id, stored_path, byte_size
        FROM chat_message_attachments
        WHERE message_id IS NULL
          AND created_at < (NOW() - INTERVAL :hours HOUR)
    ");
    $stmt->bindValue(':hours', ORPHAN_MAX_AGE_HOURS, PDO::PARAM_INT);
    $stmt->execute();
    $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    attachCleanupLog('Orphan temp uploads found: ' . count($orphans));
    foreach ($orphans as $row) {
        [$ok, $bytes] = attachCleanupRemove($db, $row, $dryRun);
        if ($ok) { $deleted++; $freedBytes += $bytes; }
    }

    // 2) 長期アクセスなしセッションの大型添付
    $stmt = $db->prepare("
        SELECT a.id, a.stored_path, a.byte_size
        FROM chat_message_attachments a
        JOIN chat_sessions cs ON cs.id = a.session_id
        WHERE a.message_id IS NOT NULL
          AND a.byte_size > :large
          AND COALESCE(cs.last_seen_at, cs.created_at) < (NOW() - INTERVAL :months MONTH)
    ");
    $stmt->bindValue(':large', LARGE_BYTES, PDO::PARAM_INT);
    $stmt->bindValue(':months', INACTIVE_MONTHS, PDO::PARAM_INT);
    $stmt->execute();
    $stale = $stmt->fetchAll(PDO::FETCH_ASSOC);
    attachCleanupLog('Large attachments in inactive sessions found: ' . count($stale));
    foreach ($stale as $row) {
        [$ok, $bytes] = attachCleanupRemove($db, $row, $dryRun);
        if ($ok) { $deleted++; $freedBytes += $bytes; }
    }

    attachCleanupLog(
        'Finished cleanup: ' . ($dryRun ? 'would_delete=' : 'deleted=') . $deleted
        . ', freed=' . round($freedBytes / 1048576, 2) . 'MB'
    );
    exit(0);
} catch (Throwable $e) {
    attachCleanupLog('FATAL: ' . $e->getMessage());
    exit(1);
}
