<?php
/**
 * MLIT宅建業者ライセンスデータ インポートスクリプト
 *
 * Usage:
 *   php import_licenses.php /path/to/licenses.csv
 *   php import_licenses.php /path/to/licenses.csv --full    (全件置換)
 *   php import_licenses.php /path/to/licenses.csv --update  (差分更新、デフォルト)
 *
 * Expected CSV format:
 *   prefecture,prefecture_code,renewal_number,registration_number,company_name,representative_name,office_name,address,phone_number
 *
 * Example row:
 *   青森県,02,15,000586,三和興業株式会社,吉町 敦子,本店,青森県青森市橋本1－9－14,017-XXX-XXXX
 */

require_once __DIR__ . '/../config/database.php';

// Configuration
define('BATCH_SIZE', 500);
define('LOG_INTERVAL', 1000);

/**
 * Main execution
 */
function main() {
    $args = getopt('', ['full', 'update', 'help']);

    if (isset($args['help']) || $GLOBALS['argc'] < 2) {
        showUsage();
        exit(0);
    }

    $csvPath = $GLOBALS['argv'][1];
    $isFullReplace = isset($args['full']);

    if (!file_exists($csvPath)) {
        echo "Error: CSV file not found: {$csvPath}\n";
        exit(1);
    }

    echo "=== MLIT License Data Import ===\n";
    echo "File: {$csvPath}\n";
    echo "Mode: " . ($isFullReplace ? "Full Replace" : "Incremental Update") . "\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo "================================\n\n";

    try {
        $database = new Database();
        $db = $database->getConnection();

        // Create import log entry
        $logId = createImportLog($db, $csvPath, $isFullReplace ? 'full' : 'incremental');

        // Load prefecture codes for mapping
        $prefectureCodes = loadPrefectureCodes($db);

        // Process CSV
        $stats = processCSV($db, $csvPath, $prefectureCodes, $isFullReplace);

        // Update import log
        updateImportLog($db, $logId, $stats, 'completed');

        echo "\n=== Import Complete ===\n";
        echo "Processed: {$stats['processed']}\n";
        echo "Inserted:  {$stats['inserted']}\n";
        echo "Updated:   {$stats['updated']}\n";
        echo "Failed:    {$stats['failed']}\n";
        echo "Completed: " . date('Y-m-d H:i:s') . "\n";

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        if (isset($logId)) {
            updateImportLog($db, $logId, ['error' => $e->getMessage()], 'failed');
        }
        exit(1);
    }
}

/**
 * Show usage instructions
 */
function showUsage() {
    echo <<<USAGE
MLIT License Data Import Script

Usage:
  php import_licenses.php <csv_file> [options]

Options:
  --full    Full replace mode (truncates existing data)
  --update  Incremental update mode (default)
  --help    Show this help message

CSV Format (with header row):
  prefecture,prefecture_code,renewal_number,registration_number,company_name,representative_name,office_name,address,phone_number

Example:
  php import_licenses.php /tmp/mlit_licenses.csv --update

USAGE;
}

/**
 * Load prefecture codes mapping
 */
function loadPrefectureCodes($db) {
    $stmt = $db->query("SELECT code, name, authority_name FROM prefecture_codes");
    $codes = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $codes[$row['name']] = $row;
        $codes[$row['code']] = $row;
    }
    return $codes;
}

/**
 * Create import log entry
 */
function createImportLog($db, $fileName, $importType) {
    $stmt = $db->prepare("
        INSERT INTO license_import_logs (import_type, file_name, status, started_at)
        VALUES (?, ?, 'running', NOW())
    ");
    $stmt->execute([$importType, basename($fileName)]);
    return $db->lastInsertId();
}

/**
 * Update import log
 */
function updateImportLog($db, $logId, $stats, $status) {
    $sql = "UPDATE license_import_logs SET
        status = ?,
        records_processed = ?,
        records_inserted = ?,
        records_updated = ?,
        records_failed = ?,
        error_message = ?,
        completed_at = NOW()
        WHERE id = ?";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $status,
        $stats['processed'] ?? 0,
        $stats['inserted'] ?? 0,
        $stats['updated'] ?? 0,
        $stats['failed'] ?? 0,
        $stats['error'] ?? null,
        $logId
    ]);
}

/**
 * Process CSV file
 */
function processCSV($db, $csvPath, $prefectureCodes, $isFullReplace) {
    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        throw new Exception("Cannot open CSV file: {$csvPath}");
    }

    // Detect BOM and skip if present
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // Read header
    $header = fgetcsv($handle);
    if (!$header) {
        throw new Exception("CSV file is empty or invalid");
    }

    // Normalize header names
    $header = array_map('trim', $header);
    $header = array_map('strtolower', $header);

    // Map column indices
    $columnMap = mapColumns($header);

    // If full replace, truncate table
    if ($isFullReplace) {
        echo "Truncating existing data...\n";
        $db->exec("TRUNCATE TABLE real_estate_licenses");
    }

    $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'failed' => 0];
    $rows = [];
    $lineNumber = 1;

    while (($data = fgetcsv($handle)) !== false) {
        $lineNumber++;

        try {
            $row = parseRow($data, $columnMap, $prefectureCodes);
            if ($row) {
                $rows[] = $row;
            }
        } catch (Exception $e) {
            echo "Warning: Line {$lineNumber}: " . $e->getMessage() . "\n";
            $stats['failed']++;
        }

        // Batch insert
        if (count($rows) >= BATCH_SIZE) {
            $result = insertBatch($db, $rows);
            $stats['inserted'] += $result['inserted'];
            $stats['updated'] += $result['updated'];
            $stats['processed'] += count($rows);
            $rows = [];

            if ($stats['processed'] % LOG_INTERVAL === 0) {
                echo "Processed: {$stats['processed']} records...\n";
            }
        }
    }

    // Insert remaining rows
    if (count($rows) > 0) {
        $result = insertBatch($db, $rows);
        $stats['inserted'] += $result['inserted'];
        $stats['updated'] += $result['updated'];
        $stats['processed'] += count($rows);
    }

    fclose($handle);
    return $stats;
}

/**
 * Map CSV column names to expected fields
 */
function mapColumns($header) {
    $map = [];

    $aliases = [
        'prefecture' => ['prefecture', '都道府県', 'pref', 'todofuken'],
        'prefecture_code' => ['prefecture_code', '都道府県コード', 'pref_code', 'code'],
        'renewal_number' => ['renewal_number', '更新回数', 'renewal', 'koshin', '免許回数'],
        'registration_number' => ['registration_number', '登録番号', 'registration', 'toroku', '免許番号'],
        'company_name' => ['company_name', '商号又は名称', '商号', '名称', 'company', 'name'],
        'representative_name' => ['representative_name', '代表者名', '代表者', 'representative'],
        'office_name' => ['office_name', '事務所名', 'office', '事務所'],
        'address' => ['address', '所在地', '住所', 'location'],
        'phone_number' => ['phone_number', '電話番号', 'phone', 'tel'],
    ];

    foreach ($aliases as $field => $possibleNames) {
        foreach ($possibleNames as $name) {
            $index = array_search(strtolower($name), $header);
            if ($index !== false) {
                $map[$field] = $index;
                break;
            }
        }
    }

    return $map;
}

/**
 * Parse a single CSV row
 */
function parseRow($data, $columnMap, $prefectureCodes) {
    $get = function($field) use ($data, $columnMap) {
        if (!isset($columnMap[$field])) return null;
        $val = $data[$columnMap[$field]] ?? null;
        return $val !== null ? trim($val) : null;
    };

    $prefecture = $get('prefecture');
    $prefCode = $get('prefecture_code');
    $renewal = $get('renewal_number');
    $registration = $get('registration_number');
    $company = $get('company_name');
    $address = $get('address');

    // Skip if essential fields are missing
    if (empty($prefecture) && empty($prefCode)) {
        return null;
    }
    if (empty($registration)) {
        return null;
    }

    // Normalize prefecture code
    if (empty($prefCode) && isset($prefectureCodes[$prefecture])) {
        $prefCode = $prefectureCodes[$prefecture]['code'];
    }
    if (empty($prefecture) && isset($prefectureCodes[$prefCode])) {
        $prefecture = $prefectureCodes[$prefCode]['name'];
    }

    // Determine issuing authority
    $authority = null;
    if (isset($prefectureCodes[$prefecture])) {
        $authority = $prefectureCodes[$prefecture]['authority_name'];
    } elseif (isset($prefectureCodes[$prefCode])) {
        $authority = $prefectureCodes[$prefCode]['authority_name'];
    }

    // Normalize registration number (remove leading zeros for matching, but keep original)
    $registrationNorm = preg_replace('/^0+/', '', $registration);
    if (empty($registrationNorm)) {
        $registrationNorm = $registration; // Keep if all zeros
    }

    // Build full license text: {authority}({renewal})第{registration}号
    $fullLicense = null;
    if ($authority && $renewal !== null) {
        $fullLicense = $authority . '(' . $renewal . ')第' . $registration . '号';
    }

    return [
        'prefecture' => $prefecture,
        'prefecture_code' => $prefCode,
        'issuing_authority' => $authority,
        'renewal_number' => $renewal !== null ? (int)$renewal : null,
        'registration_number' => $registration,
        'full_license_text' => $fullLicense,
        'company_name' => $company,
        'representative_name' => $get('representative_name'),
        'office_name' => $get('office_name'),
        'address' => $address,
        'phone_number' => $get('phone_number'),
        'raw_source' => implode(',', $data),
    ];
}

/**
 * Batch insert rows with ON DUPLICATE KEY UPDATE
 */
function insertBatch($db, array $rows) {
    if (empty($rows)) {
        return ['inserted' => 0, 'updated' => 0];
    }

    $columns = [
        'prefecture', 'prefecture_code', 'issuing_authority',
        'renewal_number', 'registration_number', 'full_license_text',
        'company_name', 'representative_name', 'office_name',
        'address', 'phone_number', 'raw_source', 'data_source'
    ];

    $placeholders = [];
    $values = [];

    foreach ($rows as $r) {
        $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $values[] = $r['prefecture'];
        $values[] = $r['prefecture_code'];
        $values[] = $r['issuing_authority'];
        $values[] = $r['renewal_number'];
        $values[] = $r['registration_number'];
        $values[] = $r['full_license_text'];
        $values[] = $r['company_name'];
        $values[] = $r['representative_name'];
        $values[] = $r['office_name'];
        $values[] = $r['address'];
        $values[] = $r['phone_number'];
        $values[] = $r['raw_source'];
        $values[] = 'mlit_csv';
    }

    $sql = "INSERT INTO real_estate_licenses (" . implode(',', $columns) . ")
            VALUES " . implode(',', $placeholders) . "
            ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                representative_name = VALUES(representative_name),
                office_name = VALUES(office_name),
                address = VALUES(address),
                phone_number = VALUES(phone_number),
                full_license_text = VALUES(full_license_text),
                issuing_authority = VALUES(issuing_authority),
                raw_source = VALUES(raw_source),
                updated_at = CURRENT_TIMESTAMP";

    $stmt = $db->prepare($sql);
    $stmt->execute($values);

    $affected = $stmt->rowCount();
    // In MySQL: 1 = inserted, 2 = updated (for ON DUPLICATE KEY UPDATE)
    // This is an approximation
    return [
        'inserted' => $affected,
        'updated' => 0
    ];
}

// Run main function
main();

