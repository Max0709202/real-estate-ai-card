<?php
/**
 * MLIT Takken License Scraper using cURL
 *
 * Usage: php scrape_mlit_curl.php [prefecture_code] [start_page] [end_page]
 * Example: php scrape_mlit_curl.php 13 1 10   (scrape Tokyo pages 1-10)
 *
 * Note: Be respectful of MLIT servers. Add delays between requests.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$prefCode = $argv[1] ?? '13'; // Default: Tokyo
$startPage = (int)($argv[2] ?? 1);
$endPage = (int)($argv[3] ?? 5);
$outputFile = __DIR__ . '/../data/mlit_scraped_' . $prefCode . '.csv';

$baseUrl = 'https://etsuran2.mlit.go.jp/TAKKEN/takkenKensaku.do';
$cookieFile = sys_get_temp_dir() . '/mlit_cookies_' . time() . '.txt';

// Prefecture names
$prefNames = [
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
    '41' => '佐賀県', '42' => '長崎県', '43' => '大分県', '44' => '宮崎県',
    '45' => '鹿児島県', '46' => '沖縄県'
];

echo "MLIT Takken License Scraper\n";
echo "Prefecture: " . ($prefNames[$prefCode] ?? $prefCode) . " (code: $prefCode)\n";
echo "Pages: $startPage to $endPage\n";
echo "Output: $outputFile\n\n";

// Step 1: GET initial page to establish session
echo "Establishing session...\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $baseUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_SSL_VERIFYPEER => false
]);
$html = curl_exec($ch);
if (!$html) {
    die("Failed to connect: " . curl_error($ch) . "\n");
}
echo "Session established.\n";

// Parse form fields
$formFields = parseFormFields($html);
echo "Found " . count($formFields) . " form fields\n";

// Step 2: Submit search
echo "Submitting search for prefecture $prefCode...\n";

$postData = [
    'menkyo_pref_no' => $prefCode,
    'menkyo_gyosha_no_from' => '1',
    'menkyo_gyosha_no_to' => '999999',
    'pageID' => 'takkenKensakuAction'
];

// Add hidden fields
foreach ($formFields as $name => $value) {
    if (!isset($postData[$name])) {
        $postData[$name] = $value;
    }
}

curl_setopt_array($ch, [
    CURLOPT_URL => $baseUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($postData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Referer: ' . $baseUrl
    ]
]);

$resultHtml = curl_exec($ch);
if (!$resultHtml) {
    die("Search failed: " . curl_error($ch) . "\n");
}

// Save for debugging
file_put_contents(__DIR__ . '/mlit_debug.html', $resultHtml);
echo "Search results saved to mlit_debug.html\n";

// Parse results
$records = parseResults($resultHtml, $prefCode, $prefNames);
echo "Found " . count($records) . " records on page 1\n";

// Navigate through pages
for ($page = 2; $page <= $endPage; $page++) {
    sleep(2); // Be nice to the server
    echo "Fetching page $page...\n";

    $pageData = $postData;
    $pageData['listPosition'] = ($page - 1) * 10;

    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($pageData));
    $pageHtml = curl_exec($ch);

    if ($pageHtml) {
        $pageRecords = parseResults($pageHtml, $prefCode, $prefNames);
        $records = array_merge($records, $pageRecords);
        echo "  Found " . count($pageRecords) . " records\n";
    }
}

curl_close($ch);

// Write CSV
$fp = fopen($outputFile, 'w');
fputcsv($fp, [
    'prefecture', 'prefecture_code', 'renewal_number', 'registration_number',
    'company_name', 'representative_name', 'office_name', 'address', 'phone_number'
]);

foreach ($records as $rec) {
    fputcsv($fp, [
        $rec['prefecture'],
        $rec['prefecture_code'],
        $rec['renewal'],
        $rec['registration'],
        $rec['company'],
        $rec['representative'],
        $rec['office'],
        $rec['address'],
        ''
    ]);
}
fclose($fp);

echo "\nTotal records: " . count($records) . "\n";
echo "Saved to: $outputFile\n";

// Cleanup
@unlink($cookieFile);

function parseFormFields($html) {
    $fields = [];
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Get hidden inputs
    $inputs = $xpath->query("//input[@type='hidden']");
    foreach ($inputs as $input) {
        $name = $input->getAttribute('name');
        $value = $input->getAttribute('value');
        if ($name) {
            $fields[$name] = $value;
        }
    }
    return $fields;
}

function parseResults($html, $prefCode, $prefNames) {
    $records = [];
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    // Find table rows
    $rows = $xpath->query("//table[@class='searchresult']//tr");

    foreach ($rows as $row) {
        $cells = $xpath->query(".//td", $row);
        if ($cells->length < 7) continue;

        $prefDisplay = trim($cells->item(1)->textContent);
        $license = trim($cells->item(2)->textContent);
        $company = trim($cells->item(3)->textContent);
        $rep = trim($cells->item(4)->textContent);
        $office = trim($cells->item(5)->textContent);
        $address = trim($cells->item(6)->textContent);

        // Parse license: (16)第000252号
        $renewal = '';
        $registration = '';
        if (preg_match('/\((\d+)\)第(\d+)号/', $license, $m)) {
            $renewal = $m[1];
            $registration = $m[2];
        }

        // Clean prefecture
        $prefecture = preg_replace('/（.+）/', '', $prefDisplay);
        if (empty($prefecture) && isset($prefNames[$prefCode])) {
            $prefecture = $prefNames[$prefCode];
        }

        if (!empty($company) && $company !== '商号又は名称') {
            $records[] = [
                'prefecture' => $prefecture,
                'prefecture_code' => $prefCode,
                'renewal' => $renewal,
                'registration' => $registration,
                'company' => $company,
                'representative' => $rep,
                'office' => $office,
                'address' => $address
            ];
        }
    }

    return $records;
}

