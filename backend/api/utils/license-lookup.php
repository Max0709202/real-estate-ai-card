<?php
/**
 * MLIT宅建業者ライセンス検索 API (ローカルDB版)
 *
 * POST JSON expected: { "prefecture": "東京都", "renewal": "4", "registration": "12345" }
 *
 * Returns:
 *   Success: { "success": true, "data": { "company_name": "...", "address": "..." } }
 *   Not Found: { "success": false, "message": "Not found" }
 *
 * This version uses a local database instead of scraping MLIT website.
 * Import data using: php backend/scripts/import_licenses.php /path/to/data.csv
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    // Accept JSON or form-encoded
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) {
        $input = array_merge($_GET, $_POST);
    }

    $prefecture = trim($input['prefecture'] ?? '');
    $renewal = isset($input['renewal']) ? trim($input['renewal']) : '';
    $registration = trim($input['registration'] ?? '');

    // Validate required parameters
    if ($prefecture === '' || $renewal === '' || $registration === '') {
        sendErrorResponse('prefecture, renewal and registration are required', 400);
    }

    // Normalize renewal to integer
    $renewalInt = (int) preg_replace('/[^0-9]/', '', $renewal);

    // Normalize registration number (remove non-alphanumeric except hyphen)
    $registrationNorm = preg_replace('/[^0-9A-Za-z\-]/', '', $registration);

    // Connect to database
    $database = new Database();
    $db = $database->getConnection();

    // Check if table exists
    if (!tableExists($db, 'real_estate_licenses')) {
        sendErrorResponse('ライセンスデータベースが未設定です。管理者に連絡してください。', 503);
    }

    // Check if table has data
    $count = getTableCount($db);
    if ($count === 0) {
        sendErrorResponse('ライセンスデータがまだインポートされていません。', 503);
    }

    // Get prefecture code if available
    $prefCode = getPrefectureCode($db, $prefecture);

    // Strategy 1: Try exact match by prefecture_code + renewal + registration
    $row = null;
    if ($prefCode) {
        $row = lookupByComponents($db, $prefCode, $renewalInt, $registrationNorm);
    }

    // Strategy 2: Try by prefecture name + renewal + registration
    if (!$row) {
        $row = lookupByPrefectureName($db, $prefecture, $renewalInt, $registrationNorm);
    }

    // Strategy 3: Try by full license text
    if (!$row) {
        $row = lookupByFullLicenseText($db, $prefecture, $renewalInt, $registration);
    }

    // Strategy 4: Fuzzy match on registration number (handle leading zeros)
    if (!$row) {
        $row = lookupFuzzy($db, $prefecture, $prefCode, $renewalInt, $registration);
    }

    if ($row) {
        sendSuccessResponse([
            'company_name' => $row['company_name'] ?? '',
            'address' => $row['address'] ?? '',
            'representative_name' => $row['representative_name'] ?? '',
            'office_name' => $row['office_name'] ?? '',
            'phone_number' => $row['phone_number'] ?? '',
            'full_license_text' => $row['full_license_text'] ?? ''
        ]);
    } else {
        sendErrorResponse('該当する業者が見つかりませんでした。入力内容をご確認ください。', 404);
    }

} catch (PDOException $e) {
    error_log("License Lookup DB Error: " . $e->getMessage());
    // Check if it's a "table doesn't exist" error
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        sendErrorResponse('ライセンスデータベースが未設定です。', 503);
    }
    sendErrorResponse('データベースエラーが発生しました', 500);
} catch (Exception $e) {
    error_log("License Lookup Error: " . $e->getMessage());
    sendErrorResponse('Server error: ' . $e->getMessage(), 500);
}

/**
 * Check if table exists
 */
function tableExists($db, $tableName) {
    try {
        $stmt = $db->query("SHOW TABLES LIKE '{$tableName}'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get count of records in the licenses table
 */
function getTableCount($db) {
    try {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM real_estate_licenses");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Get prefecture code from name
 */
function getPrefectureCode($db, $prefectureName) {
    try {
        // First check if prefecture_codes table exists
        $stmt = $db->query("SHOW TABLES LIKE 'prefecture_codes'");
        if ($stmt->rowCount() === 0) {
            return mapPrefectureToCode($prefectureName);
        }

        $stmt = $db->prepare("SELECT code FROM prefecture_codes WHERE name = ? LIMIT 1");
        $stmt->execute([$prefectureName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['code'] : mapPrefectureToCode($prefectureName);
    } catch (Exception $e) {
        return mapPrefectureToCode($prefectureName);
    }
}

/**
 * Fallback prefecture code mapping
 */
function mapPrefectureToCode($prefectureName) {
    $codes = [
        '北海道' => '01', '青森県' => '02', '岩手県' => '03', '宮城県' => '04',
        '秋田県' => '05', '山形県' => '06', '福島県' => '07', '茨城県' => '08',
        '栃木県' => '09', '群馬県' => '10', '埼玉県' => '11', '千葉県' => '12',
        '東京都' => '13', '神奈川県' => '14', '新潟県' => '15', '富山県' => '16',
        '石川県' => '17', '福井県' => '18', '山梨県' => '19', '長野県' => '20',
        '岐阜県' => '21', '静岡県' => '22', '愛知県' => '23', '三重県' => '24',
        '滋賀県' => '25', '京都府' => '26', '大阪府' => '27', '兵庫県' => '28',
        '奈良県' => '29', '和歌山県' => '30', '鳥取県' => '31', '島根県' => '32',
        '岡山県' => '33', '広島県' => '34', '山口県' => '35', '徳島県' => '36',
        '香川県' => '37', '愛媛県' => '38', '高知県' => '39', '福岡県' => '40',
        '佐賀県' => '41', '長崎県' => '42', '熊本県' => '43', '大分県' => '44',
        '宮崎県' => '45', '鹿児島県' => '46', '沖縄県' => '47'
    ];
    return $codes[$prefectureName] ?? null;
}

/**
 * Lookup by prefecture code + renewal + registration components
 */
function lookupByComponents($db, $prefCode, $renewal, $registration) {
    // Try exact match first
    $stmt = $db->prepare("
        SELECT company_name, address, representative_name, office_name, phone_number, full_license_text
        FROM real_estate_licenses
        WHERE prefecture_code = ?
          AND renewal_number = ?
          AND registration_number = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$prefCode, $renewal, $registration]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Try with leading zeros stripped
        $regNoZeros = ltrim($registration, '0');
        if ($regNoZeros !== $registration && $regNoZeros !== '') {
            $stmt->execute([$prefCode, $renewal, $regNoZeros]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$row) {
        // Try with leading zeros added (6 digits)
        $regWithZeros = str_pad($registration, 6, '0', STR_PAD_LEFT);
        if ($regWithZeros !== $registration) {
            $stmt->execute([$prefCode, $renewal, $regWithZeros]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    return $row ?: null;
}

/**
 * Lookup by prefecture name + renewal + registration
 */
function lookupByPrefectureName($db, $prefecture, $renewal, $registration) {
    $stmt = $db->prepare("
        SELECT company_name, address, representative_name, office_name, phone_number, full_license_text
        FROM real_estate_licenses
        WHERE prefecture = ?
          AND renewal_number = ?
          AND registration_number = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$prefecture, $renewal, $registration]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Try with different registration formats
        $regNoZeros = ltrim($registration, '0');
        $regWithZeros = str_pad($registration, 6, '0', STR_PAD_LEFT);

        if ($regNoZeros !== $registration && $regNoZeros !== '') {
            $stmt->execute([$prefecture, $renewal, $regNoZeros]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row && $regWithZeros !== $registration) {
            $stmt->execute([$prefecture, $renewal, $regWithZeros]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    return $row ?: null;
}

/**
 * Lookup by full license text pattern
 * Format: {prefecture}知事({renewal})第{registration}号
 */
function lookupByFullLicenseText($db, $prefecture, $renewal, $registration) {
    // Build possible full license text patterns
    $patterns = [
        $prefecture . '知事(' . $renewal . ')第' . $registration . '号',
        $prefecture . '知事（' . $renewal . '）第' . $registration . '号', // Full-width parentheses
    ];

    // Also try with zero-padded registration
    $regWithZeros = str_pad($registration, 6, '0', STR_PAD_LEFT);
    if ($regWithZeros !== $registration) {
        $patterns[] = $prefecture . '知事(' . $renewal . ')第' . $regWithZeros . '号';
    }

    $placeholders = implode(',', array_fill(0, count($patterns), '?'));
    $stmt = $db->prepare("
        SELECT company_name, address, representative_name, office_name, phone_number, full_license_text
        FROM real_estate_licenses
        WHERE full_license_text IN ({$placeholders})
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute($patterns);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Fuzzy lookup - try LIKE matching on registration number
 */
function lookupFuzzy($db, $prefecture, $prefCode, $renewal, $registration) {
    // Normalize registration: extract numeric part
    $regNumeric = preg_replace('/[^0-9]/', '', $registration);
    if (empty($regNumeric)) {
        return null;
    }

    // Try LIKE match with the numeric part
    $likePattern = '%' . $regNumeric;

    $sql = "
        SELECT company_name, address, representative_name, office_name, phone_number, full_license_text
        FROM real_estate_licenses
        WHERE (prefecture = ? OR prefecture_code = ?)
          AND renewal_number = ?
          AND registration_number LIKE ?
          AND is_active = 1
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$prefecture, $prefCode ?? '', $renewal, $likePattern]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
