<?php
/**
 * 不動産情報ライブラリ（国土交通省）API 連携検証スクリプト（読み取り専用）。
 *
 * 「APIに接続できているか」だけでなく、テスト物件を1件指定して、実際に
 * 以下のデータが取得できているかを API ID 単位で確認する。
 *   ① 取引価格・成約価格・地価情報
 *   ② 用途地域・建蔽率・容積率・防火地域 等
 *   ③ 小中学校区・医療／福祉施設
 *   ④ 将来人口・駅別乗降客数
 *   ⑤ 洪水／高潮／津波／土砂災害／液状化／盛土 等の防災情報
 *   ⑥ 避難場所・災害履歴
 *
 * 実際のチャットとまったく同じ取得関数（chat-public-data-helper.php）を呼ぶため、
 * ここでの結果はチャットの回答能力そのものを表す。DBは書き換えない
 * （chat_public_data_cache への取得結果キャッシュのみ、通常の chat と同じ挙動）。
 *
 * Usage:
 *   php backend/scripts/verify_reinfolib_apis.php --address="埼玉県川口市弥平2-20-3"
 *   php backend/scripts/verify_reinfolib_apis.php --mansion="リプレ川口一番街1号棟"
 *   php backend/scripts/verify_reinfolib_apis.php --address="..." --station="川口駅"
 *   php backend/scripts/verify_reinfolib_apis.php --address="..." --json
 *
 * 判定の見方（重要）:
 *   OK    … データ取得成功
 *   なし  … API正常応答だが、その地点に区域指定・該当データが無い（＝連携は正常）
 *   区域外… 近隣にはデータがあるが、指定地点はその区域の外（＝連携は正常）
 *   NG    … HTTPエラー・APIキー不正・タイムアウト等（＝要対応）
 *   -     … 前提条件が揃わず未実行（住所解決失敗・駅名不明など）
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI only.\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-helpers.php';
require_once __DIR__ . '/../includes/openai-chat-helper.php';
require_once __DIR__ . '/../includes/chat-public-data-helper.php';

// ---- 引数 -------------------------------------------------------------------
$opts = ['address' => '', 'mansion' => '', 'station' => '', 'json' => false];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') { $opts['json'] = true; continue; }
    if (preg_match('/^--(address|mansion|station)=(.*)$/u', $arg, $m)) {
        $opts[$m[1]] = trim($m[2]);
        continue;
    }
    if ($opts['address'] === '' && strpos($arg, '--') !== 0) $opts['address'] = trim($arg);
}
if ($opts['address'] === '' && $opts['mansion'] === '') {
    fwrite(STDERR, "使い方: php backend/scripts/verify_reinfolib_apis.php --address=\"埼玉県川口市弥平2-20-3\"\n"
        . "        php backend/scripts/verify_reinfolib_apis.php --mansion=\"リプレ川口一番街1号棟\"\n");
    exit(1);
}

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- 検証対象の住所を決める --------------------------------------------------
$buildingName = '';
$mansionNote = '';
$address = $opts['address'];
if ($opts['mansion'] !== '') {
    // チャットと同じ経路（全国マンションDB → 住所）で解決する。
    $resolved = chatResolveMansionAddress($db, $opts['mansion']);
    if ($resolved === null) {
        $mansionNote = '全国マンションDBに該当なし（この物件名ではチャットも住所を解決できません）';
        if ($address === '') {
            fwrite(STDERR, "「{$opts['mansion']}」は全国マンションデータベースに見つかりませんでした。\n"
                . "--address=\"（所在地）\" を指定して実行してください。\n");
            exit(1);
        }
    } else {
        $buildingName = $resolved['building_name'];
        $mansionNote = '全国マンションDBで解決';
        if ($address === '') $address = $resolved['full_address'];
        if ($opts['station'] === '' && !empty($resolved['row']['nearest_station'])) {
            $opts['station'] = (string)$resolved['row']['nearest_station'];
        }
    }
}

$apiKeySet = defined('REINFOLIB_API_KEY') && REINFOLIB_API_KEY !== '';
$geo = function_exists('chatGeocodeAddressRobust')
    ? chatGeocodeAddressRobust($db, $address)
    : chatAddressGeocode($db, $address);
$area = chatPublicExtractArea($address);
if ($opts['station'] !== '') {
    $area['station_name'] = mb_substr($opts['station'], -1) === '駅' ? $opts['station'] : $opts['station'] . '駅';
}

// ---- 検証カタログ：お客様のご要望6分類 → API ID ------------------------------
$catalog = chatReinfoApiCatalog();
$groups = [
    '① 取引価格・成約価格・地価情報' => ['XIT002', 'XIT001', 'XPT001', 'XPT002', 'XCT001'],
    '② 用途地域・建蔽率・容積率・防火地域等' => ['XKT002', 'XKT014', 'XKT001', 'XKT003', 'XKT023', 'XKT024', 'XKT030'],
    '③ 小中学校区・医療／福祉施設' => ['XKT004', 'XKT005', 'XKT006', 'XKT007', 'XKT010', 'XKT011', 'XKT017', 'XKT018', 'XKT019'],
    '④ 将来人口・駅別乗降客数' => ['XKT013', 'XKT031', 'XKT015'],
    '⑤ 防災情報（洪水・高潮・津波・土砂・液状化・盛土等）' => ['XKT026', 'XKT027', 'XKT028', 'XKT029', 'XKT025', 'XKT020', 'XKT021', 'XKT022', 'XKT016'],
    '⑥ 避難場所・災害履歴' => ['XGT001', 'XST001'],
];
// 個別実装（タイル型ではないAPI）の日本語名。
$specialTitles = [
    'XIT001' => '不動産価格（取引価格・成約価格）情報',
    'XIT002' => '都道府県内市区町村一覧（XIT001の前提）',
    'XKT015' => '駅別乗降客数',
];

/** 個別実装APIの検証結果を、タイル診断と同じ形に揃える。 */
function verifyResultRow($code, $title, $status, $statusLabel, $count = 0, $note = '', $sample = null) {
    return [
        'code' => $code,
        'title' => $title,
        'status' => $status,
        'status_label' => $statusLabel,
        'matched_count' => (int)$count,
        'note' => $note,
        'sample' => $sample,
    ];
}

/** 個別実装（XIT002 / XIT001 / XCT001 / XKT015）を検証する。 */
function verifySpecialApi($db, $code, $area, $address, $apiKeySet) {
    $title = ['XIT001' => '不動産価格（取引価格・成約価格）情報',
              'XIT002' => '都道府県内市区町村一覧（XIT001の前提）',
              'XCT001' => '鑑定評価書情報',
              'XKT015' => '駅別乗降客数'][$code] ?? $code;
    if (!$apiKeySet) return verifyResultRow($code, $title, 'no_api_key', 'APIキー未設定');

    if ($code === 'XIT002') {
        if (empty($area['prefecture_code']) || empty($area['city_name'])) {
            return verifyResultRow($code, $title, 'skipped', '未実行', 0, '住所から都道府県・市区町村を判別できませんでした');
        }
        $cityCode = chatReinfoCityCode($db, $area['prefecture_code'], $area['city_name']);
        return $cityCode
            ? verifyResultRow($code, $title, 'data', 'データあり', 1, '市区町村コード ' . $cityCode)
            : verifyResultRow($code, $title, 'http_error', '取得失敗', 0, '市区町村コードを取得できませんでした');
    }
    if ($code === 'XIT001') {
        $item = chatReinfoContext($db, $address, $area, true);
        return $item
            ? verifyResultRow($code, $title, 'data', 'データあり',
                (int)($item['total_count'] ?? $item['record_count'] ?? 0),
                ($item['scope_note'] ?? ''), $item['data'][0] ?? null)
            : verifyResultRow($code, $title, 'no_data', '取得できず', 0, '市区町村コード未解決、または対象年に取引データなし');
    }
    if ($code === 'XCT001') {
        if (empty($area['prefecture_code'])) {
            return verifyResultRow($code, $title, 'skipped', '未実行', 0, '住所から都道府県を判別できませんでした');
        }
        $item = chatReinfoAppraisalContext($db, $address, $area);
        return $item
            ? verifyResultRow($code, $title, 'data', 'データあり',
                (int)($item['total_count'] ?? $item['record_count'] ?? 0),
                ($item['scope_note'] ?? ''), $item['data'][0] ?? null)
            : verifyResultRow($code, $title, 'no_data', '取得できず', 0, '当年・前年ともに該当データなし');
    }
    // XKT015: 住所ではなく駅名で照会する。
    $station = trim((string)($area['station_name'] ?? ''));
    if ($station === '') {
        return verifyResultRow($code, $title, 'skipped', '未実行', 0, '駅名が不明です（--station="○○駅" で指定できます）');
    }
    $item = chatReinfoStationContext($db, $station . 'の乗降客数', $area);
    return $item
        ? verifyResultRow($code, $title, 'data', 'データあり', (int)($item['record_count'] ?? 0),
            $station . 'の乗降客数', $item['data'][0] ?? null)
        : verifyResultRow($code, $title, 'no_data', '取得できず', 0, $station . 'を該当タイル内で特定できませんでした');
}

// ---- 実行 -------------------------------------------------------------------
$results = [];
foreach ($groups as $groupTitle => $codes) {
    foreach ($codes as $code) {
        if (in_array($code, ['XIT001', 'XIT002', 'XCT001', 'XKT015'], true)) {
            $row = verifySpecialApi($db, $code, $area, $address, $apiKeySet);
        } elseif (isset($catalog[$code])) {
            $d = chatReinfoTileDiagnostic($db, $code, $catalog[$code], $geo);
            $row = verifyResultRow($code, $catalog[$code]['title'] ?? $code, $d['status'], $d['status_label'],
                $d['matched_count'], 'HTTP ' . ($d['http_status'] ?? '-')
                . ($d['error'] !== '' ? ' / ' . $d['error'] : ''), $d['rows'][0] ?? null);
        } else {
            $row = verifyResultRow($code, $specialTitles[$code] ?? $code, 'not_implemented', '未実装');
        }
        $row['group'] = $groupTitle;
        $results[] = $row;
    }
}

// ---- 出力 -------------------------------------------------------------------
$mark = function ($status) {
    switch ($status) {
        case 'data': return 'OK  ';
        case 'not_designated': return 'なし ';
        case 'out_of_area': return '区域外';
        case 'no_data': return 'なし ';
        case 'skipped': return '-   ';
        case 'geocode_failed': return '-   ';
        case 'no_api_key': return 'NG  ';
        case 'not_implemented': return 'NG  ';
        default: return 'NG  ';
    }
};
$isOk = function ($status) { return $status === 'data'; };
$isFail = function ($status) {
    return in_array($status, ['http_error', 'no_api_key', 'not_implemented', 'error'], true);
};

if ($opts['json']) {
    echo json_encode([
        'building' => $buildingName, 'address' => $address, 'mansion_db' => $mansionNote,
        'geocode' => $geo, 'area' => $area, 'api_key_configured' => $apiKeySet,
        'generated_at' => date('Y-m-d H:i:s'), 'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$line = str_repeat('=', 78);
echo $line, "\n";
echo "不動産情報ライブラリ API 連携検証\n";
echo $line, "\n";
if ($opts['mansion'] !== '') echo "対象物件  : {$opts['mansion']}" . ($buildingName !== '' ? "  → DB名称: {$buildingName}" : '') . "\n";
if ($mansionNote !== '')     echo "マンションDB: {$mansionNote}\n";
echo "検証住所  : {$address}\n";
echo "緯度経度  : " . ($geo ? sprintf('%.6f / %.6f  （一致: %s）', $geo['lat'], $geo['lon'], $geo['title'] ?? '-') : '取得失敗（住所を特定できませんでした）') . "\n";
echo "都道府県  : " . ($area['prefecture_name'] ?: '-') . " / 市区町村: " . ($area['city_name'] ?: '-')
    . " / 駅: " . ($area['station_name'] ?: '-') . "\n";
echo "APIキー   : " . ($apiKeySet ? '設定あり' : '★未設定（REINFOLIB_API_KEY）') . "\n";
echo "実行日時  : " . date('Y-m-d H:i:s') . "\n";
echo $line, "\n\n";

$currentGroup = null;
foreach ($results as $r) {
    if ($r['group'] !== $currentGroup) {
        $currentGroup = $r['group'];
        echo "■ {$currentGroup}\n";
    }
    printf("  [%s] %-7s %-28s %s\n", $mark($r['status']), $r['code'],
        mb_strimwidth($r['title'], 0, 28, ''), $r['status_label']
        . ($r['matched_count'] > 0 ? '（' . $r['matched_count'] . '件）' : '')
        . ($r['note'] !== '' ? ' ' . $r['note'] : ''));
    if ($r['sample'] !== null) {
        echo "          例: " . mb_strimwidth((string)json_encode($r['sample'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 150, '…') . "\n";
    }
}

$ok = $none = $fail = $skip = [];
foreach ($results as $r) {
    if ($isOk($r['status'])) $ok[] = $r;
    elseif ($isFail($r['status'])) $fail[] = $r;
    elseif (in_array($r['status'], ['skipped', 'geocode_failed'], true)) $skip[] = $r;
    else $none[] = $r;
}

echo "\n", $line, "\n";
echo "【取得できたAPI】 " . count($ok) . " / " . count($results) . " 件\n";
foreach ($ok as $r) echo "  ○ {$r['code']}  {$r['title']}（{$r['matched_count']}件）\n";

echo "\n【該当データが無かったAPI（API連携は正常・その地点に指定が無い）】 " . count($none) . " 件\n";
foreach ($none as $r) echo "  ・{$r['code']}  {$r['title']} … {$r['status_label']}\n";

echo "\n【前提が揃わず未実行のAPI】 " . count($skip) . " 件\n";
foreach ($skip as $r) echo "  － {$r['code']}  {$r['title']} … {$r['note']}\n";

echo "\n【取得できなかったAPI（要対応）】 " . count($fail) . " 件\n";
if (empty($fail)) {
    echo "  なし（APIエラーは発生していません）\n";
} else {
    foreach ($fail as $r) echo "  × {$r['code']}  {$r['title']} … {$r['status_label']} {$r['note']}\n";
}
echo $line, "\n";
exit(empty($fail) ? 0 : 2);
