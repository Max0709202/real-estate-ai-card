<?php
/**
 * Cron Job: 物件選定データの保存期間（既定6か月）超過分を自動削除する。
 *
 * 対象（すべて保存期間6か月で統一・PROPERTY_RETENTION_MONTHS で変更可）:
 *   ・元PDF（エージェント専用）   property_images.stored_path
 *   ・顧客用マスク済PDF           property_images.masked_path
 *   ・一覧サムネイル/写真・資料     property_images（写真）
 *   ・編集用プレビュー             property_images.preview_path
 * properties.expires_at / property_images.expires_at が過去のものを、実ファイルとDB行ともに削除する。
 *
 * 日次cron例（毎日 3:40）:
 *   40 3 * * * /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-expired-properties.php
 *
 * 安全確認（削除せず対象のみ表示）:
 *   /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/cron/cleanup-expired-properties.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/property-helper.php';

date_default_timezone_set('Asia/Tokyo');
set_time_limit(300);

$dryRun = in_array('--dry-run', $argv, true);
$logFile = __DIR__ . '/../logs/property-cleanup.log';
if (!is_dir(dirname($logFile))) { @mkdir(dirname($logFile), 0755, true); }

function propCleanupLog($message) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function propCleanupUnlinkRels(array $rels) {
    $n = 0;
    foreach ($rels as $rel) {
        if (empty($rel)) continue;
        $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($rel, '/');
        if (is_file($abs) && @unlink($abs)) $n++;
    }
    return $n;
}

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);
    propCleanupLog('=== property cleanup start' . ($dryRun ? ' (dry-run)' : '') . ' ===');

    $filesDeleted = 0; $imgRows = 0; $propRows = 0;

    // 1) 期限切れの個別画像行（写真・資料・サムネイル等）
    $stmt = $db->query("SELECT id, property_id, stored_path, preview_path, masked_path, thumb_path
                        FROM property_images
                        WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!$dryRun) $filesDeleted += propCleanupUnlinkRels([$r['stored_path'], $r['preview_path'], $r['masked_path'], $r['thumb_path']]);
        if (!$dryRun) {
            // サムネイル参照を外す
            $db->prepare("UPDATE properties SET thumbnail_image_id = NULL WHERE thumbnail_image_id = ?")->execute([(int)$r['id']]);
            $db->prepare("DELETE FROM property_images WHERE id = ?")->execute([(int)$r['id']]);
        }
        $imgRows++;
    }
    propCleanupLog("expired images: {$imgRows} rows, {$filesDeleted} files");

    // 2) 期限切れの物件（残った画像ファイルもまとめて削除）
    $stmt = $db->query("SELECT id, business_card_id FROM properties
                        WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    $props = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($props as $p) {
        $pid = (int)$p['id'];
        $imgs = $db->prepare("SELECT id, stored_path, preview_path, masked_path, thumb_path FROM property_images WHERE property_id = ?");
        $imgs->execute([$pid]);
        foreach ($imgs->fetchAll(PDO::FETCH_ASSOC) as $im) {
            if (!$dryRun) $filesDeleted += propCleanupUnlinkRels([$im['stored_path'], $im['preview_path'], $im['masked_path'], $im['thumb_path']]);
        }
        if (!$dryRun) {
            $db->prepare("DELETE FROM property_images WHERE property_id = ?")->execute([$pid]);
            $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$pid]);
            // 物件ディレクトリが空なら削除
            $dir = rtrim(UPLOAD_DIR, '/') . '/property/' . (int)$p['business_card_id'] . '/' . $pid;
            if (is_dir($dir)) { $left = glob($dir . '/*'); if (!$left) @rmdir($dir); }
        }
        $propRows++;
    }
    propCleanupLog("expired properties: {$propRows} rows");
    propCleanupLog('=== property cleanup done: total files deleted=' . $filesDeleted . ' ===');
} catch (Throwable $e) {
    propCleanupLog('ERROR: ' . $e->getMessage());
    exit(1);
}
