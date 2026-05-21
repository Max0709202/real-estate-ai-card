<?php
/**
 * Cron Job: Cleanup old PHP session files.
 *
 * Deletes sess_* files older than 1 day from known project session folders.
 *
 * Recommended cron, hourly:
 * 15 * * * * /usr/bin/php /path/to/public_html/backend/cron/cleanup-sessions.php
 *
 * Dry run:
 * /usr/bin/php /path/to/public_html/backend/cron/cleanup-sessions.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Tokyo');
set_time_limit(120);

$projectRoot = dirname(__DIR__, 2);
$backendRoot = dirname(__DIR__);
$sessionDirs = [
    $projectRoot . '/sessions',
    $backendRoot . '/sessions',
];

$dryRun = in_array('--dry-run', $argv, true);
$maxAgeSeconds = 86400;
$cutoff = time() - $maxAgeSeconds;
$logFile = $backendRoot . '/logs/session-cleanup.log';

if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

function cleanupSessionLog($message) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function cleanupSessionDirectory($dir, $cutoff, $dryRun) {
    $result = [
        'scanned' => 0,
        'deleted' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    if (!is_dir($dir)) {
        cleanupSessionLog('Skip missing directory: ' . $dir);
        return $result;
    }

    if (!is_readable($dir)) {
        cleanupSessionLog('Skip unreadable directory: ' . $dir);
        return $result;
    }

    foreach (new DirectoryIterator($dir) as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $filename = $file->getFilename();
        if (!preg_match('/^sess_[A-Za-z0-9,-]+$/', $filename)) {
            $result['skipped']++;
            continue;
        }

        $result['scanned']++;
        $path = $file->getPathname();
        $modifiedAt = $file->getMTime();

        if ($modifiedAt > $cutoff) {
            $result['skipped']++;
            continue;
        }

        if ($dryRun) {
            cleanupSessionLog('Would delete: ' . $path . ' modified=' . date('Y-m-d H:i:s', $modifiedAt));
            $result['deleted']++;
            continue;
        }

        if (@unlink($path)) {
            cleanupSessionLog('Deleted: ' . $path . ' modified=' . date('Y-m-d H:i:s', $modifiedAt));
            $result['deleted']++;
        } else {
            cleanupSessionLog('Failed to delete: ' . $path);
            $result['failed']++;
        }
    }

    return $result;
}

cleanupSessionLog('Starting session cleanup' . ($dryRun ? ' (dry-run)' : ''));

$totals = [
    'scanned' => 0,
    'deleted' => 0,
    'failed' => 0,
    'skipped' => 0,
];

foreach (array_unique($sessionDirs) as $dir) {
    $result = cleanupSessionDirectory($dir, $cutoff, $dryRun);
    foreach ($totals as $key => $_) {
        $totals[$key] += $result[$key];
    }
}

cleanupSessionLog(
    'Finished session cleanup: scanned=' . $totals['scanned']
    . ', ' . ($dryRun ? 'would_delete=' : 'deleted=') . $totals['deleted']
    . ', failed=' . $totals['failed']
    . ', skipped=' . $totals['skipped']
);

exit($totals['failed'] > 0 ? 1 : 0);
