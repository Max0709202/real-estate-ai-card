<?php
/**
 * Parse MLIT snapshot file and extract license data
 * More precise parsing using cell structure
 */

$snapshotFile = $argv[1] ?? 'C:\Users\Administrator\.cursor\browser-logs\snapshot-2025-12-04T03-22-41-671Z.log';
$outputFile = __DIR__ . '/../data/mlit_real_data.csv';

echo "Parsing snapshot: $snapshotFile\n";

$lines = file($snapshotFile, FILE_IGNORE_NEW_LINES);
if (!$lines) {
    die("Cannot read snapshot file\n");
}

$records = [];
$currentRow = null;
$cellIndex = 0;

foreach ($lines as $line) {
    // Start of a data row (contains all data in name)
    if (preg_match('/^\s*-\s*role:\s*row\s*$/', $line)) {
        if ($currentRow && count($currentRow) >= 7) {
            $records[] = $currentRow;
        }
        $currentRow = [];
        $cellIndex = 0;
        continue;
    }

    // Cell content
    if (preg_match('/^\s*-\s*role:\s*cell\s*$/', $line)) {
        $cellIndex++;
        continue;
    }

    // Extract name from cell
    if ($currentRow !== null && preg_match('/^\s*name:\s*(.+)\s*$/', $line, $m)) {
        $value = trim($m[1]);

        // Skip header cells
        if (in_array($value, ['No.', '免許行政庁', '免許証番号', '商号又は名称', '代表者名', '事務所名', '所在地'])) {
            $currentRow = null;
            continue;
        }

        // Skip link duplicates (they appear right after cell names)
        if (isset($currentRow['company']) && $value === $currentRow['company']) {
            continue;
        }

        switch ($cellIndex) {
            case 1: $currentRow['row_num'] = $value; break;
            case 2: $currentRow['prefecture'] = $value; break;
            case 3: $currentRow['license'] = $value; break;
            case 4: $currentRow['company'] = $value; break;
            case 5: $currentRow['representative'] = $value; break;
            case 6: $currentRow['office'] = $value; break;
            case 7: $currentRow['address'] = $value; break;
        }
    }
}

// Add last row
if ($currentRow && count($currentRow) >= 7) {
    $records[] = $currentRow;
}

echo "Found " . count($records) . " records\n";

// Prefecture to code mapping
$prefCodes = [
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

// Open CSV for writing
$fp = fopen($outputFile, 'w');
fputcsv($fp, [
    'prefecture', 'prefecture_code', 'renewal_number', 'registration_number',
    'company_name', 'representative_name', 'office_name', 'address', 'phone_number'
]);

foreach ($records as $rec) {
    // Parse prefecture (remove sub-region like （石狩）)
    $prefecture = preg_replace('/（.+）/', '', $rec['prefecture'] ?? '');

    // Get prefecture code
    $prefCode = $prefCodes[$prefecture] ?? '';
    if (empty($prefCode)) {
        foreach ($prefCodes as $name => $code) {
            if (strpos($rec['prefecture'], $name) !== false) {
                $prefCode = $code;
                $prefecture = $name;
                break;
            }
        }
    }

    // Parse license: (16)第000252号 -> renewal=16, registration=000252
    $renewal = '';
    $registration = '';
    if (preg_match('/\((\d+)\)第(\d+)号/', $rec['license'] ?? '', $m)) {
        $renewal = $m[1];
        $registration = $m[2];
    }

    fputcsv($fp, [
        $prefecture,
        $prefCode,
        $renewal,
        $registration,
        $rec['company'] ?? '',
        $rec['representative'] ?? '',
        $rec['office'] ?? '',
        $rec['address'] ?? '',
        '' // phone number
    ]);
}

fclose($fp);
echo "Wrote " . count($records) . " records to $outputFile\n";
