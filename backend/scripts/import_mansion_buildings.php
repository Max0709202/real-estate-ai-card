<?php
/**
 * Import mansion building master data from XLSX into mansion_buildings.
 * Usage: php backend/scripts/import_mansion_buildings.php [xlsx_path]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$file = $argv[1] ?? (__DIR__ . '/../../assets/全国マンションデータチャット用2026.5.25.xlsx');
if (!is_file($file)) {
    fwrite(STDERR, "XLSX file not found: {$file}\n");
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

function ensureMansionBuildingsTable(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS mansion_buildings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        building_name VARCHAR(255) NOT NULL,
        postal_code VARCHAR(20) NULL,
        prefecture VARCHAR(50) NULL,
        city VARCHAR(100) NULL,
        town VARCHAR(120) NULL,
        address_detail VARCHAR(255) NULL,
        full_address VARCHAR(600) NULL,
        structure VARCHAR(120) NULL,
        floors_above INT NULL,
        floors_below INT NULL,
        built_year_month VARCHAR(20) NULL,
        built_date DATE NULL,
        total_units INT NULL,
        nearest_line VARCHAR(255) NULL,
        nearest_station VARCHAR(120) NULL,
        nearest_access_method VARCHAR(50) NULL,
        nearest_minutes INT NULL,
        transports_json JSON NULL,
        raw_data JSON NULL,
        search_text TEXT NULL,
        name_norm VARCHAR(255) NULL,
        search_norm TEXT NULL,
        source_file VARCHAR(255) NULL,
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_mansion_name (building_name),
        INDEX idx_mansion_name_norm (name_norm),
        INDEX idx_mansion_pref_city (prefecture, city),
        INDEX idx_mansion_station (nearest_station),
        FULLTEXT KEY ft_mansion_search (building_name, full_address, search_text)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * 表記ブレ-insensitive normalization for マンション matching. MUST stay identical to
 * chatMansionNormalizeText() in includes/chat-public-data-helper.php — the chat
 * search normalizes the query the same way and matches name_norm/search_norm.
 */
function mansionNormalizeText($s) {
    $s = (string)$s;
    if ($s === '') return '';
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_KC);
        if (is_string($n) && $n !== '') $s = $n;
    }
    $s = mb_convert_kana($s, 'KVC');
    $s = mb_strtolower($s);
    $s = preg_replace('/[ー―‐\x{2010}-\x{2015}\x{2212}\x{301C}\x{FF5E}\-－〜~ｰ]/u', '', $s);
    $s = preg_replace('/[\s\x{3000}・･,，、。.／\/「」『』（）()\[\]【】｛｝{}＆&\x{2019}\x{2018}\x{201C}\x{201D}\x{0027}\x{0060}"’‘`*~!！?？:：;；|｜＿_]/u', '', $s);
    $roman = ['viii' => '8', 'vii' => '7', 'iii' => '3', 'vi' => '6', 'iv' => '4', 'ix' => '9', 'ii' => '2', 'v' => '5', 'x' => '10', 'i' => '1'];
    if (preg_match('/([一-龯々〆ぁ-んァ-ヺ])(' . implode('|', array_keys($roman)) . ')$/u', (string)$s, $m)) {
        $s = mb_substr((string)$s, 0, mb_strlen((string)$s) - mb_strlen($m[2])) . $roman[$m[2]];
    }
    return $s === null ? '' : $s;
}

function xlsxColumnIndex($cellRef) {
    if (!preg_match('/^([A-Z]+)/', $cellRef, $m)) return null;
    $letters = $m[1];
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function excelDateToYm($value) {
    if ($value === null || $value === '') return [null, null];
    if (is_numeric($value)) {
        $serial = (int)$value;
        if ($serial > 20000 && $serial < 80000) {
            $timestamp = ($serial - 25569) * 86400;
            $ym = gmdate('Y-m', $timestamp);
            return [$ym, $ym . '-01'];
        }
    }
    $text = trim((string)$value);
    if (preg_match('/(\d{4})[年\/-]?(\d{1,2})/u', $text, $m)) {
        $ym = sprintf('%04d-%02d', (int)$m[1], (int)$m[2]);
        return [$ym, $ym . '-01'];
    }
    return [$text, null];
}

function intOrNull($value) {
    $digits = preg_replace('/[^0-9]/', '', mb_convert_kana((string)$value, 'n'));
    return $digits === '' ? null : (int)$digits;
}

$zip = new ZipArchive();
if ($zip->open($file) !== true) {
    fwrite(STDERR, "Could not open XLSX: {$file}\n");
    exit(1);
}

$sharedStrings = [];
$sharedXml = $zip->getFromName('xl/sharedStrings.xml');
if ($sharedXml !== false) {
    $xml = simplexml_load_string($sharedXml);
    foreach ($xml->si as $si) {
        $text = '';
        if (isset($si->t)) {
            $text = (string)$si->t;
        } else {
            foreach ($si->r as $r) $text .= (string)$r->t;
        }
        $sharedStrings[] = $text;
    }
}

$sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
if ($sheetXml === false) {
    fwrite(STDERR, "sheet1.xml not found.\n");
    exit(1);
}

$reader = new XMLReader();
$reader->XML($sheetXml, 'UTF-8', LIBXML_PARSEHUGE | LIBXML_NONET);
$headers = [];
$rowCount = 0;
$imported = 0;
$skipped = 0;

$database = new Database();
$db = $database->getConnection();
ensureMansionBuildingsTable($db);
try {
    $db->exec('ALTER TABLE mansion_buildings DROP INDEX uniq_mansion_name_address');
} catch (Throwable $e) {
    // Index may not exist on fresh installs.
}
$db->exec('TRUNCATE TABLE mansion_buildings');

$sql = "INSERT INTO mansion_buildings
    (building_name, postal_code, prefecture, city, town, address_detail, full_address, structure,
     floors_above, floors_below, built_year_month, built_date, total_units,
     nearest_line, nearest_station, nearest_access_method, nearest_minutes,
     transports_json, raw_data, search_text, name_norm, search_norm, source_file)
    VALUES
    (:building_name, :postal_code, :prefecture, :city, :town, :address_detail, :full_address, :structure,
     :floors_above, :floors_below, :built_year_month, :built_date, :total_units,
     :nearest_line, :nearest_station, :nearest_access_method, :nearest_minutes,
     :transports_json, :raw_data, :search_text, :name_norm, :search_norm, :source_file)";
$stmt = $db->prepare($sql);
$db->beginTransaction();

while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
    $rowXml = $reader->readOuterXML();
    $row = simplexml_load_string($rowXml);
    $cells = [];
    foreach ($row->c as $cell) {
        $ref = (string)$cell['r'];
        $idx = xlsxColumnIndex($ref);
        if ($idx === null) continue;
        $value = isset($cell->v) ? (string)$cell->v : '';
        $type = (string)$cell['t'];
        if ($type === 's') $value = $sharedStrings[(int)$value] ?? '';
        elseif ($type === 'inlineStr' && isset($cell->is->t)) $value = (string)$cell->is->t;
        $cells[$idx] = trim((string)$value);
    }
    if (empty($cells)) continue;
    ksort($cells);
    if ($rowCount === 0) {
        $max = max(array_keys($cells));
        for ($i = 0; $i <= $max; $i++) $headers[$i] = $cells[$i] ?? '';
        $rowCount++;
        continue;
    }
    $rowCount++;
    $data = [];
    foreach ($headers as $i => $header) {
        if ($header === '') continue;
        $data[$header] = $cells[$i] ?? '';
    }
    $name = $data['物件名'] ?? '';
    if ($name === '') { $skipped++; continue; }
    $pref = $data['住所[都道府県名]'] ?? '';
    $city = $data['住所[都市名]'] ?? '';
    $town = $data['住所[町名]'] ?? '';
    $detail = $data['住所[それ以下]'] ?? '';
    $fullAddress = trim($pref . $city . $town . $detail);
    [$builtYm, $builtDate] = excelDateToYm($data['築年月'] ?? '');

    $transports = [];
    for ($i = 1; $i <= 14; $i++) {
        $line = $data["交通_{$i}_路線"] ?? '';
        $station = $data["交通_{$i}_駅"] ?? '';
        $method = $data["交通_{$i}_手段"] ?? '';
        $minutes = $data["交通_{$i}_時間(分)"] ?? '';
        if ($line === '' && $station === '') continue;
        $transports[] = [
            'line' => $line,
            'station' => $station,
            'method' => $method,
            'minutes' => intOrNull($minutes),
        ];
    }
    $nearest = $transports[0] ?? ['line' => null, 'station' => null, 'method' => null, 'minutes' => null];
    $searchText = implode(' ', array_filter([$name, $fullAddress, $pref, $city, $town, $nearest['line'] ?? '', $nearest['station'] ?? '', $data['構造'] ?? '', $builtYm]));

    $stmt->execute([
        ':building_name' => $name,
        ':postal_code' => $data['住所[郵便番号]'] ?? null,
        ':prefecture' => $pref ?: null,
        ':city' => $city ?: null,
        ':town' => $town ?: null,
        ':address_detail' => $detail ?: null,
        ':full_address' => $fullAddress ?: null,
        ':structure' => ($data['構造'] ?? '') ?: null,
        ':floors_above' => intOrNull($data['階建て'] ?? ''),
        ':floors_below' => intOrNull($data['階建て (地下)'] ?? ''),
        ':built_year_month' => $builtYm,
        ':built_date' => $builtDate,
        ':total_units' => intOrNull($data['総戸数'] ?? ''),
        ':nearest_line' => $nearest['line'] ?? null,
        ':nearest_station' => $nearest['station'] ?? null,
        ':nearest_access_method' => $nearest['method'] ?? null,
        ':nearest_minutes' => $nearest['minutes'] ?? null,
        ':transports_json' => json_encode($transports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':raw_data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':search_text' => $searchText,
        ':name_norm' => mansionNormalizeText($name),
        ':search_norm' => mansionNormalizeText($searchText),
        ':source_file' => basename($file),
    ]);
    $imported++;
    if ($imported % 1000 === 0) {
        $db->commit();
        echo "Imported {$imported}\n";
        $db->beginTransaction();
    }
}
$db->commit();
$reader->close();
echo "Done. rows={$rowCount} imported={$imported} skipped={$skipped}\n";
