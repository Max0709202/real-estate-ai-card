<?php
/**
 * Run database migration for real_estate_licenses table
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Running License Database Migration ===\n";

try {
    $database = new Database();
    $db = $database->getConnection();

    $sqlFile = __DIR__ . '/../database/migrations/create_real_estate_licenses_table.sql';

    if (!file_exists($sqlFile)) {
        die("Error: Migration file not found: {$sqlFile}\n");
    }

    $sql = file_get_contents($sqlFile);

    // Split by semicolon but handle the INSERT statements carefully
    $statements = [];
    $current = '';
    $lines = explode("\n", $sql);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }

        $current .= ' ' . $line;

        // Check if this is the end of a statement
        if (substr($line, -1) === ';') {
            $statements[] = trim($current);
            $current = '';
        }
    }

    if (!empty(trim($current))) {
        $statements[] = trim($current);
    }

    $executed = 0;
    $errors = 0;

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;

        try {
            $db->exec($stmt);
            $executed++;

            // Show what was executed
            $preview = substr($stmt, 0, 60);
            echo "OK: {$preview}...\n";
        } catch (PDOException $e) {
            // Ignore "table already exists" and "duplicate entry" errors
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "SKIP: Table/data already exists\n";
            } else {
                echo "ERROR: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }

    echo "\n=== Migration Complete ===\n";
    echo "Statements executed: {$executed}\n";
    echo "Errors: {$errors}\n";

    // Check results
    $tables = ['real_estate_licenses', 'license_import_logs', 'prefecture_codes'];
    echo "\nTable status:\n";
    foreach ($tables as $table) {
        $check = $db->query("SHOW TABLES LIKE '{$table}'");
        $exists = $check->rowCount() > 0;
        echo "  {$table}: " . ($exists ? "✓ exists" : "✗ missing") . "\n";
    }

    // Check prefecture codes count
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM prefecture_codes");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nPrefecture codes loaded: {$row['cnt']}\n";

} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

