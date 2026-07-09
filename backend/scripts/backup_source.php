<?php
/**
 * Creates a source-code archive outside public_html.
 *
 * Usage:
 *   php backend/scripts/backup_source.php
 *   php backend/scripts/backup_source.php --keep-days=35
 */

$keepDays = 35;
$backupRoot = dirname(__DIR__, 3) . '/backups/source';

foreach ($argv as $arg) {
    if (strpos($arg, '--keep-days=') === 0) {
        $keepDays = max(1, (int) substr($arg, strlen('--keep-days=')));
    }
    if (strpos($arg, '--backup-dir=') === 0) {
        $backupRoot = substr($arg, strlen('--backup-dir='));
    }
}

if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) {
    fwrite(STDERR, "Failed to create backup directory: {$backupRoot}\n");
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$timestamp = date('Ymd_His');
$archiveFile = sprintf('%s/source_%s.tar.gz', rtrim($backupRoot, '/'), $timestamp);

chdir($repoRoot);
exec('git rev-parse --is-inside-work-tree 2>/dev/null', $gitCheck, $gitExitCode);

if ($gitExitCode === 0 && trim($gitCheck[0] ?? '') === 'true') {
    exec('git diff-index --quiet HEAD -- 2>/dev/null', $dirtyOutput, $dirtyExitCode);
    if ($dirtyExitCode === 0) {
        $command = sprintf('git archive --format=tar.gz -o %s HEAD', escapeshellarg($archiveFile));
    } else {
        $command = sprintf(
            'tar --exclude=.git --exclude=sessions --exclude=backend/logs --exclude=backend/uploads --exclude=backend/uploads_quarantine -czf %s .',
            escapeshellarg($archiveFile)
        );
    }
} else {
    $command = sprintf(
        'tar --exclude=.git --exclude=sessions --exclude=backend/logs --exclude=backend/uploads --exclude=backend/uploads_quarantine -czf %s .',
        escapeshellarg($archiveFile)
    );
}

exec($command, $output, $exitCode);
if ($exitCode !== 0 || !is_file($archiveFile) || filesize($archiveFile) === 0) {
    @unlink($archiveFile);
    fwrite(STDERR, "Source backup failed.\n");
    exit(1);
}

$hash = hash_file('sha256', $archiveFile);
file_put_contents($archiveFile . '.sha256', $hash . '  ' . basename($archiveFile) . PHP_EOL);

$cutoff = time() - ($keepDays * 86400);
foreach (glob(rtrim($backupRoot, '/') . '/source_*.tar.gz*') ?: [] as $file) {
    if (is_file($file) && filemtime($file) < $cutoff) {
        @unlink($file);
    }
}

printf("Source backup created: %s\nSHA256: %s\n", $archiveFile, $hash);
