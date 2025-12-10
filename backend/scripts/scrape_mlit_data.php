<?php
/**
 * MLIT宅建業者データ スクレイパー
 *
 * このスクリプトはMLITウェブサイトから宅建業者データを取得し、
 * ローカルデータベースに保存します。
 *
 * Usage:
 *   php scrape_mlit_data.php                    # 全都道府県をスクレイプ
 *   php scrape_mlit_data.php --prefecture=東京都  # 特定の都道府県のみ
 *   php scrape_mlit_data.php --test              # テストモード（1都道府県のみ）
 *
 * 注意: MLITサーバーに負荷をかけないよう、リクエスト間に遅延を入れています。
 */

require_once __DIR__ . '/../config/database.php';

// Configuration
define('MLIT_BASE_URL', 'https://etsuran2.mlit.go.jp/TAKKEN/');
define('MLIT_SEARCH_URL', 'https://etsuran2.mlit.go.jp/TAKKEN/takkenKensaku.do');
define('REQUEST_DELAY', 2); // seconds between requests
define('MAX_PAGES_PER_PREFECTURE', 100); // safety limit

// Prefecture list with codes
$PREFECTURES = [
    '01' => '北海道', '02' => '青森県', '03' => '岩手県', '04' => '宮城県',
    '05' => '秋田県', '06' => '山形県', '07' => '福島県', '08' => '茨城県',
    '09' => '栃木県', '10' => '群馬県', '11' => '埼玉県', '12' => '千葉県',
    '13' => '東京都', '14' => '神奈川県', '15' => '新潟県', '16' => '富山県',
    '17' => '石川県', '18' => '福井県', '19' => '山梨県', '20' => '長野県',
    '21' => '岐阜県', '22' => '静岡県', '23' => '愛知県', '24' => '三重県',
    '25' => '滋賀県', '26' => '京都府', '27' => '大阪府', '28' => '兵庫県',
    '29' => '奈良県', '30' => '和歌山県', '31' => '鳥取県', '32' => '島根県',
    '33' => '岡山県', '34' => '広島県', '35' => '山口県', '36' => '徳島県',
    '37' => '香川県', '38' => '愛媛県', '39' => '高知県', '40' => '福岡県',
    '41' => '佐賀県', '42' => '長崎県', '43' => '熊本県', '44' => '大分県',
    '45' => '宮崎県', '46' => '鹿児島県', '47' => '沖縄県'
];

/**
 * Main execution
 */
function main() {
    global $PREFECTURES;

    $args = getopt('', ['prefecture:', 'test', 'help']);

    if (isset($args['help'])) {
        showUsage();
        exit(0);
    }

    $isTest = isset($args['test']);
    $targetPrefecture = $args['prefecture'] ?? null;

    echo "=== MLIT License Data Scraper ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    if ($isTest) echo "Mode: TEST (limited scraping)\n";
    if ($targetPrefecture) echo "Target: {$targetPrefecture}\n";
    echo "=================================\n\n";

    try {
        $database = new Database();
        $db = $database->getConnection();

        $stats = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'failed' => 0];

        // Filter prefectures if target specified
        $prefecturesToScrape = $PREFECTURES;
        if ($targetPrefecture) {
            $prefecturesToScrape = array_filter($PREFECTURES, function($name) use ($targetPrefecture) {
                return $name === $targetPrefecture;
            });
        }

        if ($isTest) {
            // Only scrape first prefecture in test mode
            $prefecturesToScrape = array_slice($prefecturesToScrape, 0, 1, true);
        }

        foreach ($prefecturesToScrape as $code => $name) {
            echo "\n[{$code}] {$name} を処理中...\n";

            $result = scrapePrefecture($db, $code, $name, $isTest);

            $stats['total'] += $result['total'];
            $stats['inserted'] += $result['inserted'];
            $stats['failed'] += $result['failed'];

            echo "  完了: {$result['total']} 件取得, {$result['inserted']} 件保存\n";

            // Delay between prefectures
            if (!$isTest) {
                sleep(REQUEST_DELAY * 2);
            }
        }

        echo "\n=== Scraping Complete ===\n";
        echo "Total records: {$stats['total']}\n";
        echo "Inserted: {$stats['inserted']}\n";
        echo "Failed: {$stats['failed']}\n";
        echo "Completed: " . date('Y-m-d H:i:s') . "\n";

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function showUsage() {
    echo <<<USAGE
MLIT License Data Scraper

Usage:
  php scrape_mlit_data.php [options]

Options:
  --prefecture=XX  Scrape only specified prefecture (e.g., --prefecture=東京都)
  --test           Test mode (scrape only 1 prefecture, limited pages)
  --help           Show this help

Examples:
  php scrape_mlit_data.php --test
  php scrape_mlit_data.php --prefecture=青森県
  php scrape_mlit_data.php

USAGE;
}

/**
 * Scrape data for a single prefecture
 */
function scrapePrefecture($db, $prefCode, $prefName, $isTest = false) {
    $stats = ['total' => 0, 'inserted' => 0, 'failed' => 0];

    // Initialize cookie jar
    $cookieFile = tempnam(sys_get_temp_dir(), 'mlit_cookie_');

    try {
        // Step 1: Get initial page to establish session
        $initialHtml = curlGet(MLIT_SEARCH_URL, $cookieFile);
        if (!$initialHtml) {
            throw new Exception("Failed to load initial page");
        }

        sleep(REQUEST_DELAY);

        // Step 2: Parse form to get field names and action
        $formData = parseSearchForm($initialHtml);
        if (!$formData) {
            throw new Exception("Failed to parse search form");
        }

        // Step 3: Build search request for this prefecture
        $postData = buildSearchRequest($formData, $prefCode, $prefName);

        // Step 4: Submit search
        $actionUrl = MLIT_BASE_URL . ltrim($formData['action'], '/');
        $resultHtml = curlPost($actionUrl, $postData, $cookieFile);

        if (!$resultHtml) {
            throw new Exception("Failed to submit search");
        }

        // Step 5: Parse results
        $records = parseResultsTable($resultHtml, $prefCode, $prefName);
        $stats['total'] = count($records);

        // Step 6: Save to database
        foreach ($records as $record) {
            try {
                saveRecord($db, $record);
                $stats['inserted']++;
            } catch (Exception $e) {
                $stats['failed']++;
                error_log("Failed to save record: " . $e->getMessage());
            }
        }

        // Pagination: check for more pages (limited in test mode)
        $pageCount = 1;
        $maxPages = $isTest ? 2 : MAX_PAGES_PER_PREFECTURE;

        while ($pageCount < $maxPages) {
            $nextPageHtml = getNextPage($resultHtml, $cookieFile, $actionUrl);
            if (!$nextPageHtml) break;

            sleep(REQUEST_DELAY);

            $moreRecords = parseResultsTable($nextPageHtml, $prefCode, $prefName);
            if (empty($moreRecords)) break;

            $stats['total'] += count($moreRecords);

            foreach ($moreRecords as $record) {
                try {
                    saveRecord($db, $record);
                    $stats['inserted']++;
                } catch (Exception $e) {
                    $stats['failed']++;
                }
            }

            $resultHtml = $nextPageHtml;
            $pageCount++;
            echo "    Page {$pageCount}: " . count($moreRecords) . " records\n";
        }

    } finally {
        @unlink($cookieFile);
    }

    return $stats;
}

/**
 * cURL GET request
 */
function curlGet($url, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_ENCODING => '',
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200) ? $html : false;
}

/**
 * cURL POST request
 */
function curlPost($url, $postData, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml',
        ],
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200) ? $html : false;
}

/**
 * Parse search form
 */
function parseSearchForm($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $forms = $dom->getElementsByTagName('form');

    foreach ($forms as $form) {
        $action = $form->getAttribute('action');
        if (empty($action)) continue;

        $inputs = [];
        $inputNodes = $xpath->query('.//input | .//select', $form);

        foreach ($inputNodes as $node) {
            $name = $node->getAttribute('name');
            if (empty($name)) continue;

            $value = $node->getAttribute('value') ?? '';
            $inputs[$name] = $value;
        }

        if (!empty($inputs)) {
            return ['action' => $action, 'inputs' => $inputs];
        }
    }

    return null;
}

/**
 * Build search request for prefecture
 */
function buildSearchRequest($formData, $prefCode, $prefName) {
    $postData = $formData['inputs'] ?? [];

    // Set prefecture-related fields
    $prefFields = ['todofuken', 'todouhuken', 'pref', 'kenCd', 'todouhukenCd'];
    foreach ($prefFields as $field) {
        if (isset($postData[$field]) || in_array($field, array_keys($postData))) {
            $postData[$field] = $prefCode;
        }
    }

    // If no prefecture field found, add common one
    if (!isset($postData['todofuken'])) {
        $postData['todofuken'] = $prefCode;
    }

    return $postData;
}

/**
 * Parse results table from HTML
 */
function parseResultsTable($html, $prefCode, $prefName) {
    $records = [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);

    if (mb_detect_encoding($html, 'UTF-8', true) !== 'UTF-8') {
        $html = mb_convert_encoding($html, 'UTF-8', 'auto');
    }

    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Find tables that contain license data
    $tables = $xpath->query("//table");

    foreach ($tables as $table) {
        $tableHtml = $dom->saveHTML($table);

        // Check if this looks like a results table
        if (stripos($tableHtml, '商号') === false && stripos($tableHtml, '所在地') === false) {
            continue;
        }

        $rows = $xpath->query(".//tr", $table);

        foreach ($rows as $rowIndex => $row) {
            // Skip header row
            if ($rowIndex === 0) continue;

            $cells = $xpath->query(".//td", $row);
            if ($cells->length < 4) continue;

            $record = parseTableRow($cells, $prefCode, $prefName);
            if ($record) {
                $records[] = $record;
            }
        }

        if (!empty($records)) break; // Found results table
    }

    return $records;
}

/**
 * Parse a single table row
 */
function parseTableRow($cells, $prefCode, $prefName) {
    // Expected columns: No, 免許行政庁, 免許証番号, 商号又は名称, 代表者名, 事務所名, 所在地
    // But the order might vary

    $data = [];
    foreach ($cells as $i => $cell) {
        $data[$i] = trim($cell->textContent);
    }

    // Try to identify columns by content
    $record = [
        'prefecture' => $prefName,
        'prefecture_code' => $prefCode,
        'issuing_authority' => null,
        'renewal_number' => null,
        'registration_number' => null,
        'company_name' => null,
        'representative_name' => null,
        'office_name' => null,
        'address' => null,
    ];

    foreach ($data as $i => $value) {
        // License number pattern: (N)第XXXXX号
        if (preg_match('/\((\d+)\)第(\d+)号/', $value, $matches)) {
            $record['renewal_number'] = (int)$matches[1];
            $record['registration_number'] = $matches[2];
        }
        // Company name (contains 株式会社, etc.)
        elseif (preg_match('/(株式会社|有限会社|合同会社|合資会社)/', $value)) {
            $record['company_name'] = $value;
        }
        // Address (contains prefecture + 市/区/町/村)
        elseif (preg_match('/[都道府県].+[市区町村]/', $value)) {
            $record['address'] = $value;
        }
        // Issuing authority (contains 知事 or 大臣)
        elseif (preg_match('/(知事|大臣)/', $value)) {
            $record['issuing_authority'] = $value;
        }
        // Office name (本店, 支店, etc.)
        elseif (preg_match('/^(本店|支店|.+事務所)$/', $value)) {
            $record['office_name'] = $value;
        }
        // Representative name (short, contains name-like characters)
        elseif (mb_strlen($value) > 1 && mb_strlen($value) < 20 && !preg_match('/[0-9]/', $value)) {
            if (!$record['representative_name'] && !$record['company_name']) {
                // Could be representative name
            }
        }
    }

    // Build full license text
    if ($record['renewal_number'] && $record['registration_number']) {
        $authority = $record['issuing_authority'] ?? ($prefName . '知事');
        $record['full_license_text'] = $authority . '(' . $record['renewal_number'] . ')第' . $record['registration_number'] . '号';
    }

    // Only return if we have essential data
    if ($record['registration_number'] && ($record['company_name'] || $record['address'])) {
        return $record;
    }

    return null;
}

/**
 * Check for and get next page
 */
function getNextPage($html, $cookieFile, $baseUrl) {
    // Look for pagination links
    if (preg_match('/次へ|次ページ|Next/', $html)) {
        // This would need to be implemented based on MLIT's actual pagination
        // For now, return false to indicate no more pages
        return false;
    }
    return false;
}

/**
 * Save record to database
 */
function saveRecord($db, $record) {
    $sql = "INSERT INTO real_estate_licenses
            (prefecture, prefecture_code, issuing_authority, renewal_number, registration_number,
             full_license_text, company_name, representative_name, office_name, address, data_source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'mlit_scrape')
            ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                representative_name = VALUES(representative_name),
                office_name = VALUES(office_name),
                address = VALUES(address),
                full_license_text = VALUES(full_license_text),
                updated_at = CURRENT_TIMESTAMP";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $record['prefecture'],
        $record['prefecture_code'],
        $record['issuing_authority'],
        $record['renewal_number'],
        $record['registration_number'],
        $record['full_license_text'],
        $record['company_name'],
        $record['representative_name'],
        $record['office_name'],
        $record['address'],
    ]);
}

// Run main function
main();

