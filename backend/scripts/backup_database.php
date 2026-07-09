<?php
/**
 * Creates a gzipped MySQL backup outside public_html.
 *
 * Usage:
 *   php backend/scripts/backup_database.php
 *   php backend/scripts/backup_database.php --keep-days=35
 */

require_once __DIR__ . '/../config/database.php';

function optionValue(array $argv, string $name, string $default): string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $name . '=') === 0) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return $default;
}

function databaseConfigValue(Database $database, string $property): string
{
    $ref = new ReflectionClass($database);
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);

    return (string) $prop->getValue($database);
}

$keepDays = max(1, (int) optionValue($argv, '--keep-days', '35'));
$backupRoot = optionValue($argv, '--backup-dir', dirname(__DIR__, 3) . '/backups/db');
$timestamp = date('Ymd_His');

if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) {
    fwrite(STDERR, "Failed to create backup directory: {$backupRoot}\n");
    exit(1);
}

$database = new Database();
$host = databaseConfigValue($database, 'host');
$dbName = databaseConfigValue($database, 'db_name');
$username = databaseConfigValue($database, 'username');
$password = databaseConfigValue($database, 'password');

$dumpFile = sprintf('%s/%s_%s.sql.gz', rtrim($backupRoot, '/'), $dbName, $timestamp);
$command = sprintf(
    'MYSQL_PWD=%s mysqldump --single-transaction --routines --triggers --events --default-character-set=utf8mb4 -h %s -u %s %s | gzip -9 > %s',
    escapeshellarg($password),
    escapeshellarg($host),
    escapeshellarg($username),
    escapeshellarg($dbName),
    escapeshellarg($dumpFile)
);

exec($command, $output, $exitCode);
if ($exitCode !== 0 || !is_file($dumpFile) || filesize($dumpFile) === 0) {
    @unlink($dumpFile);
    fwrite(STDERR, "Database backup failed.\n");
    exit(1);
}

$hash = hash_file('sha256', $dumpFile);
file_put_contents($dumpFile . '.sha256', $hash . '  ' . basename($dumpFile) . PHP_EOL);

$cutoff = time() - ($keepDays * 86400);
foreach (glob(rtrim($backupRoot, '/') . '/' . $dbName . '_*.sql.gz*') ?: [] as $file) {
    if (is_file($file) && filemtime($file) < $cutoff) {
        @unlink($file);
    }
}

printf("Database backup created: %s\nSHA256: %s\n", $dumpFile, $hash);
