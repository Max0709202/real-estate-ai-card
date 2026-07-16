<?php
/**
 * Server-side public/open data enrichment for chat replies.
 * API keys stay in backend config/secrets and are never exposed to the browser.
 */

function ensureChatPublicDataCacheTable($db) {
    if (!$db instanceof PDO) return;
    $db->exec("CREATE TABLE IF NOT EXISTS chat_public_data_cache (
        cache_key CHAR(64) PRIMARY KEY,
        provider VARCHAR(60) NOT NULL,
        request_url TEXT NOT NULL,
        response_json MEDIUMTEXT NULL,
        http_status INT NULL,
        error_message TEXT NULL,
        expires_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_chat_public_cache_provider (provider),
        INDEX idx_chat_public_cache_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function chatPublicDataShouldRun($message) {
    // A message that contains a street-level address (even inside a sentence like
    // "○○のハザードを教えて") has none of the keywords below but must still trigger
    // the address-based zone/hazard report — otherwise it falls through to a
    // マンション名検索 and is answered "該当物件が見つかりませんでした".
    if (chatMessageContainsAddress($message)) return true;
    return (bool)preg_match('/(住所|所在地|駅|エリア|地域|周辺|公的|データ|国土交通|政府統計|統計|相場|取引価格|成約|地価|公示|地価調査|基準地価|鑑定|評価書|路線価|災害|防災|浸水|洪水|水害|土砂|地盤|液状化|津波|高潮|地すべり|地滑り|急傾斜|崖|がけ|盛土|造成地|災害危険|被災|用途地域|建蔽率|建ぺい率|容積率|都市計画|区域区分|市街化|立地適正化|防火|地区計画|高度利用|再開発|交通|道路|インフラ|学校|小学校|中学校|高校|学区|病院|医療|クリニック|診療|図書館|公園|役所|役場|公民館|避難|保育|幼稚園|こども園|福祉|介護|老人ホーム|人口|世帯|高齢|子育て|子供|子ども|ファミリー|年収|昼夜|外国人|持ち家|人口集中|DID|将来人口|推計人口|マンション|物件|建物|基礎情報|基本情報|概要|詳細|築年月|築年数|竣工|総戸数|階建|最寄り|乗降|乗降客|乗降人員|利用者数|乗客|混雑)/u', (string)$message);
}

/**
 * Extract the street-level address substring from a message, whether typed alone
 * ("埼玉県川口市弥平2-20-3") or embedded in a sentence ("…のハザードを教えて"). The
 * address is anchored to the 番地 tail (丁目/番地/号 or N-N), so trailing particles
 * /words (の…を教えて) are not swallowed — the old prefecture-anchored regex grabbed
 * the whole sentence and GSI could not geocode it. Returns null when no address is
 * present. A 都道府県/市区町村 prefix is optional, so a bare 町名+番地 ("弥平2-20-3")
 * is still recognised.
 */
function chatExtractAddressFromMessage($message) {
    $message = (string)$message;
    $num = '[0-9０-９]';
    $ja  = '[一-龥ぁ-んァ-ンヶ々ー]';
    $pref = '(?:北海道|東京都|京都府|大阪府|' . $ja . '{2,3}県)';
    $banchi = '(?:' . $num . '+\s*丁目(?:\s*' . $num . '+\s*番地?)?(?:\s*' . $num . '+\s*号)?'
            . '|' . $num . '+(?:[-－‐ー―]' . $num . '+){1,3}'
            . '|' . $num . '+\s*番地?(?:\s*' . $num . '+\s*号)?)';
    // Reject partial numbers / units so "予算10-20万円" or "10時20分" are not addresses.
    $boundary = '(?![0-9０-９万円台人年月日時分秒度名個件部階室歳坪％%])';
    $re = '/(' . $pref . '?' . $ja . '{1,24}' . $banchi . $boundary . ')/u';
    if (preg_match($re, $message, $m)) return trim($m[1]);
    return null;
}

/** True when the message contains a recognisable street-level address. */
function chatMessageContainsAddress($message) {
    return chatExtractAddressFromMessage($message) !== null;
}

function chatPublicDataSourceLabel($provider) {
    $map = [
        'reinfolib' => '国土交通省 不動産情報ライブラリ',
        'mlit_dpf' => '国土交通データプラットフォーム',
        'estat' => '政府統計の総合窓口 e-Stat',
        'mansion_db' => '当社 全国マンションデータベース',
    ];
    return $map[$provider] ?? $provider;
}

function chatPublicDataHttpGet($url, $headers = [], $timeout = 12) {
    $ch = curl_init($url);
    $httpHeaders = [];
    foreach ($headers as $key => $value) $httpHeaders[] = $key . ': ' . $value;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'AI-Fcard-ChatPublicData/1.0',
    ]);
    $body = curl_exec($ch);
    $error = $body === false ? curl_error($ch) : '';
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'status' => $status, 'error' => $error ?: 'request failed', 'data' => null, 'body' => null];
    $data = json_decode($body, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => '', 'data' => $data, 'body' => $body];
}


function chatPublicDataRedactUrl($url) {
    return preg_replace('/([?&](?:appId|api_key|apikey|key)=)[^&]*/i', '$1[redacted]', (string)$url);
}

function chatPublicDataHttpPostJson($url, $payload, $headers = [], $timeout = 12) {
    $ch = curl_init($url);
    $httpHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($headers as $key => $value) $httpHeaders[] = $key . ': ' . $value;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'AI-Fcard-ChatPublicData/1.0',
    ]);
    $body = curl_exec($ch);
    $error = $body === false ? curl_error($ch) : '';
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'status' => $status, 'error' => $error ?: 'request failed', 'data' => null, 'body' => null];
    $data = json_decode($body, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => '', 'data' => $data, 'body' => $body];
}

function chatPublicDataCachedPostJson($db, $provider, $url, $payload, $headers = [], $ttlSeconds = 86400) {
    $key = hash('sha256', $provider . '|POST|' . $url . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($db instanceof PDO) {
        ensureChatPublicDataCacheTable($db);
        $stmt = $db->prepare('SELECT response_json, http_status, error_message, updated_at FROM chat_public_data_cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
        $stmt->execute([$key]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            $cachedStatus = (int)($cached['http_status'] ?? 0);
            return [
                'ok' => $cachedStatus >= 200 && $cachedStatus < 300,
                'status' => $cachedStatus,
                'error' => $cached['error_message'] ?? '',
                'data' => json_decode($cached['response_json'] ?? 'null', true),
                'cached' => true,
                'fetched_at' => $cached['updated_at'] ?? null,
            ];
        }
    }
    $result = chatPublicDataHttpPostJson($url, $payload, $headers);
    $result['cached'] = false;
    $result['fetched_at'] = date('Y-m-d H:i:s');
    if ($db instanceof PDO) {
        $stmt = $db->prepare("INSERT INTO chat_public_data_cache (cache_key, provider, request_url, response_json, http_status, error_message, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), http_status = VALUES(http_status), error_message = VALUES(error_message), expires_at = VALUES(expires_at), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([
            $key,
            $provider,
            chatPublicDataRedactUrl($url),
            is_array($result['data']) ? json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($result['body'] ?? ''),
            $result['status'] ?? null,
            $result['error'] ?? null,
            // 成功応答は通常TTLでキャッシュ。取得失敗（タイムアウト・通信エラー等）は
            // 長くキャッシュすると「一度失敗すると同じ住所でしばらく失敗し続ける」不安定さの
            // 原因になるため、ごく短時間（20秒）だけにして次回リクエストで必ず再取得させる。
            // ※HTTP 200 で中身が空（＝該当なし/区域外）は ok=true 扱いのため通常TTLで保持する。
            !empty($result['ok']) ? max(60, (int)$ttlSeconds) : 20,
        ]);
    }
    return $result;
}

function chatPublicDataCachedGet($db, $provider, $url, $headers = [], $ttlSeconds = 86400, $timeout = 12) {
    $key = hash('sha256', $provider . '|' . $url);
    if ($db instanceof PDO) {
        ensureChatPublicDataCacheTable($db);
        $stmt = $db->prepare('SELECT response_json, http_status, error_message, updated_at FROM chat_public_data_cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
        $stmt->execute([$key]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            $cachedStatus = (int)($cached['http_status'] ?? 0);
            return [
                'ok' => $cachedStatus >= 200 && $cachedStatus < 300,
                'status' => $cachedStatus,
                'error' => $cached['error_message'] ?? '',
                'data' => json_decode($cached['response_json'] ?? 'null', true),
                'cached' => true,
                'fetched_at' => $cached['updated_at'] ?? null,
            ];
        }
    }
    $result = chatPublicDataHttpGet($url, $headers, $timeout);
    $result['cached'] = false;
    $result['fetched_at'] = date('Y-m-d H:i:s');
    if ($db instanceof PDO) {
        $stmt = $db->prepare("INSERT INTO chat_public_data_cache (cache_key, provider, request_url, response_json, http_status, error_message, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), http_status = VALUES(http_status), error_message = VALUES(error_message), expires_at = VALUES(expires_at), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([
            $key,
            $provider,
            chatPublicDataRedactUrl($url),
            is_array($result['data']) ? json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($result['body'] ?? ''),
            $result['status'] ?? null,
            $result['error'] ?? null,
            // 成功応答は通常TTLでキャッシュ。取得失敗（タイムアウト・通信エラー等）は
            // 長くキャッシュすると「一度失敗すると同じ住所でしばらく失敗し続ける」不安定さの
            // 原因になるため、ごく短時間（20秒）だけにして次回リクエストで必ず再取得させる。
            // ※HTTP 200 で中身が空（＝該当なし/区域外）は ok=true 扱いのため通常TTLで保持する。
            !empty($result['ok']) ? max(60, (int)$ttlSeconds) : 20,
        ]);
    }
    return $result;
}

function chatPublicPrefectureCodes() {
    return ['北海道'=>'01','青森県'=>'02','岩手県'=>'03','宮城県'=>'04','秋田県'=>'05','山形県'=>'06','福島県'=>'07','茨城県'=>'08','栃木県'=>'09','群馬県'=>'10','埼玉県'=>'11','千葉県'=>'12','東京都'=>'13','東京'=>'13','神奈川県'=>'14','新潟県'=>'15','富山県'=>'16','石川県'=>'17','福井県'=>'18','山梨県'=>'19','長野県'=>'20','岐阜県'=>'21','静岡県'=>'22','愛知県'=>'23','三重県'=>'24','滋賀県'=>'25','京都府'=>'26','京都'=>'26','大阪府'=>'27','大阪'=>'27','兵庫県'=>'28','奈良県'=>'29','和歌山県'=>'30','鳥取県'=>'31','島根県'=>'32','岡山県'=>'33','広島県'=>'34','山口県'=>'35','徳島県'=>'36','香川県'=>'37','愛媛県'=>'38','高知県'=>'39','福岡県'=>'40','佐賀県'=>'41','長崎県'=>'42','熊本県'=>'43','大分県'=>'44','宮崎県'=>'45','鹿児島県'=>'46','沖縄県'=>'47'];
}

function chatPublicExtractArea($message) {
    $message = (string)$message;
    $prefCode = null;
    $prefName = null;
    foreach (chatPublicPrefectureCodes() as $name => $code) {
        if (mb_strpos($message, $name) !== false) { $prefName = $name; $prefCode = $code; break; }
    }
    if (!$prefCode && preg_match('/(千代田区|中央区|港区|新宿区|文京区|台東区|墨田区|江東区|品川区|目黒区|大田区|世田谷区|渋谷区|中野区|杉並区|豊島区|北区|荒川区|板橋区|練馬区|足立区|葛飾区|江戸川区)/u', $message)) {
        $prefName = '東京都';
        $prefCode = '13';
    }
    $cityName = null;
    if (preg_match('/([一-龥ぁ-んァ-ンヶケー]{1,20}(?:市|区|町|村))/u', $message, $m)) $cityName = $m[1];
    $station = null;
    if (preg_match('/([一-龥ぁ-んァ-ンA-Za-z0-9０-９ヶケー]{1,30})駅/u', $message, $m)) $station = $m[1] . '駅';
    return ['prefecture_code' => $prefCode, 'prefecture_name' => $prefName, 'city_name' => $cityName, 'station_name' => $station];
}


function chatPublicDataRows($data) {
    if (!is_array($data)) return [];
    if (isset($data['data']) && is_array($data['data'])) return $data['data'];
    if (isset($data['result']) && is_array($data['result'])) return $data['result'];
    return $data;
}

function chatReinfoCityCode($db, $prefCode, $cityName) {
    if (!$prefCode || !$cityName || !defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') return null;
    $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/XIT002?' . http_build_query(['area' => $prefCode, 'language' => 'ja']);
    $result = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], 604800);
    $data = $result['data'];
    if (!is_array($data)) return null;
    foreach (chatPublicDataRows($data) as $row) {
        $name = $row['name'] ?? $row['Name'] ?? '';
        $id = $row['id'] ?? $row['Id'] ?? $row['code'] ?? null;
        if ($id && $name && mb_strpos($name, $cityName) !== false) return (string)$id;
    }
    return null;
}

function chatReinfoContext($db, $message, $area, $force = false) {
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') return null;
    if (!$force && !preg_match('/(相場|取引価格|成約|地価|公示|価格|マンション|エリア|地域)/u', $message)) return null;
    $cityCode = chatReinfoCityCode($db, $area['prefecture_code'] ?? null, $area['city_name'] ?? null);
    if (!$cityCode) return null;
    $year = (int)date('Y') - 1;
    $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/XIT001?' . http_build_query([
        'year' => (string)$year,
        'city' => $cityCode,
        'priceClassification' => '01',
        'language' => 'ja',
    ]);
    $result = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], 86400);
    if (!$result['ok'] || !is_array($result['data'])) return null;
    $allRows = chatPublicDataRows($result['data']);
    $totalCount = count($allRows);
    $rows = array_slice($allRows, 0, 8);
    if (empty($rows)) return null;
    $scope = $year . '年・' . trim(($area['prefecture_name'] ?? '') . ($area['city_name'] ?? '')) . 'の不動産取引価格情報';
    return [
        'provider' => 'reinfolib',
        'title' => '不動産価格・取引価格の参考データ',
        'notice' => 'このエリアの価格・取引事例を公的データで確認します。',
        'data' => $rows,
        'record_count' => count($rows),
        'total_count' => $totalCount,
        'scope_note' => $scope,
        'count_note' => 'このAPIレスポンスには上記対象（' . $scope . '）の取引が合計 ' . $totalCount . ' 件含まれています。プロンプトには先頭 ' . count($rows) . ' 件のみ添付しています。「取引件数」を聞かれた場合は合計 ' . $totalCount . ' 件と回答できます。',
        'fetched_at' => $result['fetched_at'] ?? null,
        'cached' => !empty($result['cached']),
    ];
}

function chatMlitDpfContext($db, $message, $area, $force = false) {
    if (!defined('MLIT_DPF_API_KEY') || MLIT_DPF_API_KEY === '') return null;
    if (!$force && !preg_match('/(公的|データ|国土交通|災害|防災|浸水|洪水|水害|土砂|地盤|液状化|都市計画|再開発|交通|道路|インフラ|河川|地域データ|周辺環境|エリア説明|子育て|子供|子ども|ファミリー)/u', $message)) return null;
    $keyword = trim(($area['prefecture_name'] ?? '') . ' ' . ($area['city_name'] ?? '') . ' ' . ($area['station_name'] ?? ''));
    if ($keyword === '') $keyword = mb_substr($message, 0, 80);
    $base = defined('MLIT_DPF_BASE_URL') ? rtrim(MLIT_DPF_BASE_URL, '/') . '/' : 'https://data-platform.mlit.go.jp/api/v1/';
    $term = json_encode($keyword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $graphql = 'query { search(first: 0, size: 10, term: ' . $term . ', phraseMatch: true) { totalNumber searchResults { id title lat lon year dataset_id catalog_id } } }';
    $result = chatPublicDataCachedPostJson($db, 'mlit_dpf', $base, ['query' => $graphql], ['apikey' => MLIT_DPF_API_KEY], 86400);
    if (!$result['ok'] || empty($result['data'])) return null;
    $search = $result['data']['data']['search'] ?? null;
    $searchResults = is_array($search['searchResults'] ?? null) ? $search['searchResults'] : [];
    $totalNumber = isset($search['totalNumber']) ? (int)$search['totalNumber'] : null;
    if ($totalNumber === 0 || (empty($searchResults) && $totalNumber === null)) return null;
    $notice = 'この住所周辺で関連する国交省データを探します。';
    if (preg_match('/(災害|防災)/u', $message)) $notice = 'このエリアの災害リスクを公的データで確認します。';
    elseif (preg_match('/(都市計画|再開発)/u', $message)) $notice = '再開発・都市計画の参考情報を確認します。';
    elseif (preg_match('/(交通|道路|インフラ)/u', $message)) $notice = '周辺インフラや交通環境を確認します。';
    elseif (preg_match('/(河川|浸水|水害|洪水)/u', $message)) $notice = 'ハザード関連の注意点を整理します。';
    return [
        'provider' => 'mlit_dpf',
        'title' => '国土交通データプラットフォーム検索結果',
        'notice' => $notice,
        'data' => ['totalNumber' => $totalNumber, 'searchResults' => array_slice($searchResults, 0, 10)],
        'record_count' => count($searchResults),
        'total_count' => $totalNumber,
        // The DPF search API returns a catalog of matching datasets (title/座標/年度), not measured values.
        'caveat' => 'これは該当する「データセットの一覧（カタログ）」であり、浸水深・対象河川などの具体的な数値は含まれていません。具体的な数値は断定せず、該当データセットが存在することと一般的な確認方法のみ伝えてください。洪水・浸水の具体的な想定は、ハザードマップポータル等で別途確認が必要です。',
        'fetched_at' => $result['fetched_at'] ?? null,
        'cached' => !empty($result['cached']),
    ];
}

function chatEstatContext($db, $message, $area, $force = false) {
    if (!defined('ESTAT_APP_ID') || ESTAT_APP_ID === '') return null;
    if (!$force && !preg_match('/(人口|世帯|高齢|子育て|子供|子ども|ファミリー|年収|昼夜|外国人|持ち家|統計|政府統計|e-Stat)/u', $message)) return null;
    $keyword = $area['city_name'] ?: mb_substr($message, 0, 40);
    $url = 'https://api.e-stat.go.jp/rest/3.0/app/json/getStatsList?' . http_build_query([
        'appId' => ESTAT_APP_ID,
        'searchWord' => $keyword . ' 国勢調査 人口 世帯',
        'limit' => 10,
    ]);
    $result = chatPublicDataCachedGet($db, 'estat', $url, [], 86400, 5);
    if (!$result['ok'] || empty($result['data'])) return null;
    $tables = $result['data']['GET_STATS_LIST']['DATALIST_INF']['TABLE_INF'] ?? null;
    $totalNumber = $result['data']['GET_STATS_LIST']['DATALIST_INF']['NUMBER'] ?? null;
    $recordCount = is_array($tables) ? (isset($tables[0]) ? count($tables) : 1) : 0;
    if ($recordCount === 0 && $totalNumber === null) return null;
    return [
        'provider' => 'estat',
        'title' => '政府統計の検索結果',
        'notice' => '政府統計による地域データを確認します。',
        'data' => $result['data'],
        'record_count' => $recordCount,
        'total_count' => $totalNumber !== null ? (int)$totalNumber : null,
        'caveat' => 'これは該当する統計表の一覧であり、具体的な集計値そのものではありません。具体的な数値は断定せず、参照できる統計があることを伝えてください。',
        'fetched_at' => $result['fetched_at'] ?? null,
        'cached' => !empty($result['cached']),
    ];
}

/**
 * Canonical normalization for matching マンション names across 表記ブレ (notation
 * variants). Collapses everything that varies between how a user types a name and
 * how it is stored, so the same building matches regardless of:
 *   - 全角/半角 英数字・記号 (NFKC) and upper/lower case
 *   - 半角カタカナ → 全角、濁点・半濁点の合成 (NFKC + mb_convert_kana 'KV')
 *   - ひらがな ⇔ カタカナ (unified to カタカナ)
 *   - 長音「ー」とハイフン/ダッシュ/チルダ各種（除去して同一視）
 *   - スペース・中黒「・」・引用符・各種記号（除去）
 * The SAME function normalizes both the stored columns (name_norm / search_norm)
 * and the query term, so a substring match on the normalized form is robust.
 * MUST stay in sync with the backfill in import_mansion_buildings.php.
 */
function chatMansionNormalizeText($s) {
    $s = (string)$s;
    if ($s === '') return '';
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_KC);
        if (is_string($n) && $n !== '') $s = $n;
    }
    // Do not depend on ext-intl: some production PHP builds do not provide
    // Normalizer. Convert Unicode Roman numerals explicitly, and use `a` to fold
    // full-width Latin letters and digits (including ２) to ASCII.
    $s = strtr($s, [
        'Ⅰ' => 'I', 'Ⅱ' => 'II', 'Ⅲ' => 'III', 'Ⅳ' => 'IV', 'Ⅴ' => 'V',
        'Ⅵ' => 'VI', 'Ⅶ' => 'VII', 'Ⅷ' => 'VIII', 'Ⅸ' => 'IX', 'Ⅹ' => 'X',
        // Common address/building-name variants: ヶ丘 ⇔ ケ丘, ヵ月 ⇔ カ月.
        'ヶ' => 'ケ', 'ゖ' => 'ケ', 'ヵ' => 'カ', 'ゕ' => 'カ',
    ]);
    $s = mb_convert_kana($s, 'KVCa');
    $s = mb_strtolower($s);
    // Convert a Roman phase number while its original boundary is still present.
    // Confidence matching normalizes "building_name + space + address", so doing
    // this only after spaces were removed made Ⅱ cease to be a terminal suffix.
    $roman = ['viii' => '8', 'vii' => '7', 'iii' => '3', 'vi' => '6', 'iv' => '4', 'ix' => '9', 'ii' => '2', 'v' => '5', 'x' => '10', 'i' => '1'];
    $romanPattern = '/([一-龯々〆ぁ-んァ-ヺ])(' . implode('|', array_keys($roman)) . ')(?=$|[\s\x{3000}・･,，、。.／\/「」『』（）()\[\]【】])/u';
    $s = preg_replace_callback($romanPattern, function ($m) use ($roman) {
        return $m[1] . $roman[$m[2]];
    }, $s);
    // Long-vowel marks and hyphen/dash/tilde variants → removed (treated as noise).
    $s = preg_replace('/[ー―‐\x{2010}-\x{2015}\x{2212}\x{301C}\x{FF5E}\-－〜~ｰ]/u', '', $s);
    // Spaces, middle dots, quotes, punctuation, brackets and common symbols → removed.
    $s = preg_replace('/[\s\x{3000}・･,，、。.／\/「」『』（）()\[\]【】｛｝{}＆&\x{2019}\x{2018}\x{201C}\x{201D}\x{0027}\x{0060}"’‘`*~!！?？:：;；|｜＿_]/u', '', $s);
    // NFKC turns Unicode Roman numerals (Ⅱ etc.) into Latin letters (ii), while
    // an Arabic numeral remains "2".  Building databases and users commonly mix
    // these suffixes, so canonicalise a terminal Roman phase number to Arabic.
    // Requiring a preceding Japanese character avoids changing ordinary Latin
    // names which happen to end in "i"/"ii" (for example, Hawaii).
    return $s === null ? '' : $s;
}

function chatNormalizeMansionSearchTerm($term) {
    $term = trim((string)$term);
    $term = preg_replace('/^[\s「」『』"\']+|[\s「」『』"\']+$/u', '', $term);
    $term = preg_replace('/^(?:マンション名|物件名|建物名)\s*(?:は|の|:|：)?\s*/u', '', $term);
    $term = preg_replace('/(?:について|を)?(?:教えて|知りたい|調べて|検索して|確認して)(?:ください|下さい)?$/u', '', $term);
    $term = preg_replace('/(?:ですか|でしょうか|ください|下さい|お願いします)$/u', '', $term);
    $term = preg_replace('/(?:の)?(?:基礎情報|基本情報|建物情報|物件情報|マンション情報|概要|詳細|情報|住所|所在地|築年月|築年数|築|竣工|完成|建築年|構造|総戸数|戸数|階建|階数|最寄り駅|最寄駅|アクセス|徒歩)(?:と|や|、|,|，|・|\s*)?.*$/u', '', $term);
    $term = preg_replace('/[とや、,，・\s]*$/u', '', $term);
    $term = preg_replace('/の$/u', '', $term);
    $term = trim(preg_replace('/\s+/u', ' ', $term));
    return $term;
}

/**
 * Remove natural-language request phrases which follow a mansion name.
 *
 * This is intentionally suffix based: replacing words globally could damage a
 * legitimate building name. The function is run repeatedly because requests may
 * stack phrases such as "の詳しい情報について教えてください".
 */
function chatStripMansionRequestSuffix($text) {
    $text = trim((string)$text);
    if ($text === '') return '';
    $text = preg_replace('/^[\s　「」『』"\']+|[\s　「」『』"\']+$/u', '', $text);

    $patterns = [
        '/\s*(?:について|に関して)?\s*(?:を|が)?\s*(?:詳しく|くわしく)?\s*(?:教えて|知りたい|調べて|検索して|確認して)(?:ください|下さい|ほしい|欲しい|もらえますか|いただけますか)?[。．.!！?？\s　]*$/u',
        '/\s*(?:の)?\s*(?:(?:詳しい|くわしい|詳細な|具体的な|もっと詳しい)\s*)?(?:物件情報|マンション情報|建物情報|基本情報|基礎情報|詳細情報|詳しい情報|情報|詳細|概要)(?:について)?[。．.!！?？\s　]*$/u',
        '/\s*(?:の)?\s*(?:住所|所在地|築年月|築年数|構造|総戸数|戸数|階建|階数|最寄り駅|最寄駅|アクセス)(?:と|や|、|,|，|・|\s|.)*$/u',
    ];

    do {
        $before = $text;
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        // PHP trim() uses a byte mask and corrupts UTF-8 when Japanese punctuation
        // is placed in its character list. Keep Unicode trimming in a regex.
        $text = preg_replace('/^[\s　、。,.．!！?？「」『』"\']+|[\s　、。,.．!！?？「」『』"\']+$/u', '', (string)$text);
        $text = preg_replace('/(?:の|を)$/u', '', $text);
    } while ($text !== $before && $text !== '');

    return trim((string)$text);
}

/**
 * Whether a message is just a building name typed on its own (no field word, no
 * intent, no sentence) — e.g. "キャピタルコータス南砂". These should still trigger a
 * DB lookup. Kept conservative (must contain カタカナ, be short, and not look like
 * a generic real-estate question) so general chat is not hijacked.
 */
function chatMansionLooksLikeBareName($message) {
    $m = trim((string)$message);
    if ($m === '' || mb_strlen($m) > 40) return false;
    if (preg_match('/[。、，,．.！!？?\n]/u', $m)) return false;
    if (!preg_match('/[ァ-ヶｦ-ﾟ]/u', $m)) return false;
    if (preg_match('/(相場|価格|地価|ローン|いくら|教えて|どこ|おすすめ|ランキング|探|住みたい|エリア|周辺|近く|物件は|物件を|物件が)/u', $m)) return false;
    return true;
}

function chatExtractMansionSearchTerms($message) {
    $message = trim((string)$message);
    $terms = [];
    $fieldWords = '基礎情報|基本情報|建物情報|物件情報|マンション情報|概要|詳細|情報|築年月|築年数|築|竣工|完成|建築年|構造|総戸数|戸数|階建|階数|最寄り駅|最寄駅|アクセス|徒歩|住所|所在地';
    // Broad "building-name character" class: kanji, kana (full & half width), Latin
    // & digits (full & half width), 中黒, apostrophes, hyphen/dash/長音 variants,
    // ＆ and spaces. Missing any of these previously sliced names mid-string (e.g.
    // a long vowel typed as "-" or a half-width-kana name) and matched the wrong row.
    $nameChars = '一-龯々〆ぁ-んァ-ヺー\x{3000}・\x{FF65}\x{FF66}-\x{FF9F}A-Za-z0-9０-９Ａ-Ｚａ-ｚ’‘\'\x{2018}\x{2019}\-－―‐\x{301C}\x{FF5E}＆&.．\s';
    $patterns = [
        '/「([^」]{2,80})」/u',
        '/『([^』]{2,80})』/u',
        '/([' . $nameChars . ']{2,80}?)(?:の)?(?:(?:詳しい|くわしい|詳細な|具体的な|もっと詳しい)\s*)?(?:' . $fieldWords . ')/u',
        '/(?:マンション|物件|建物)(?:名)?(?:は|の|：|:)?\s*([' . $nameChars . ']{2,80})/u',
    ];
    // First choice: remove a complete request suffix from the original sentence.
    // This handles phrasing variants without teaching the DB search every adjective.
    $stripped = chatStripMansionRequestSuffix($message);
    if ($stripped !== '' && $stripped !== $message && mb_strlen($stripped) >= 2 && mb_strlen($stripped) <= 80) {
        $preferredTerm = chatNormalizeMansionSearchTerm($stripped);
        if ($preferredTerm !== '') {
            // This candidate is derived from the whole sentence rather than a
            // partial regex capture. Do not mix lower-priority legacy candidates
            // into confidence matching: one bad extra token rejects a valid row.
            return [$preferredTerm];
        }
    }
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) {
            $term = chatNormalizeMansionSearchTerm($m[1]);
            if ($term !== '') $terms[] = $term;
        }
    }
    if (preg_match('/(マンション|物件|建物|' . $fieldWords . ')/u', $message)) {
        $clean = preg_replace('/(について|教えて|ください|下さい|知りたい|調べて|検索して|確認して|どこ|ですか|でしょうか|詳しい|くわしい|詳しく|くわしく|詳細な|具体的な|もっと詳しい|' . $fieldWords . '|マンション名|物件名|建物名|マンション|物件|建物|の|は|を|。|、|\?|？)/u', ' ', $message);
        $clean = chatNormalizeMansionSearchTerm($clean);
        if (mb_strlen($clean) >= 2 && mb_strlen($clean) <= 80) $terms[] = $clean;
    }
    // Fallback: a bare building-name question with location/about intent but no
    // field/物件 keyword ("○○について教えて" / "○○はどこ" / "○○を調べて"). Capture
    // the proper-noun candidate, but only accept names containing カタカナ (almost
    // all マンション names do) so generic area words are not misread as buildings.
    if (empty($terms) && preg_match('/(どこ|場所|所在|について|教えて|調べて|知りたい|検索)/u', $message)) {
        $cand = preg_replace('/(?:について|に関して)?\s*(?:の(?:住所|場所|所在地))?\s*(?:は|を|って|の)?\s*(?:どこ(?:に(?:ある|あります))?(?:か|ですか)?|場所|所在地?|教えて|調べて|検索して|知りたい|ですか|でしょうか|ください|下さい|お願いします)?[\s。、,，.\?？！!]*$/u', '', $message);
        $cand = chatNormalizeMansionSearchTerm($cand);
        if ($cand !== '' && mb_strlen($cand) >= 4 && preg_match('/[ァ-ヶｦ-ﾟ]/u', $cand)) {
            $terms[] = $cand;
        }
    }
    // Bare proper-noun query (just a building name, no field/intent word).
    if (empty($terms) && chatMansionLooksLikeBareName($message)) {
        $cand = chatNormalizeMansionSearchTerm($message);
        if ($cand !== '') $terms[] = $cand;
    }
    // Apply the same suffix cleanup to every extraction route so a lower-priority
    // legacy candidate cannot reintroduce request words and then fail confidence
    // matching even when the first candidate was correct.
    $terms = array_map(function ($term) {
        return chatNormalizeMansionSearchTerm(chatStripMansionRequestSuffix($term));
    }, $terms);
    return array_values(array_unique(array_filter($terms)));
}

/** Unicode script class of a single character, for tokenizing building names. */
function chatMansionCharClass($ch) {
    $cp = function_exists('mb_ord') ? mb_ord($ch, 'UTF-8') : ord($ch);
    if ($cp === false) return 'other';
    if (($cp >= 0x4E00 && $cp <= 0x9FFF) || $cp === 0x3005 || $cp === 0x3006) return 'han';
    if (($cp >= 0x3040 && $cp <= 0x30FF) || ($cp >= 0xFF66 && $cp <= 0xFF9F)) return 'kana';
    if (($cp >= 0x30 && $cp <= 0x39) || ($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A)
        || ($cp >= 0xFF10 && $cp <= 0xFF19) || ($cp >= 0xFF21 && $cp <= 0xFF3A) || ($cp >= 0xFF41 && $cp <= 0xFF5A)) return 'alnum';
    return 'other';
}

/**
 * Split a building-name query into normalized tokens, breaking on spaces, 中黒,
 * punctuation AND script transitions (漢字 / かな / 英数). This makes matching
 * word-order independent: "キャピタルコータス南砂" → [キャピタルコタス, 南砂] which
 * matches the stored "南砂キャピタルコータース" (= [南砂, キャピタルコタス]) even
 * though the order differs and a contiguous substring match would fail.
 */
function chatMansionTokenizeForMatch($s) {
    // Canonicalise the complete name before splitting it by script. Normalising
    // each fragment independently loses the Japanese character immediately before
    // an ASCII Roman suffix (II), so II and 2 previously produced different tokens.
    $s = chatMansionNormalizeText($s);
    $tokens = [];
    foreach (preg_split('/\s+/u', trim($s)) as $word) {
        if ($word === '') continue;
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        $cur = '';
        $curClass = null;
        foreach ($chars as $ch) {
            $cls = chatMansionCharClass($ch);
            if ($curClass !== null && $cls !== $curClass && !($cls === 'other' || $curClass === 'other')) {
                $n = chatMansionNormalizeText($cur);
                if ($n !== '' && (mb_strlen($n) >= 2 || preg_match('/^[0-9]$/', $n))) $tokens[] = $n;
                $cur = '';
            }
            $cur .= $ch;
            $curClass = $cls;
        }
        $n = chatMansionNormalizeText($cur);
        if ($n !== '' && (mb_strlen($n) >= 2 || preg_match('/^[0-9]$/', $n))) $tokens[] = $n;
    }
    return array_values(array_unique($tokens));
}

/**
 * True when the matched row genuinely corresponds to the query: every query token
 * must appear in the row's normalized name+address. For a bare proper-noun query
 * (no field keyword) we additionally require ≥2 tokens, so an ambiguous single
 * word like "ライオンズ" never yields one confidently-wrong building.
 */
function chatMansionRowConfident($row, $terms, $requireMultiToken = false) {
    $tokens = [];
    foreach ((array)$terms as $t) {
        foreach (chatMansionTokenizeForMatch($t) as $tok) $tokens[] = $tok;
    }
    $tokens = array_values(array_unique($tokens));
    if (empty($tokens)) return false;
    if ($requireMultiToken && count($tokens) < 2) return false;
    // Strict (bare-name) match looks at the building NAME only, so a district token
    // that merely matches the address (e.g. "東京タワー" → some 東京 building) cannot
    // produce a confident answer. Field-word queries allow name+address.
    $hay = $requireMultiToken
        ? chatMansionNormalizeText($row['building_name'] ?? '')
        : chatMansionNormalizeText(($row['building_name'] ?? '') . ' ' . ($row['full_address'] ?? ''));
    if ($hay === '') return false;
    foreach ($tokens as $tok) {
        if (mb_strpos($hay, $tok) === false) return false;
    }
    return true;
}

/** Raw LIKE fallbacks for existing rows whose normalized columns predate Roman-number canonicalisation. */
function chatMansionNumericSuffixVariants($term) {
    $term = trim((string)$term);
    if (class_exists('Normalizer')) {
        $kc = Normalizer::normalize($term, Normalizer::FORM_KC);
        if (is_string($kc) && $kc !== '') $term = $kc;
    }
    $term = strtr($term, ['Ⅰ' => 'I', 'Ⅱ' => 'II', 'Ⅲ' => 'III', 'Ⅳ' => 'IV', 'Ⅴ' => 'V', 'Ⅵ' => 'VI', 'Ⅶ' => 'VII', 'Ⅷ' => 'VIII', 'Ⅸ' => 'IX', 'Ⅹ' => 'X']);
    $term = mb_convert_kana($term, 'KVCa');
    $lower = mb_strtolower($term);
    if (!preg_match('/^(.+?)(10|[1-9]|viii|vii|iii|vi|iv|ix|ii|v|x|i)$/u', $lower, $m)) return [];
    $romanToArabic = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10'];
    $number = $romanToArabic[$m[2]] ?? $m[2];
    $unicodeRoman = ['1' => 'Ⅰ', '2' => 'Ⅱ', '3' => 'Ⅲ', '4' => 'Ⅳ', '5' => 'Ⅴ', '6' => 'Ⅵ', '7' => 'Ⅶ', '8' => 'Ⅷ', '9' => 'Ⅸ', '10' => 'Ⅹ'];
    $asciiRoman = ['1' => 'I', '2' => 'II', '3' => 'III', '4' => 'IV', '5' => 'V', '6' => 'VI', '7' => 'VII', '8' => 'VIII', '9' => 'IX', '10' => 'X'];
    $fullWidthNumber = mb_convert_kana($number, 'N');
    return array_values(array_unique([
        $m[1] . $number,
        $m[1] . $fullWidthNumber,
        $m[1] . $unicodeRoman[$number],
        $m[1] . $asciiRoman[$number],
        $m[1] . mb_strtolower($asciiRoman[$number]),
    ]));
}

/** Raw recall variants for existing normalized rows created before ヶ/ケ folding. */
function chatMansionSmallKanaVariants($term) {
    $term = (string)$term;
    $variants = [
        strtr($term, ['ヶ' => 'ケ', 'ゖ' => 'ケ', 'ヵ' => 'カ', 'ゕ' => 'カ']),
        strtr($term, ['ケ' => 'ヶ', 'カ' => 'ヵ']),
    ];
    return array_values(array_unique(array_filter($variants, function ($variant) use ($term) {
        return $variant !== '' && $variant !== $term;
    })));
}

/**
 * Check whether the optional normalized search columns are available.
 *
 * Older deployments created mansion_buildings from add_mansion_buildings.sql,
 * which did not contain these columns. Referencing them unconditionally made the
 * whole query fail and the caller converted that database error into "not found".
 */
function chatMansionDbHasNormalizedColumns($db) {
    if (!$db instanceof PDO) return false;
    static $cache = [];
    $key = function_exists('spl_object_id') ? spl_object_id($db) : spl_object_hash($db);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $db->query("SHOW COLUMNS FROM mansion_buildings WHERE Field IN ('name_norm', 'search_norm')");
        $fields = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        return $cache[$key] = in_array('name_norm', $fields, true) && in_array('search_norm', $fields, true);
    } catch (Throwable $e) {
        error_log('Mansion DB normalized-column check error: ' . $e->getMessage());
        return $cache[$key] = false;
    }
}

/** Search legacy mansion tables without name_norm/search_norm. */
function chatMansionDbSearchLegacyRows($db, $term, $limit) {
    $variants = array_merge(
        [$term],
        chatMansionNumericSuffixVariants($term),
        chatMansionSmallKanaVariants($term)
    );
    // Also expand kana variants of numeric variants (e.g. ヶ丘Ⅱ).
    foreach ($variants as $variant) {
        $variants = array_merge($variants, chatMansionSmallKanaVariants($variant));
    }
    $variants = array_values(array_unique(array_filter(array_map('trim', $variants))));
    if (empty($variants)) return [];

    $where = [];
    $params = [];
    foreach ($variants as $i => $variant) {
        $where[] = "(building_name LIKE :legacy_name{$i} OR search_text LIKE :legacy_search{$i})";
        $params[":legacy_name{$i}"] = '%' . $variant . '%';
        $params[":legacy_search{$i}"] = '%' . $variant . '%';
    }
    // Fetch a small recall set, then use the exact same PHP canonicalizer used by
    // confidence matching. This avoids relying on server collation for Ⅱ/2 etc.
    $fetchLimit = max(20, min(100, (int)$limit * 10));
    $sql = "SELECT id, building_name, postal_code, prefecture, city, town, address_detail, full_address, structure, floors_above, floors_below, built_year_month, total_units, nearest_line, nearest_station, nearest_access_method, nearest_minutes, transports_json
        FROM mansion_buildings
        WHERE " . implode(' OR ', $where) . "
        ORDER BY CHAR_LENGTH(building_name) ASC, id ASC
        LIMIT {$fetchLimit}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $queryNorm = chatMansionNormalizeText($term);
    usort($rows, function ($a, $b) use ($queryNorm) {
        $an = chatMansionNormalizeText($a['building_name'] ?? '');
        $bn = chatMansionNormalizeText($b['building_name'] ?? '');
        $rank = function ($name) use ($queryNorm) {
            if ($name === $queryNorm) return 0;
            if ($queryNorm !== '' && mb_strpos($name, $queryNorm) === 0) return 1;
            if ($queryNorm !== '' && mb_strpos($name, $queryNorm) !== false) return 2;
            return 3;
        };
        $cmp = $rank($an) <=> $rank($bn);
        if ($cmp !== 0) return $cmp;
        $cmp = mb_strlen($an) <=> mb_strlen($bn);
        return $cmp !== 0 ? $cmp : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });
    return array_slice($rows, 0, max(1, (int)$limit));
}

function chatMansionDbSearchRows($db, $terms, $limit = 5) {
    if (!$db instanceof PDO) return [];
    $limit = max(1, min(10, (int)$limit));
    $rows = [];
    $seen = [];
    $hasNormalizedColumns = chatMansionDbHasNormalizedColumns($db);
    foreach (array_slice((array)$terms, 0, 4) as $term) {
        $term = chatNormalizeMansionSearchTerm($term);
        if ($term === '') continue;
        if (!$hasNormalizedColumns) {
            foreach (chatMansionDbSearchLegacyRows($db, $term, $limit) as $row) {
                $key = ($row['building_name'] ?? '') . '|' . ($row['full_address'] ?? '');
                if ($key === '|' || isset($seen[$key])) continue;
                $seen[$key] = true;
                $rows[] = $row;
                if (count($rows) >= $limit) break 2;
            }
            continue;
        }
        // Canonical 表記ブレ-insensitive match. name_norm/search_norm collapse
        // spaces, 中黒, 長音, ハイフン, 記号; raw building_name is a recall fallback
        // (collation folds 全半角/かな/大小文字). Token AND-matching additionally
        // makes word order irrelevant ("A南砂" ⇔ "南砂A").
        $norm = chatMansionNormalizeText($term);
        if ($norm === '') continue;
        $tokens = chatMansionTokenizeForMatch($term);

        $where = ['name_norm LIKE :n_sub', 'search_norm LIKE :s_sub', 'building_name LIKE :raw'];
        $params = [':n_sub' => '%' . $norm . '%', ':s_sub' => '%' . $norm . '%', ':raw' => '%' . $term . '%'];
        foreach (chatMansionNumericSuffixVariants($term) as $i => $variant) {
            $where[] = "building_name LIKE :raw_variant{$i}";
            $params[":raw_variant{$i}"] = '%' . $variant . '%';
        }
        foreach (chatMansionSmallKanaVariants($term) as $i => $variant) {
            $where[] = "building_name LIKE :raw_kana_variant{$i}";
            $params[":raw_kana_variant{$i}"] = '%' . $variant . '%';
        }
        // Token AND-match. Placeholders are duplicated between WHERE and ORDER BY,
        // and this PDO connection has emulated prepares off (each placeholder must
        // be bound exactly once), so use a separate :ont* set for the ORDER BY copy.
        $nameAndWhere = '1=0';
        $nameAndOrder = '1=0';
        if (count($tokens) >= 2) {
            $nameAnd = [];
            $nameAndOrd = [];
            $searchAnd = [];
            foreach ($tokens as $i => $tok) {
                $nameAnd[] = "name_norm LIKE :nt{$i}";
                $nameAndOrd[] = "name_norm LIKE :ont{$i}";
                $searchAnd[] = "search_norm LIKE :st{$i}";
                $params[":nt{$i}"] = '%' . $tok . '%';
                $params[":ont{$i}"] = '%' . $tok . '%';
                $params[":st{$i}"] = '%' . $tok . '%';
            }
            $nameAndWhere = '(' . implode(' AND ', $nameAnd) . ')';
            $nameAndOrder = '(' . implode(' AND ', $nameAndOrd) . ')';
            $where[] = $nameAndWhere;
            $where[] = '(' . implode(' AND ', $searchAnd) . ')';
        }
        $params[':neq'] = $norm;
        $params[':npre'] = $norm . '%';
        $params[':s_sub2'] = '%' . $norm . '%';

        $sql = "SELECT id, building_name, postal_code, prefecture, city, town, address_detail, full_address, structure, floors_above, floors_below, built_year_month, total_units, nearest_line, nearest_station, nearest_access_method, nearest_minutes, transports_json
            FROM mansion_buildings
            WHERE " . implode(' OR ', $where) . "
            ORDER BY CASE
                WHEN name_norm = :neq THEN 0
                WHEN name_norm LIKE :npre THEN 1
                WHEN {$nameAndOrder} THEN 2
                WHEN search_norm LIKE :s_sub2 THEN 3
                ELSE 4 END,
                CHAR_LENGTH(name_norm) ASC, id ASC
            LIMIT {$limit}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = ($row['building_name'] ?? '') . '|' . ($row['full_address'] ?? '');
            if ($key === '|' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $rows[] = $row;
            if (count($rows) >= $limit) break 2;
        }
    }
    return $rows;
}

function chatMansionTermLooksSpecific($terms, $message) {
    if (preg_match('/(マンション名|物件名|建物名|「|『)/u', (string)$message)) return true;
    foreach ((array)$terms as $term) {
        $term = chatNormalizeMansionSearchTerm($term);
        if ($term === '') continue;
        // A bare short place name like "福岡市"/"中野区" is not specific enough on its
        // own. But a building name that merely ENDS in 道/町/区 (e.g. "グランドメゾン百道"
        // — 百道 is a 福岡 district) is a real proper noun and IS specific. A ≥3-char
        // カタカナ run is a reliable brand/building marker, so never treat such a term
        // as a generic place name. (Previously "百道" → 末尾「道」を北海道扱いし誤って棄却。)
        $hasKatakanaRun = (bool)preg_match('/[ァ-ヶ]{3,}/u', $term);
        if (!$hasKatakanaRun
            && preg_match('/^[一-龥ぁ-んァ-ン]+(?:都|道|府|県|市|区|町|村)(?:の)?(?:マンション|物件|建物)?$/u', $term)
            && mb_strlen($term) <= 14) continue;
        if (mb_strlen($term) >= 3) return true;
    }
    return false;
}

function chatMansionRequestedFields($message) {
    $message = (string)$message;
    $fields = [];
    if (preg_match('/(住所|所在地|所在|場所|どこ)/u', $message)) $fields[] = 'address';
    if (preg_match('/(築年月|築年数|築|竣工|完成|建築年)/u', $message)) $fields[] = 'built';
    if (preg_match('/構造/u', $message)) $fields[] = 'structure';
    if (preg_match('/(総戸数|戸数)/u', $message)) $fields[] = 'units';
    if (preg_match('/(階建|階数|地下|地上)/u', $message)) $fields[] = 'floors';
    if (preg_match('/(最寄り駅|最寄駅|アクセス|徒歩|駅)/u', $message)) $fields[] = 'station';
    if (empty($fields) && preg_match('/(概要|情報|詳細|について|教えて|知りたい|調べて|検索)/u', $message)) {
        $fields = ['address', 'built', 'station', 'structure', 'floors', 'units'];
    }
    return array_values(array_unique($fields));
}

function chatMansionBuiltAgeLabel($builtYearMonth) {
    $value = trim((string)$builtYearMonth);
    if ($value === '') return '';
    $normalized = mb_convert_kana($value, 'n');
    if (!preg_match('/((?:19|20)\d{2})(?:\D*(\d{1,2}))?/u', $normalized, $m)) return $value;
    $year = (int)$m[1];
    $month = isset($m[2]) && $m[2] !== '' ? max(1, min(12, (int)$m[2])) : null;
    $age = (int)date('Y') - $year;
    if ($month !== null && (int)date('n') < $month) $age--;
    $age = max(0, $age);
    $label = $month !== null ? sprintf('%04d年%d月', $year, $month) : sprintf('%04d年', $year);
    return $label . '（築' . $age . '年目安）';
}

function chatMansionFormatFacts($row, $fields) {
    $facts = [];
    foreach ((array)$fields as $field) {
        if ($field === 'address' && !empty($row['full_address'])) {
            $facts[] = '住所：' . $row['full_address'];
        } elseif ($field === 'built') {
            $built = chatMansionBuiltAgeLabel($row['built_year_month'] ?? '');
            if ($built !== '') $facts[] = '築年月：' . $built;
        } elseif ($field === 'structure' && !empty($row['structure'])) {
            $facts[] = '構造：' . $row['structure'];
        } elseif ($field === 'units' && !empty($row['total_units'])) {
            $facts[] = '総戸数：' . (int)$row['total_units'] . '戸';
        } elseif ($field === 'floors') {
            $floorParts = [];
            if (!empty($row['floors_above'])) $floorParts[] = '地上' . (int)$row['floors_above'] . '階';
            if (!empty($row['floors_below'])) $floorParts[] = '地下' . (int)$row['floors_below'] . '階';
            if (!empty($floorParts)) $facts[] = '階数：' . implode('・', $floorParts);
        } elseif ($field === 'station') {
            $stationParts = [];
            if (!empty($row['nearest_line'])) $stationParts[] = $row['nearest_line'];
            if (!empty($row['nearest_station'])) {
                $station = $row['nearest_station'];
                if (mb_substr($station, -1) !== '駅') $station .= '駅';
                $stationParts[] = $station;
            }
            $access = trim((string)($row['nearest_access_method'] ?? ''));
            if (!empty($row['nearest_minutes'])) {
                $access = ($access !== '' ? $access : '徒歩') . (int)$row['nearest_minutes'] . '分';
            }
            if ($access !== '') $stationParts[] = $access;
            if (!empty($stationParts)) $facts[] = '最寄り：' . implode(' ', $stationParts);
        }
    }
    return array_values(array_unique($facts));
}

function chatMansionDbContext($db, $message, $force = false) {
    if (!$db instanceof PDO) return null;
    if (!$force && !preg_match('/(マンション|物件|建物|基礎情報|基本情報|建物情報|物件情報|マンション情報|概要|詳細|情報|築年月|築年数|竣工|構造|総戸数|戸数|階建|最寄り駅|最寄駅|物件名|住所|所在地|アクセス)/u', $message)) return null;
    $terms = chatExtractMansionSearchTerms($message);
    if (empty($terms)) return null;
    try {
        $rows = chatMansionDbSearchRows($db, $terms, 5);
        if (empty($rows)) return null;
        $rows = array_slice($rows, 0, 5);
        return [
            'provider' => 'mansion_db',
            'title' => '全国マンションデータベース検索結果',
            'notice' => '当社の全国マンションデータベースで物件情報を確認します。',
            'data' => $rows,
            'record_count' => count($rows),
            'total_count' => count($rows),
            'fetched_at' => date('Y-m-d H:i:s'),
            'cached' => false,
        ];
    } catch (Throwable $e) {
        error_log('Mansion DB context error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Build a disambiguation reply when a mansion-name query matches several genuinely
 * different buildings. Per the accuracy policy we must NOT auto-pick one row: we
 * list the candidates and ask the user to specify before answering any fact.
 * Returns null when fewer than 2 distinct candidates can be listed.
 */
function chatMansionDisambiguationAnswer($terms, $candidates) {
    $queryLabel = '';
    foreach ((array)$terms as $t) {
        $t = trim((string)$t);
        if ($t !== '') { $queryLabel = $t; break; }
    }
    $lines = [];
    $quickReplies = [];
    foreach ($candidates as $r) {
        $name = trim((string)($r['building_name'] ?? ''));
        if ($name === '') continue;
        $fullAddress = trim((string)($r['full_address'] ?? ''));
        $loc = trim((string)($r['prefecture'] ?? '') . (string)($r['city'] ?? ''));
        if ($loc === '') $loc = $fullAddress;
        $label = $name . ($loc !== '' ? '（' . $loc . '）' : '');
        $lines[] = '・' . $label;
        $quickReplies[] = [
            'label' => $label,
            // Prefer the immutable DB id. Name/address remains a compatibility
            // fallback for legacy rows or non-DB AI candidates without an id.
            'value' => !empty($r['id'])
                ? 'mansion_id:' . (int)$r['id']
                : $name . ($fullAddress !== '' ? ' ' . $fullAddress : ($loc !== '' ? ' ' . $loc : '')),
            'field' => 'mansion_lookup',
        ];
    }
    if (count($lines) < 2) return null;
    $head = ($queryLabel !== '' ? '「' . $queryLabel . '」' : 'ご入力の名称')
        . 'に近い物件が複数見つかりました。どの物件について確認しますか？';
    $reply = $head . "\n\n" . implode("\n", $lines)
        . "\n\n下の候補ボタンから選択してください。正式名称や所在エリアを入力して選ぶこともできます。";
    return [
        'reply' => $reply,
        'sources' => [],
        'row' => null,
        'meta' => [],
        'ambiguous' => true,
        'quick_replies' => $quickReplies,
    ];
}

/** Resolve a customer-selected candidate by its immutable mansion DB id. */
function chatMansionDbFindRowById($db, $id) {
    if (!$db instanceof PDO || (int)$id <= 0) return null;
    try {
        $stmt = $db->prepare("SELECT id, building_name, postal_code, prefecture, city, town, address_detail, full_address, structure, floors_above, floors_below, built_year_month, total_units, nearest_line, nearest_station, nearest_access_method, nearest_minutes, transports_json FROM mansion_buildings WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('Mansion DB id lookup error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Convert property candidates written as list items in an AI reply into the same
 * structured buttons used by deterministic DB disambiguation.
 *
 * Only lines containing both a candidate name and a Japanese location qualifier
 * in parentheses are accepted. This deliberately ignores ordinary prose and
 * factual bullet lists such as 築年月/構造/総戸数.
 */
function chatMansionQuickRepliesFromAiReply($reply) {
    $reply = trim((string)$reply);
    if ($reply === '') return [];

    $quickReplies = [];
    $seen = [];
    foreach (preg_split('/\R/u', $reply) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (!preg_match('/^(?:[-・●▪◦]|[0-9０-９]{1,2}[\.．、\)）])\s*[「『"]?(.{2,80}?)[」』"]?\s*[（(]([^）)]{1,80}(?:都|道|府|県|市|区|町|村)[^）)]*)[）)]\s*$/u', $line, $m)) {
            continue;
        }
        $name = trim((string)$m[1]);
        $location = trim((string)$m[2]);
        if ($name === '' || preg_match('/^(?:所在地|住所|築年月|構造|規模|総戸数|アクセス)$/u', $name)) continue;

        $key = chatMansionNormalizeText($name . '|' . $location);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $label = $name . '（' . $location . '）';
        $quickReplies[] = [
            'label' => $label,
            'value' => $name . ' ' . $location,
            'field' => 'mansion_lookup',
        ];
        if (count($quickReplies) >= 5) break;
    }
    return $quickReplies;
}

/**
 * Debug logging for マンション検索・紹介生成 (req. ⑧). Active only when CHAT_MANSION_DEBUG
 * is on, so production stays quiet. The GPT context and reply are logged in full
 * because the whole point is to verify what was sent to / returned by the model.
 */
function chatMansionDebugLog($label, $value) {
    if (!(defined('CHAT_MANSION_DEBUG') && CHAT_MANSION_DEBUG)) return;
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log('[MansionRAG] ' . $label . ': ' . $value);
}

/**
 * 土地情報取得のデバッグログ。入力住所・正規化住所・ジオコーディング結果・各API
 * リクエスト/レスポンス・最終的な取得方法などを追跡できるようにする。
 * CHAT_LAND_DEBUG または CHAT_MANSION_DEBUG が有効なときだけ出力する。
 */
function chatLandDebugLog($label, $value = '') {
    $on = (defined('CHAT_LAND_DEBUG') && CHAT_LAND_DEBUG) || (defined('CHAT_MANSION_DEBUG') && CHAT_MANSION_DEBUG);
    if (!$on) return;
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log('[LandInfo] ' . $label . ': ' . $value);
}

/**
 * 日本の住所を検索・ジオコーディング用に正規化する。
 *  - 郵便番号（〒123-4567 / 123-4567）を除去
 *  - 全角英数・スペースを半角へ
 *  - 丁目の漢数字を算用数字へ（"三丁目"→"3丁目"）
 *  - 「N丁目M番地L号」等を「N-M-L」のハイフン形式へ統一
 *  - ダッシュ類（－‐ー―等）を半角ハイフンへ
 * 変換に失敗しても元の住所を壊さないよう、可能な範囲で整えて返す。
 */
function chatNormalizeJapaneseAddress($addr) {
    $s = trim((string)$addr);
    if ($s === '') return '';
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_KC);
        if (is_string($n) && $n !== '') $s = $n;
    }
    // 全角英数字→半角、全角スペース→半角
    $s = mb_convert_kana($s, 'as');
    // 郵便番号を除去（〒付き / 数字のみ 3-4桁）
    $s = preg_replace('/〒\s*/u', '', $s);
    $s = preg_replace('/\b\d{3}[-－]?\d{4}\b/u', ' ', $s);
    // 丁目の漢数字→算用数字
    $s = chatChomeKanjiToArabic($s);
    // ダッシュ類を半角ハイフンへ統一
    $s = preg_replace('/[－‐ー―—\x{2010}-\x{2015}\x{2212}\x{FF0D}]/u', '-', $s);
    // 「N丁目」「N番地」「N番」→「N-」、「N号」→「N」
    $s = preg_replace('/(\d+)\s*丁目/u', '$1-', $s);
    $s = preg_replace('/(\d+)\s*番地/u', '$1-', $s);
    $s = preg_replace('/(\d+)\s*番/u', '$1-', $s);
    $s = preg_replace('/(\d+)\s*号/u', '$1', $s);
    // ハイフン周りの空白を除去し、連続ハイフンをまとめる
    $s = preg_replace('/\s*-\s*/u', '-', $s);
    $s = preg_replace('/-{2,}/u', '-', $s);
    // 末尾の余分なハイフンを除去
    $s = preg_replace('/-+$/u', '', $s);
    // 余分な空白を除去
    $s = preg_replace('/\s{2,}/u', ' ', $s);
    return trim($s);
}

/**
 * 住所を段階的にジオコーディングして緯度経度を得る（安定化のための多段リトライ）。
 *   ① 入力住所そのままで検索
 *   ② 正規化住所で検索
 *   ③ さらに末尾の号・番地を落とした粗い住所（町丁目レベル）で検索
 * 取得できた時点でその結果を返し、どの方法で取得したかを 'method' に格納する。
 * すべて失敗したら null。各段階をデバッグログへ出力する。
 */
function chatGeocodeAddressRobust($db, $address) {
    $raw = trim((string)$address);
    if ($raw === '') return null;
    chatLandDebugLog('geocode_input', $raw);

    // ① 入力住所そのまま
    $geo = chatAddressGeocode($db, $raw);
    if ($geo) {
        $geo['method'] = 'address_raw';
        chatLandDebugLog('geocode_success', ['method' => 'address_raw', 'lat' => $geo['lat'], 'lon' => $geo['lon'], 'title' => $geo['title'] ?? '']);
        return $geo;
    }

    // ② 正規化住所
    $norm = chatNormalizeJapaneseAddress($raw);
    chatLandDebugLog('geocode_normalized', $norm);
    if ($norm !== '' && $norm !== $raw) {
        $geo = chatAddressGeocode($db, $norm);
        if ($geo) {
            $geo['method'] = 'address_normalized';
            chatLandDebugLog('geocode_success', ['method' => 'address_normalized', 'lat' => $geo['lat'], 'lon' => $geo['lon'], 'title' => $geo['title'] ?? '']);
            return $geo;
        }
    }

    // ③ 号・番地を落とした粗い住所（町丁目レベル）— 精度ガードを避けて座標だけでも取得
    $coarse = preg_replace('/-\d+$/u', '', $norm);
    if ($coarse !== '' && $coarse !== $norm) {
        $geo = chatAddressGeocode($db, $coarse);
        if ($geo) {
            $geo['method'] = 'address_coarse';
            chatLandDebugLog('geocode_success', ['method' => 'address_coarse', 'lat' => $geo['lat'], 'lon' => $geo['lon'], 'title' => $geo['title'] ?? '']);
            return $geo;
        }
    }

    chatLandDebugLog('geocode_failed', ['raw' => $raw, 'normalized' => $norm]);
    return null;
}

/**
 * All transit options stored for a building (up to 14), not just the nearest one.
 * Reads transports_json with a graceful fallback to the nearest_* columns. Each line
 * is "路線 駅 徒歩N分" with the 駅 suffix normalized.
 */
function chatMansionTransitLines($row) {
    $transports = [];
    if (!empty($row['transports_json'])) {
        $decoded = json_decode((string)$row['transports_json'], true);
        if (is_array($decoded)) $transports = $decoded;
    }
    if (empty($transports)) {
        $transports = [[
            'line' => $row['nearest_line'] ?? '',
            'station' => $row['nearest_station'] ?? '',
            'method' => $row['nearest_access_method'] ?? '',
            'minutes' => $row['nearest_minutes'] ?? null,
        ]];
    }
    $lines = [];
    foreach ($transports as $t) {
        if (!is_array($t)) continue;
        $line = trim((string)($t['line'] ?? ''));
        $station = trim((string)($t['station'] ?? ''));
        if ($line === '' && $station === '') continue;
        if ($station !== '' && mb_substr($station, -1) !== '駅') $station .= '駅';
        $method = trim((string)($t['method'] ?? ''));
        $minutes = isset($t['minutes']) && $t['minutes'] !== null && $t['minutes'] !== '' ? (int)$t['minutes'] : null;
        $access = '';
        if ($minutes !== null) $access = ($method !== '' ? $method : '徒歩') . $minutes . '分';
        elseif ($method !== '') $access = $method;
        $parts = array_filter([$line, $station, $access], static function ($v) { return $v !== ''; });
        if (!empty($parts)) $lines[] = implode(' ', $parts);
    }
    return array_values(array_unique($lines));
}

/**
 * The real, DB-backed facts for one building as label => value pairs. ONLY columns
 * that actually exist in mansion_buildings are returned — 売主/施工/管理/価格/坪単価/
 * 共用施設/学区/ハザード等はDBに存在しないため絶対に含めない（捏造防止の核心）。
 * Empty/unknown fields are omitted so the model is never tempted to fill a blank.
 */
function chatMansionGatherFacts($row) {
    $facts = [];
    $name = trim((string)($row['building_name'] ?? ''));
    if ($name !== '') $facts['マンション名'] = $name;
    if (!empty($row['full_address'])) $facts['所在地'] = trim((string)$row['full_address']);
    $built = chatMansionBuiltAgeLabel($row['built_year_month'] ?? '');
    if ($built !== '') $facts['築年月'] = $built;
    if (!empty($row['structure'])) $facts['構造'] = trim((string)$row['structure']);
    $floorParts = [];
    if (!empty($row['floors_above'])) $floorParts[] = '地上' . (int)$row['floors_above'] . '階';
    if (!empty($row['floors_below'])) $floorParts[] = '地下' . (int)$row['floors_below'] . '階';
    if (!empty($floorParts)) $facts['階数'] = implode('・', $floorParts);
    if (!empty($row['total_units'])) $facts['総戸数'] = (int)$row['total_units'] . '戸';
    $transit = chatMansionTransitLines($row);
    if (!empty($transit)) $facts['交通'] = $transit;
    return $facts;
}

/**
 * Compact "ラベル：値" context block for the GPT prompt, built ONLY from real facts.
 * Kept small to control API cost (最終目標：必要十分な情報のみGPTへ渡す). Returns '' when
 * there is nothing to describe.
 */
function chatMansionFactsToContext($facts) {
    $lines = [];
    foreach ((array)$facts as $key => $value) {
        if (is_array($value)) {
            if (empty($value)) continue;
            $lines[] = $key . '：' . implode(' / ', $value);
        } else {
            $value = trim((string)$value);
            if ($value === '') continue;
            $lines[] = $key . '：' . $value;
        }
    }
    return implode("\n", $lines);
}

/**
 * Generate a natural, 営業担当者-style introduction for ONE building using GPT, from
 * the DB facts only. Roles are strictly separated (役割分離): the 全国マンションDB
 * supplies facts; GPT only rewrites them into readable prose. The system prompt
 * forbids inventing any field the DB does not hold (売主/価格/共用施設/学区/ハザード等) —
 * for those it must say they aren't registered, never guess. Returns the prose, or
 * null on any failure so the caller falls back to the deterministic facts reply.
 */
function chatMansionGenerateIntroduction($facts, $agentName = '担当者') {
    if (!function_exists('callOpenAIChat')) return null;
    $context = chatMansionFactsToContext($facts);
    if ($context === '') return null;
    $model = function_exists('chatOpenAIModelMansion') ? chatOpenAIModelMansion()
        : (defined('OPENAI_CHAT_MODEL') ? OPENAI_CHAT_MODEL : 'gpt-4o-mini');
    $apiKey = function_exists('chatOpenAIApiKeyForModel') ? chatOpenAIApiKeyForModel($model)
        : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
    if ($apiKey === '') return null;
    $name = $facts['マンション名'] ?? 'このマンション';
    $agentLabel = trim((string)$agentName) !== '' ? trim((string)$agentName) : '担当者';

    $system = <<<SYS
あなたは経験豊富な不動産営業担当者「{$agentLabel}」です。お客様へ、全国マンションデータベースから取得した下記の【物件データ】だけを使ってマンションを紹介してください。

絶対ルール：
- 【物件データ】に書かれている事実のみを使う。書かれていない項目（売主・施工会社・管理会社・管理方式・価格・坪単価・共用施設・学区・周辺施設・ハザード情報・リセール等）は推測・創作を一切しない。
- それらの情報が無い場合、無理に章立てを埋めず、「当社データベースには登録がありません」と正直に伝え、必要なら私（{$agentLabel}）が個別にお調べします、と添える。
- データの数値・固有名詞は変えない。築年数の概算（築○年目安）はそのまま使ってよい。
- 所在地（住所）は【物件データ】の表記を、丁目・番地・号まで一字一句省略せずそのまま記載する（「○丁目」などに短縮しない）。
- 専門用語の羅列ではなく、お客様が読みやすい自然な文章にする。丸写しではなく、立地・規模・築年・アクセスといった特徴や魅力が伝わるように説明する。
- 出力は本文のみ。出典表記やデータ取得情報のフッターは付けない（システム側で付与する）。

構成の目安（データがある項目だけ／全体で簡潔に）：
1. 物件概要（所在地・築年月・構造・階数・総戸数）を自然な文章で。
2. 交通アクセスの利便性。
3. 営業担当者として、どのような方に向いているか等の一言。
SYS;

    $user = "【物件データ】\n" . $context . "\n\n上記の「{$name}」について、お客様向けの紹介文を作成してください。";
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
    chatMansionDebugLog('gpt_model', $model);
    chatMansionDebugLog('gpt_context_chars', mb_strlen($context));
    chatMansionDebugLog('gpt_context', $context);
    $result = callOpenAIChat($messages, $apiKey, $model, [
        'purpose' => 'mansion_intro',
        'max_tokens' => 700,
        'temperature' => 0.4,
        'timeout' => 30,
    ]);
    if (!empty($result['error']) || empty($result['reply'])) {
        chatMansionDebugLog('gpt_error', $result['error'] ?? 'empty reply');
        return null;
    }
    $reply = $result['reply'];
    if (function_exists('sanitizeChatReferralLanguage')) $reply = sanitizeChatReferralLanguage($reply, $agentLabel);
    if (function_exists('unifyAgentPersonaLanguage')) $reply = unifyAgentPersonaLanguage($reply, $agentLabel);
    $reply = trim($reply);
    chatMansionDebugLog('gpt_reply', $reply);
    return $reply !== '' ? $reply : null;
}

/**
 * Buildings whose name belongs to the same "family" as $row — i.e. the same base
 * name with a different phase number or 別棟 (パレステージ江北 / 江北２ / 江北３ / 江北３
 * 東館 …). Returned so a direct answer can offer clickable candidates letting the
 * customer switch when the auto-selected exact match was not the intended building
 * (accuracy policy: ユーザー選択で誤回答を防ぐ). Excludes $row itself; null-graceful.
 */
function chatMansionSiblingCandidates($db, $row, $limit = 5) {
    if (!$db instanceof PDO || empty($row['building_name'])) return [];
    $chosenId = (int)($row['id'] ?? 0);
    $chosenNorm = chatMansionNormalizeText($row['building_name']);
    if ($chosenNorm === '') return [];
    // Base family name = chosen name_norm with a trailing phase number and/or
    // 東西南北+館 suffix stripped, so 江北2 / 江北3東館 collapse to base 江北.
    $base = preg_replace('/([0-9]+|[東西南北中]{0,2}館).*$/u', '', $chosenNorm);
    if ($base === null || mb_strlen($base) < 2) $base = $chosenNorm;
    $limit = max(1, min(8, (int)$limit));
    try {
        if (!chatMansionDbHasNormalizedColumns($db)) return [];
        // Prefix match on the indexed name_norm column keeps this cheap. Siblings
        // must share the base name; unrelated buildings recalled only via an address
        // number (spurious token hits) are deliberately excluded.
        $stmt = $db->prepare(
            "SELECT id, building_name, prefecture, city, full_address
             FROM mansion_buildings
             WHERE name_norm LIKE :base AND id <> :cid
             ORDER BY CHAR_LENGTH(name_norm) ASC, id ASC
             LIMIT {$limit}"
        );
        $stmt->execute([':base' => $base . '%', ':cid' => $chosenId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('chatMansionSiblingCandidates error: ' . $e->getMessage());
        return [];
    }
}

/** Build the final answer from one already-retrieved DB row (the generation stage of RAG). */
function chatMansionBuildAnswerFromRow($row, $fields, $agentName = '担当者', $recordCount = 1, $hasSimilarRows = false, $similarRows = []) {
    if (!is_array($row) || empty($row['building_name'])) return null;
    $facts = chatMansionFormatFacts($row, $fields);
    $source = chatPublicDataSourceLabel('mansion_db');
    $fetchedAt = date('Y-m-d H:i:s');

    // Only this retrieved row is passed to the LLM. The prompt explicitly forbids
    // adding facts which are absent from chatMansionGatherFacts().
    $intro = chatMansionGenerateIntroduction(chatMansionGatherFacts($row), $agentName);
    if ($intro !== null && $intro !== '') {
        $reply = $intro;
    } else {
        if (empty($facts)) return null;
        $reply = $row['building_name'] . 'について、当社データベースでは次の内容を確認できます。' . "\n\n・" . implode("\n・", $facts);
    }

    $fullAddr = trim((string)($row['full_address'] ?? ''));
    if ($fullAddr !== '') {
        $normReply = chatMansionNormalizeText($reply);
        $normAddr = chatMansionNormalizeText($fullAddr);
        if ($normAddr !== '' && mb_strpos($normReply, $normAddr) === false) {
            $reply .= "\n\n所在地：" . $fullAddr;
        }
    }
    // Clickable candidates for the same-name family (別番号・別棟) so the customer can
    // switch when the auto-selected exact match was not the intended building. Values
    // carry the immutable mansion id → the reliable ID-selection RAG path.
    $siblingReplies = [];
    $seenSibling = [];
    foreach ((array)$similarRows as $sib) {
        if (!is_array($sib) || empty($sib['building_name']) || empty($sib['id'])) continue;
        if ((int)$sib['id'] === (int)($row['id'] ?? 0)) continue;
        $sibName = trim((string)$sib['building_name']);
        $sibLoc = trim((string)($sib['prefecture'] ?? '') . (string)($sib['city'] ?? ''));
        if ($sibLoc === '') $sibLoc = trim((string)($sib['full_address'] ?? ''));
        $key = chatMansionNormalizeText($sibName . '|' . $sibLoc);
        if ($key === '' || isset($seenSibling[$key])) continue;
        $seenSibling[$key] = true;
        $siblingReplies[] = [
            'label' => $sibName . ($sibLoc !== '' ? '（' . $sibLoc . '）' : ''),
            'value' => 'mansion_id:' . (int)$sib['id'],
            'field' => 'mansion_lookup',
        ];
        if (count($siblingReplies) >= 5) break;
    }

    if (!empty($siblingReplies)) {
        $reply .= "\n\n※名称のよく似た物件が他にもあります。別の物件をお探しの場合は、下の候補ボタンからお選びください。";
    } elseif ($hasSimilarRows) {
        $reply .= "\n\n※似た名称の候補が他にもあります。別の物件の場合は、住所やエリアを添えていただくと、より正確に絞り込めます。";
    }
    $reply .= "\n\n出典：" . $source;
    $meta = [[
        'provider' => 'mansion_db',
        'label' => $source,
        'record_count' => max(1, (int)$recordCount),
        'total_count' => max(1, (int)$recordCount),
        'fetched_at' => $fetchedAt,
        'cached' => false,
    ]];
    $footer = chatPublicDataTransparencyFooter($meta);
    if ($footer !== '') $reply .= "\n\n" . $footer;
    // Sibling candidate buttons (mansion_lookup) render as clickable choices in the
    // reply bubble; the land/hazard button remains a footer quick action.
    $quickReplies = $siblingReplies;
    if ($fullAddr !== '') {
        $quickReplies[] = [
            'label' => '土地/ハザード情報を確認',
            'value' => $fullAddr,
            'field' => 'land_hazard',
        ];
    }

    return [
        'reply' => $reply,
        'sources' => chatPublicDataSourcesForUi([$source], $meta),
        'row' => $row,
        'meta' => $meta,
        'quick_replies' => $quickReplies,
    ];
}

/** ID-selected RAG path: retrieve exactly one row, then generate only from that row. */
function chatMansionDbDirectAnswerById($db, $id, $message, $agentName = '担当者') {
    $row = chatMansionDbFindRowById($db, $id);
    if ($row === null) return null;
    $fields = chatMansionRequestedFields($message);
    if (empty($fields)) $fields = ['address', 'built', 'station', 'structure', 'floors', 'units'];
    chatMansionDebugLog('chosen_building_id', (int)$id . ' | ' . ($row['building_name'] ?? ''));
    $siblingCandidates = chatMansionSiblingCandidates($db, $row, 5);
    return chatMansionBuildAnswerFromRow($row, $fields, $agentName, 1, false, $siblingCandidates);
}

function chatMansionDbDirectAnswer($db, $message, $agentName = '担当者') {
    if (!$db instanceof PDO) return null;
    $hasKeyword = (bool)preg_match('/(マンション|物件|建物|基礎情報|基本情報|建物情報|物件情報|マンション情報|概要|詳細|情報|築年月|築年数|築|竣工|構造|総戸数|戸数|階建|最寄り駅|最寄駅|住所|所在地|所在|アクセス|どこ|場所|について|教えて|調べて|知りたい|検索)/u', (string)$message);
    $isBareName = chatMansionLooksLikeBareName($message);
    if (!$hasKeyword && !$isBareName) return null;
    $terms = chatExtractMansionSearchTerms($message);
    if (empty($terms) || !chatMansionTermLooksSpecific($terms, $message)) return null;
    $fields = chatMansionRequestedFields($message);
    // A bare building name ("○○タワー") is treated as an overview request.
    if (empty($fields)) {
        if (!$isBareName) return null;
        $fields = ['address', 'built', 'station', 'structure', 'floors', 'units'];
    }

    try {
        chatMansionDebugLog('extracted_terms', $terms);
        chatMansionDebugLog('search_method', '完全一致→前方一致→トークンAND→部分一致(LIKE) を単一クエリで評価');
        $rows = chatMansionDbSearchRows($db, $terms, 5);
        chatMansionDebugLog('hit_count', count($rows));
        if (empty($rows)) return null;
        $hasLocationQualifier = (bool)preg_match(
            '/[（(][^）)]*(?:都|道|府|県|市|区|町|村)[^）)]*[）)]|(?:東京都|北海道|京都府|大阪府|[一-龥]{2,3}県)/u',
            (string)$message
        );

        // Separate an actual building-name equality from the broader recall query.
        // SQL intentionally returns prefix/token/substring matches too; without
        // this step an exact row could be mixed with similarly named buildings.
        $termNorms = [];
        foreach ($terms as $term) {
            $termNorm = chatMansionNormalizeText(chatNormalizeMansionSearchTerm($term));
            if ($termNorm !== '') $termNorms[$termNorm] = true;
        }
        $exactRows = [];
        foreach ($rows as $candidateRow) {
            $candidateNorm = chatMansionNormalizeText($candidateRow['building_name'] ?? '');
            if ($candidateNorm !== '' && isset($termNorms[$candidateNorm])) {
                $exactRows[] = $candidateRow;
            }
        }

        if (!empty($exactRows)) {
            // Exact equality always outranks prefix/substring candidates.
            $rows = $exactRows;
        } elseif (!$hasLocationQualifier) {
            // No exact name AND no location hint: this is a similarity search.
            // When several distinct rows were recalled, do not silently choose one
            // using token confidence; show every candidate as a mansion_lookup
            // button and let the customer select.
            $similarDistinct = [];
            foreach ($rows as $candidateRow) {
                $key = chatMansionNormalizeText(($candidateRow['building_name'] ?? '') . '|' . ($candidateRow['full_address'] ?? ''));
                if ($key !== '' && !isset($similarDistinct[$key])) $similarDistinct[$key] = $candidateRow;
            }
            if (count($similarDistinct) > 1) {
                $suggestions = chatMansionDisambiguationAnswer($terms, array_slice(array_values($similarDistinct), 0, 5));
                if ($suggestions !== null) return $suggestions;
            }
        }
        // else: no exact name match but the user supplied a location qualifier —
        // e.g. selected/typed "パレステージ江北２（東京都足立区）". The name+location
        // string never equals a bare building_name, so $exactRows is empty here.
        // Keep the recalled $rows and let the confidence + location filtering below
        // pick the right building. (Previously $rows was overwritten with the empty
        // $exactRows, so a just-selected candidate was answered
        // 「該当物件が見つかりませんでした」.)

        // Only answer when a row genuinely matches the query (all tokens present in
        // its name+address). For a bare name we also require ≥2 tokens so an
        // ambiguous single word never produces one confidently-wrong building.
        // A candidate is commonly selected as "建物名（東京都足立区）".  That is
        // not a bare-name lookup: the parenthesised location is an explicit
        // disambiguator and must be matched against name+address.  Previously it
        // was matched against building_name alone, so the same row shown in the
        // candidate list was rejected immediately after the customer selected it.
        $requireMulti = $isBareName && !$hasKeyword && !$hasLocationQualifier;
        $confident = [];
        foreach ($rows as $r) {
            if (chatMansionRowConfident($r, $terms, $requireMulti)) $confident[] = $r;
        }
        if (empty($confident)) return null;
        // Accuracy policy (req. 4): when the query matches several genuinely different
        // buildings, never auto-pick one. List the distinct candidates (by name+市区
        // 町村) and ask the user to specify before answering any property fact.
        $distinct = [];
        foreach ($confident as $r) {
            $key = chatMansionNormalizeText(($r['building_name'] ?? '') . '|' . ($r['city'] ?? ''));
            if (!isset($distinct[$key])) $distinct[$key] = $r;
        }
        if (count($distinct) > 1) {
            $disambig = chatMansionDisambiguationAnswer($terms, array_slice(array_values($distinct), 0, 5));
            if ($disambig !== null) return $disambig;
        }
        $row = reset($confident);
        chatMansionDebugLog('chosen_building', ($row['building_name'] ?? '') . ' | ' . ($row['full_address'] ?? ''));
        // Offer the same-name family (別番号・別棟) as clickable candidates so the
        // customer can switch if this exact match was not the intended building.
        $siblingCandidates = chatMansionSiblingCandidates($db, $row, 5);
        return chatMansionBuildAnswerFromRow(
            $row,
            $fields,
            $agentName,
            count($rows),
            count($rows) > count($confident),
            $siblingCandidates
        );
    } catch (Throwable $e) {
        error_log('Mansion DB direct answer error: ' . $e->getMessage());
    }

    return null;
}

/** 土地/ハザード情報の照会意図があるか（用途地域・建ぺい率・浸水・災害 等）。 */
function chatMessageAsksLandInfo($message) {
    return (bool)preg_match('/(土地情報|土地の情報|土地|ハザード|災害|防災|浸水|洪水|水害|土砂|地盤|液状化|津波|高潮|急傾斜|都市計画|用途地域|建ぺい率|建蔽率|容積率|区域区分|市街化|防火|地区計画)/u', (string)$message);
}

/**
 * メッセージ中のマンション名を全国マンションDBで解決し、正式な住所を返す。
 * 「土地」「ハザード」等の照会語を除去してから建物名を抽出するため、
 * 「エルザタワー55の土地情報」からでも建物を特定できる。
 * @return array|null ['building_name','full_address','row'] または null
 */
function chatResolveMansionAddress($db, $message) {
    if (!$db instanceof PDO) return null;
    // 土地/ハザード等の照会語を除去（建物名抽出のノイズになるため）。
    $clean = preg_replace('/(土地の個別情報|土地情報|土地|ハザード(?:情報|マップ)?|災害|防災|浸水|洪水|水害|土砂災害|土砂|地盤|液状化|津波|高潮|急傾斜地?|都市計画|用途地域|建ぺい率|建蔽率|容積率|区域区分|市街化(?:調整)?区域|市街化|防火(?:・準防火)?地域|防火|地区計画)/u', ' ', (string)$message);
    try {
        $terms = chatExtractMansionSearchTerms($clean);
        if (empty($terms) || !chatMansionTermLooksSpecific($terms, $clean)) return null;
        $rows = chatMansionDbSearchRows($db, $terms, 5);
        if (empty($rows)) return null;
        foreach ($rows as $r) {
            if (chatMansionRowConfident($r, $terms, false) && !empty($r['full_address'])) {
                return [
                    'building_name' => trim((string)($r['building_name'] ?? '')),
                    'full_address'  => trim((string)$r['full_address']),
                    'row' => $r,
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('chatResolveMansionAddress error: ' . $e->getMessage());
    }
    return null;
}

/**
 * 「マンション名＋土地/ハザード照会」を検出し、DBから住所を解決して
 * 標準の土地情報フローに渡せるクエリ（住所入り）を組み立てる。
 * 既に住所が含まれている場合や、マンションを特定できない場合は null（＝通常処理）。
 * @return array|null ['building_name','full_address','query']
 */
function chatMansionLandQueryAddress($db, $message) {
    $message = (string)$message;
    if (!chatMessageAsksLandInfo($message)) return null;
    // 住所が既に入力されている場合は、通常の土地情報フローがそのまま扱える。
    if (chatMessageContainsAddress($message)) return null;
    $resolved = chatResolveMansionAddress($db, $message);
    if ($resolved === null) return null;
    $addr = $resolved['full_address'];
    // 住所＋土地キーワードを含むクエリにして、公的データ取得ゲート・ルーターを通す。
    $query = $addr . ' の土地情報・ハザード情報（用途地域・建ぺい率・容積率・都市計画・浸水／土砂／液状化など）を教えてください';
    chatLandDebugLog('mansion_land_resolved', ['building' => $resolved['building_name'], 'address' => $addr]);
    return [
        'building_name' => $resolved['building_name'],
        'full_address'  => $addr,
        'query' => $query,
    ];
}

function chatPublicDataTrimForPrompt($data, $maxLength = 4000) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) return '';
    return mb_strlen($json) > $maxLength ? mb_substr($json, 0, $maxLength) . "\n...（一部省略）" : $json;
}

/**
 * Convert WGS84 lat/lon to slippy-map tile coordinates at zoom $z.
 * Used to address the reinfolib GIS tile APIs (XKT***) which take z/x/y.
 */
function chatGeoLatLonToTile($lat, $lon, $z) {
    $lat = max(-85.05112878, min(85.05112878, (float)$lat));
    $n = 1 << (int)$z;
    $x = (int)floor(($lon + 180.0) / 360.0 * $n);
    $latRad = deg2rad($lat);
    $y = (int)floor((1.0 - log(tan($latRad) + 1.0 / cos($latRad)) / M_PI) / 2.0 * $n);
    $x = max(0, min($n - 1, $x));
    $y = max(0, min($n - 1, $y));
    return ['z' => (int)$z, 'x' => $x, 'y' => $y];
}

/**
 * Resolve a station name to lat/lon using the HeartRails Express API (no key
 * required, station-specific so far more reliable than a generic geocoder).
 * When $prefName is given, a station in that prefecture is preferred to
 * disambiguate same-named stations. Cached via the shared cache table.
 */
function chatStationGeocode($db, $stationName, $prefName = null) {
    $name = preg_replace('/駅$/u', '', trim((string)$stationName));
    if ($name === '') return null;
    $url = 'https://express.heartrails.com/api/json?method=getStations&name=' . rawurlencode($name);
    $result = chatPublicDataCachedGet($db, 'station_geocode', $url, [], 2592000, 6);
    $stations = $result['data']['response']['station'] ?? null;
    if (!is_array($stations) || empty($stations)) return null;
    $chosen = null;
    if ($prefName) {
        foreach ($stations as $s) {
            if (isset($s['prefecture']) && mb_strpos((string)$s['prefecture'], $prefName) !== false) { $chosen = $s; break; }
        }
    }
    if (!$chosen) $chosen = $stations[0];
    if (!isset($chosen['x'], $chosen['y'])) return null;
    return [
        'lon' => (float)$chosen['x'],
        'lat' => (float)$chosen['y'],
        'title' => ($chosen['name'] ?? $name) . '駅',
        'prefecture' => $chosen['prefecture'] ?? null,
    ];
}

/**
 * Extract the station name a 乗降客数 question is about. Falls back to a noun
 * captured right before 乗降/利用者/乗客 when the area extractor found no 〜駅.
 */
function chatReinfoStationName($message, $area) {
    $station = $area['station_name'] ?? null;
    if ($station) return $station;
    $message = (string)$message;
    if (preg_match('/([一-龥ぁ-んァ-ンA-Za-z0-9０-９ヶケー]{2,14}?)駅?(?:の|は|について|で)?\s*(?:乗降|乗車|降車|利用者|乗客)/u', $message, $m)) {
        $name = trim($m[1]);
        if ($name !== '') return mb_substr($name, -1) === '駅' ? $name : $name . '駅';
    }
    return null;
}

/**
 * XKT015: 駅別乗降客数. Geocode the station → tile coords → fetch the GeoJSON
 * tile (center first, then its 3x3 neighbours) → match the station by name and
 * report the most recent available annual passenger counts. Null-graceful.
 */
function chatReinfoStationContext($db, $message, $area) {
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') return null;
    $stationQuery = chatReinfoStationName($message, $area);
    if (!$stationQuery) return null;
    $geo = chatStationGeocode($db, $stationQuery, $area['prefecture_name'] ?? null);
    if (!$geo) return null;

    $z = 14;
    $center = chatGeoLatLonToTile($geo['lat'], $geo['lon'], $z);
    // Scan the centre tile first, then the surrounding ring, stopping as soon as
    // the station is found. Caching makes repeated tile fetches cheap.
    $offsets = [[0,0],[-1,0],[1,0],[0,-1],[0,1],[-1,-1],[-1,1],[1,-1],[1,1]];
    $needle = preg_replace('/駅$/u', '', $stationQuery);
    $yearMap = ['S12_009'=>2011,'S12_013'=>2012,'S12_017'=>2013,'S12_021'=>2014,'S12_025'=>2015,'S12_029'=>2016,'S12_033'=>2017,'S12_037'=>2018,'S12_041'=>2019,'S12_045'=>2020,'S12_049'=>2021,'S12_053'=>2022,'S12_057'=>2023];
    $matches = [];
    $fetchedAt = null;
    $cached = true;
    foreach ($offsets as $off) {
        if (!empty($matches)) break;
        $tx = $center['x'] + $off[0];
        $ty = $center['y'] + $off[1];
        $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/XKT015?' . http_build_query([
            'response_format' => 'geojson',
            'z' => $z,
            'x' => $tx,
            'y' => $ty,
        ]);
        $result = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], 2592000);
        $fetchedAt = $result['fetched_at'] ?? $fetchedAt;
        if (empty($result['cached'])) $cached = false;
        if (!$result['ok'] || !is_array($result['data'])) continue;
        $features = $result['data']['features'] ?? [];
        if (!is_array($features)) continue;
        foreach ($features as $feature) {
            $props = $feature['properties'] ?? null;
            if (!is_array($props)) continue;
            $name = (string)($props['S12_001_ja'] ?? '');
            if ($name === '' || ($needle !== '' && mb_strpos($name, $needle) === false && mb_strpos($needle, $name) === false)) continue;
            $latestYear = null;
            $latestCount = null;
            foreach ($yearMap as $field => $year) {
                $val = $props[$field] ?? null;
                if ($val === null || $val === '' || (int)$val <= 0) continue;
                $latestYear = $year;
                $latestCount = (int)$val;
            }
            if ($latestCount === null) continue;
            $matches[] = [
                'station' => $name,
                'company' => (string)($props['S12_002_ja'] ?? ''),
                'line' => (string)($props['S12_003_ja'] ?? ''),
                'year' => $latestYear,
                'passengers_per_day' => $latestCount,
            ];
        }
    }
    if (empty($matches)) return null;
    $matches = array_slice($matches, 0, 8);
    $scope = $stationQuery . 'の駅別乗降客数（路線・事業者別、年間）';
    return [
        'provider' => 'reinfolib',
        'title' => '駅別乗降客数（XKT015）',
        'notice' => $stationQuery . 'の乗降客数を公的データ（不動産情報ライブラリ）で確認します。',
        'data' => $matches,
        'record_count' => count($matches),
        'total_count' => count($matches),
        'scope_note' => $scope,
        'count_note' => 'passengers_per_day は国土数値情報（駅別乗降客数 S12）の「1日あたりの平均乗降客数（人/日）」です。年間値ではありません。year はその数値の集計年度です。同一駅でも路線・事業者ごとに別レコードのため、合算する場合は乗り換えの重複に注意してください。事業者により未集計（0）の年があり、その場合は直近で取得できた年の値を表示しています。',
        'fetched_at' => $fetchedAt ?? date('Y-m-d H:i:s'),
        'cached' => $cached,
    ];
}

/**
 * Geocode a free-form address / city name to lat/lon using the GSI (国土地理院)
 * address-search API (no key required). Returns the best (first) match.
 */
function chatAddressGeocode($db, $query) {
    $q = trim((string)$query);
    if ($q === '') return null;
    $url = 'https://msearch.gsi.go.jp/address-search/AddressSearch?q=' . rawurlencode($q);
    $result = chatPublicDataCachedGet($db, 'gsi_geocode', $url, [], 2592000, 6);
    $data = $result['data'];
    if (!is_array($data) || empty($data[0]['geometry']['coordinates'])) return null;
    $best = $data[0];
    $c = $best['geometry']['coordinates'];
    if (!isset($c[0], $c[1])) return null;
    $matchedTitle = (string)($best['properties']['title'] ?? '');
    // Precision guard for non-existent addresses. GSI AddressSearch fuzzy-matches:
    // for a street number that does not exist it does NOT fail — it returns the
    // 市区町村/町丁目 centroid instead. That centroid lands on a reinfolib tile that
    // may already be cached from an earlier (valid) lookup, so the previous fetch's
    // data would be surfaced as if it were this address. When the query specifies a
    // street-level address (丁目/番地/号 or an N-N number) but GSI only matched a
    // coarser level (no 丁目 and no number in the matched title), treat it as a
    // geocoding failure so the caller reports 0 件 instead of stale tile data.
    $queryHasStreet = preg_match('/[0-9０-９]+\s*(?:丁目|番地?|号)/u', $q)
        || preg_match('/[0-9０-９]+\s*[-－‐ー―]\s*[0-9０-９]+/u', $q);
    if ($queryHasStreet && $matchedTitle !== '') {
        $matchHasStreet = preg_match('/(?:丁目|[0-9０-９]+\s*(?:番|号)|[0-9０-９]+\s*[-－‐ー―]\s*[0-9０-９]+|[0-9０-９]+$)/u', $matchedTitle);
        if (!$matchHasStreet) return null;
    }
    return [
        'lon' => (float)$c[0],
        'lat' => (float)$c[1],
        'title' => $matchedTitle !== '' ? $matchedTitle : $q,
        'prefecture' => null,
    ];
}

/**
 * Load the GSI 市区町村コード master (maps.gsi.go.jp/js/muni.js) as a map of
 * muniCd => ['pref' => 都道府県名, 'city' => 市区町村名]. Used to turn the muniCd
 * returned by the reverse geocoder into a human-readable place name. The raw file
 * is JavaScript (not JSON), so we fetch it directly, parse the lines once, and
 * cache the PARSED map as JSON in chat_public_data_cache for 30 days.
 */
function chatGsiMuniMap($db) {
    static $map = null;
    if (is_array($map)) return $map;
    $cacheKey = hash('sha256', 'gsi_muni_map|https://maps.gsi.go.jp/js/muni.js');
    if ($db instanceof PDO) {
        ensureChatPublicDataCacheTable($db);
        $stmt = $db->prepare('SELECT response_json FROM chat_public_data_cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
        $stmt->execute([$cacheKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['response_json'])) {
            $decoded = json_decode($row['response_json'], true);
            if (is_array($decoded)) { $map = $decoded; return $map; }
        }
    }
    $map = [];
    $res = chatPublicDataHttpGet('https://maps.gsi.go.jp/js/muni.js', [], 15);
    $body = $res['body'] ?? '';
    if ($body !== '' && preg_match_all('/MUNI_ARRAY\["(\d+)"\]\s*=\s*\'([^\']*)\'/u', $body, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            // value looks like: 11,埼玉県,11203,川口市  (都道府県コード,都道府県名,市区町村コード,市区町村名)
            $parts = explode(',', $m[2]);
            if (count($parts) >= 4) {
                $map[$m[1]] = [
                    'pref' => trim($parts[1]),
                    'city' => trim(str_replace('　', '', $parts[3])),
                ];
            }
        }
    }
    if ($db instanceof PDO && !empty($map)) {
        $stmt = $db->prepare("INSERT INTO chat_public_data_cache (cache_key, provider, request_url, response_json, http_status, error_message, expires_at)
            VALUES (?, 'gsi_muni_map', 'https://maps.gsi.go.jp/js/muni.js', ?, 200, NULL, DATE_ADD(NOW(), INTERVAL 2592000 SECOND))
            ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), expires_at = VALUES(expires_at), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$cacheKey, json_encode($map, JSON_UNESCAPED_UNICODE)]);
    }
    return $map;
}

/** 漢数字（〜99）を算用数字に変換。"三"→3, "十"→10, "二十一"→21。失敗時 null。 */
function chatKanjiNumToArabic($s) {
    $s = (string)$s;
    $map = ['〇'=>0,'一'=>1,'二'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];
    if (preg_match('/^([一二三四五六七八九]?)十([一二三四五六七八九]?)$/u', $s, $m)) {
        $tens = $m[1] === '' ? 1 : $map[$m[1]];
        $ones = $m[2] === '' ? 0 : $map[$m[2]];
        return $tens * 10 + $ones;
    }
    if (mb_strlen($s) === 1 && isset($map[$s])) return $map[$s];
    return null;
}

/** 町丁目の漢数字を算用数字に直す（"本町三丁目"→"本町3丁目"）。表示用。 */
function chatChomeKanjiToArabic($town) {
    return preg_replace_callback('/([〇一二三四五六七八九十]+)丁目/u', function ($m) {
        $n = chatKanjiNumToArabic($m[1]);
        return ($n === null ? $m[1] : $n) . '丁目';
    }, (string)$town);
}

/**
 * Reverse-geocode a lat/lon to a Japanese place name using the GSI reverse
 * geocoder (no key required). Returns ['lat','lon','title','prefecture','town']
 * where title is 都道府県+市区町村+町丁目 (e.g. "埼玉県川口市本町3丁目"). The GPS
 * point fixes the tile lookups; the title is only for display. Null on failure.
 * NOTE: free reverse geocoding resolves only to 町丁目 level — 番地・号 is not
 * available, so callers must treat the address as a 町丁目-level label and rely on
 * the lat/lon (point-in-polygon) for the actual zone/hazard values.
 */
function chatReverseGeocode($db, $lat, $lon) {
    $lat = (float)$lat; $lon = (float)$lon;
    $url = 'https://mreversegeocoder.gsi.go.jp/reverse-geocoder/LonLatToAddress?lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lon);
    $res = chatPublicDataCachedGet($db, 'gsi_reverse', $url, [], 2592000, 8);
    $data = $res['data'] ?? null;
    if (!is_array($data) || empty($data['results'])) return null;
    $muniCd = trim((string)($data['results']['muniCd'] ?? ''));
    $town   = trim((string)($data['results']['lv01Nm'] ?? ''));
    if ($town === '－' || $town === '-') $town = '';
    if ($town !== '') $town = chatChomeKanjiToArabic($town);
    $pref = ''; $city = '';
    if ($muniCd !== '') {
        $muniMap = chatGsiMuniMap($db);
        $entry = $muniMap[$muniCd] ?? ($muniMap[ltrim($muniCd, '0')] ?? null);
        if (is_array($entry)) { $pref = $entry['pref']; $city = $entry['city']; }
    }
    $title = $pref . $city . $town;
    return [
        'lat' => $lat,
        'lon' => $lon,
        'title' => $title !== '' ? $title : null,
        'prefecture' => $pref !== '' ? $pref : null,
        'town' => $town,
        'precise' => false,
    ];
}

/**
 * Reverse-geocode a lat/lon to a precise Japanese street address using the
 * Google Maps Platform Geocoding API (Reverse Geocoding). Unlike the free GSI
 * reverse geocoder (chatReverseGeocode, 町丁目 level only), Google resolves down
 * to 番地・号 — e.g. "東京都中野区本町6丁目27-14" instead of "…本町6丁目". Returns
 * ['lat','lon','title','prefecture','town','precise'=>true] with title cleaned of
 * the "日本"/郵便番号 prefix. Null when the key is unset or the API fails, so the
 * caller falls back to chatReverseGeocode(). The API key is used strictly
 * server-side — it is never sent to the browser (this call runs in PHP).
 */
function chatGoogleReverseGeocode($db, $lat, $lon) {
    if (!defined('GOOGLE_GEOCODING_API_KEY') || GOOGLE_GEOCODING_API_KEY === '') return null;
    $lat = (float)$lat; $lon = (float)$lon;
    $url = 'https://maps.googleapis.com/maps/api/geocode/json'
        . '?latlng=' . rawurlencode($lat . ',' . $lon)
        . '&language=ja&region=jp'
        . '&key=' . rawurlencode(GOOGLE_GEOCODING_API_KEY);
    $res = chatPublicDataCachedGet($db, 'google_reverse', $url, [], 2592000, 8);
    $data = $res['data'] ?? null;
    if (!is_array($data) || ($data['status'] ?? '') !== 'OK' || empty($data['results'])) return null;

    // Prefer a clean 番地・号 result (street_address / premise) over results[0],
    // which can be a nearby POI/公園名など. Fall back to results[0] otherwise. The
    // formatted_address is "日本、〒164-0012 東京都中野区本町6丁目27−14" style; strip
    // the country name and postal code so only the readable address remains.
    $best = $data['results'][0];
    foreach ($data['results'] as $r) {
        $types = $r['types'] ?? [];
        if (in_array('street_address', $types, true) || in_array('premise', $types, true)) {
            $best = $r;
            break;
        }
    }
    $formatted = trim((string)($best['formatted_address'] ?? ''));
    $formatted = preg_replace('/^日本[、,\s]*/u', '', $formatted);
    $formatted = preg_replace('/〒?\d{3}-?\d{4}\s*/u', '', $formatted);
    $formatted = mb_convert_kana(trim($formatted), 'n'); // 全角数字→半角（6丁目27-14 の見栄え）
    if ($formatted === '') return null;

    // 都道府県名は administrative_area_level_1 から拾う（下流の prefecture_name 用）。
    $pref = null;
    foreach ($data['results'] as $r) {
        foreach (($r['address_components'] ?? []) as $c) {
            if (in_array('administrative_area_level_1', $c['types'] ?? [], true)) {
                $pref = $c['long_name'] ?? null;
                break 2;
            }
        }
    }

    return [
        'lat' => $lat,
        'lon' => $lon,
        'title' => $formatted,
        'prefecture' => $pref,
        'town' => $formatted,
        'precise' => true,
    ];
}

/**
 * Resolve a lat/lon for the area a question is about: prefer a named station,
 * then fall back to GSI geocoding of 都道府県+市区町村. Used by every reinfolib
 * GIS tile API to address the right XYZ tile. Null when nothing is resolvable.
 */
function chatReinfoResolveLatLon($db, $area, $message) {
    if (isset($area['lat'], $area['lon'])) {
        return [
            'lat' => (float)$area['lat'],
            'lon' => (float)$area['lon'],
            'title' => $area['title'] ?? trim((string)$message),
            'prefecture' => $area['prefecture_name'] ?? null,
        ];
    }
    if (!empty($area['station_name'])) {
        $geo = chatStationGeocode($db, $area['station_name'], $area['prefecture_name'] ?? null);
        if ($geo) return $geo;
    }
    // Extract the precise street-level address from the message — works whether it
    // was typed alone or inside a sentence ("…のハザードを教えて"). Resolve THAT
    // address; if it cannot be geocoded, do NOT fall back to the 市区町村 centroid
    // (which would point at an unrelated, often already-cached tile and surface it
    // as this address's data — see chatAddressGeocode()'s precision guard).
    $addr = chatExtractAddressFromMessage($message);
    if ($addr !== null) {
        // 住所の正規化＋多段リトライで、同じ住所でも安定して座標を取得できるようにする。
        $geo = chatGeocodeAddressRobust($db, $addr);
        return $geo ?: null;
    }
    // No street-level address present (e.g. a general "渋谷区の地価" question): use
    // the 都道府県+市区町村 centroid extracted into $area.
    $coarse = trim(($area['prefecture_name'] ?? '') . ($area['city_name'] ?? ''));
    if ($coarse !== '') {
        $geo = chatAddressGeocode($db, $coarse);
        if ($geo) return $geo;
    }
    return null;
}

/**
 * 取得した各ハザード項目の生データ（GeoJSONプロパティを整形した data 行）を GPT で
 * 一括解析し、お客様が理解しやすい自然な説明文（summary）を項目ごとに生成する。
 * ・APIの定型文（「これは指定地点を含む区域のGISデータです」等）ではなく、取得値そのものを
 *   噛み砕いた文章にするのが目的。
 * ・data が空の項目はスキップ（count_note にフォールバック）。
 * ・GPT失敗時は何も付与しない（フロントが従来の count_note を表示）。
 * @param array $items chatHazardAddressReport が集めた status='data' の項目配列（参照渡しで summary を付与）
 */
function chatHazardSummarizeItems(array &$items) {
    if (empty($items) || !function_exists('callOpenAIChat')) return;

    // GPTに渡す各項目の生データを、コード・見出し・値行だけにコンパクト化する。
    $payload = [];
    foreach ($items as $idx => $it) {
        $rows = isset($it['data']) && is_array($it['data']) ? $it['data'] : [];
        if (empty($rows)) continue; // 生データが無ければ要約対象外
        $code = (string)($it['code'] ?? ('item' . $idx));
        // タイトル末尾の「（XKT025）」等のコード表記は不要なので落とす。
        $title = trim(preg_replace('/（[A-Z0-9]+）\s*$/u', '', (string)($it['title'] ?? '')));
        $payload[] = [
            'code'  => $code,
            'title' => $title,
            'scope' => (string)($it['scope_note'] ?? ''),
            'rows'  => array_slice($rows, 0, 6),
        ];
    }
    if (empty($payload)) return;

    $model = function_exists('chatOpenAIModelSummary') ? chatOpenAIModelSummary()
        : (defined('OPENAI_CHAT_MODEL') ? OPENAI_CHAT_MODEL : 'gpt-4o-mini');
    $apiKey = function_exists('chatOpenAIApiKeyForModel') ? chatOpenAIApiKeyForModel($model)
        : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
    if ($apiKey === '') return;

    $system = <<<SYS
あなたは経験豊富な不動産営業担当者です。国土交通省・不動産情報ライブラリの公開データ（ハザード・地盤・防災情報）を、お客様が理解しやすい自然な日本語で説明します。

絶対ルール：
- 与えられた【取得データ】の値のみを使う。書かれていない事実の推測・創作は一切しない。
- 各項目を1〜2文の平易な文章にまとめる。専門用語や項目コード、「GISデータ」「基準点」「レコード」等のシステム用語は使わない。
- 数値・区分名・施設名などの固有の値はそのまま正確に伝える。
- 不安を過度に煽らず、断定しすぎず、事実を淡々と分かりやすく伝える。避難場所・災害履歴などは、その内容が何を意味するかを一言添える。
- 他社・他機関への相談を促す表現は書かない。
- 出力は必ずJSONのみ。形式: {"項目コード":"説明文", ...}。説明文やコードフェンスは付けない。
SYS;

    $user = "【取得データ】（項目コードごとの値）\n"
        . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\n各項目コードについて、お客様向けの自然な説明文を作成し、JSONで返してください。";
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
    $result = callOpenAIChat($messages, $apiKey, $model, [
        'purpose' => 'hazard_summary',
        'max_tokens' => 900,
        'temperature' => 0.3,
        'timeout' => 40,
    ]);
    if (!empty($result['error']) || empty($result['reply'])) {
        chatLandDebugLog('hazard_summary_error', ['error' => $result['error'] ?? 'empty reply']);
        return;
    }

    $reply = trim((string)$result['reply']);
    $reply = preg_replace('/^```[a-zA-Z]*\s*/', '', $reply);
    $reply = preg_replace('/```$/', '', trim($reply));
    $s = strpos($reply, '{'); $e = strrpos($reply, '}');
    if ($s === false || $e === false) return;
    $map = json_decode(substr($reply, $s, $e - $s + 1), true);
    if (!is_array($map)) return;

    foreach ($items as &$it) {
        $code = (string)($it['code'] ?? '');
        if ($code === '' || !isset($map[$code])) continue;
        $summary = trim((string)$map[$code]);
        if ($summary === '') continue;
        if (function_exists('sanitizeChatReferralLanguage')) $summary = sanitizeChatReferralLanguage($summary);
        $it['summary'] = $summary;
    }
    unset($it);
}

function chatHazardAddressReport($db, $address) {
    if (!$db instanceof PDO) return null;
    $address = trim((string)$address);
    if ($address === '') return null;
    $geo = chatAddressGeocode($db, $address);
    if (!$geo) return [
        'address' => $address,
        'geocoded' => null,
        'items' => [],
        'record_count' => 0,
        'message' => '住所の座標を取得できませんでした（取得件数0件）。実在しない住所の可能性があります。',
    ];

    $area = [
        'lat' => $geo['lat'],
        'lon' => $geo['lon'],
        'title' => $geo['title'] ?? $address,
    ];
    $catalog = chatReinfoApiCatalog();
    $hazardKeys = ['XKT026', 'XKT029', 'XKT028', 'XKT027', 'XKT025', 'XKT022', 'XKT021', 'XKT020', 'XKT016', 'XST001', 'XGT001'];
    $items = [];
    foreach ($hazardKeys as $key) {
        if (!isset($catalog[$key])) continue;
        $item = chatReinfoCatalogContext($db, $key, $catalog[$key], $address . ' ハザード 災害 防災', $area);
        // The hazard map UI lists only layers the point actually falls inside;
        // 区域外/該当なし/取得失敗 status items are for the chat answer, not this list.
        if ($item && ($item['status'] ?? 'data') === 'data') {
            $item['code'] = $key;
            $items[] = $item;
        }
    }
    // 取得したハザードデータ（生JSON）を GPT で解析し、お客様向けの自然な説明文を各項目に付与する。
    // 失敗しても致命ではない（フロントは従来の count_note にフォールバックする）。
    try { chatHazardSummarizeItems($items); } catch (Throwable $e) { error_log('hazard summarize error: ' . $e->getMessage()); }
    return [
        'address' => $address,
        'geocoded' => $geo,
        'items' => $items,
        'record_count' => count($items),
        'message' => empty($items) ? '指定住所周辺で取得できるハザードデータは見つかりませんでした（取得件数0件）。' : 'ハザード関連データを取得しました。',
    ];
}

/** Ray-casting point-in-polygon test for a single linear ring ([[lon,lat],...]). */
function chatGeoPointInRing($lon, $lat, $ring) {
    if (!is_array($ring)) return false;
    $inside = false;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        if (!isset($ring[$i][0], $ring[$i][1], $ring[$j][0], $ring[$j][1])) continue;
        $xi = (float)$ring[$i][0]; $yi = (float)$ring[$i][1];
        $xj = (float)$ring[$j][0]; $yj = (float)$ring[$j][1];
        $denom = ($yj - $yi) ?: 1e-12;
        if ((($yi > $lat) !== ($yj > $lat)) && ($lon < ($xj - $xi) * ($lat - $yi) / $denom + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}

/** Whether a WGS84 point falls inside a GeoJSON Polygon / MultiPolygon geometry. */
function chatGeoPointInFeature($lon, $lat, $geom) {
    if (!is_array($geom)) return false;
    $type = $geom['type'] ?? '';
    $coords = $geom['coordinates'] ?? null;
    if (!is_array($coords)) return false;
    if ($type === 'Polygon') return chatGeoPointInRing($lon, $lat, $coords[0] ?? []);
    if ($type === 'MultiPolygon') {
        foreach ($coords as $poly) {
            if (is_array($poly) && chatGeoPointInRing($lon, $lat, $poly[0] ?? [])) return true;
        }
    }
    return false;
}

/**
 * Reduce raw GeoJSON features to the rows most relevant to the query point:
 *  - polygon: features whose polygon contains the point (the location's zone /
 *    hazard area). Falls back to the first $limit features when nothing contains
 *    the point (e.g. point sits on a tile/area boundary).
 *  - point: the $limit nearest features by squared planar distance.
 *  - other (lines etc.): the first $limit features in the tile.
 * Returns ['rows'=>[properties...], 'total'=>int].
 */
function chatGeoFilterFeatures($features, $lon, $lat, $geom, $limit = 8) {
    $propsOf = function ($f) {
        return is_array($f) && isset($f['properties']) && is_array($f['properties']) ? $f['properties'] : null;
    };
    if ($geom === 'polygon') {
        $matched = [];
        foreach ($features as $f) {
            $p = $propsOf($f);
            if ($p === null) continue;
            if (chatGeoPointInFeature($lon, $lat, $f['geometry'] ?? null)) $matched[] = $p;
        }
        if (empty($matched)) {
            foreach (array_slice($features, 0, $limit) as $f) {
                $p = $propsOf($f);
                if ($p !== null) $matched[] = $p;
            }
        }
        return ['rows' => array_slice($matched, 0, $limit), 'total' => count($matched)];
    }
    if ($geom === 'point') {
        $arr = [];
        foreach ($features as $f) {
            $p = $propsOf($f);
            if ($p === null) continue;
            $c = $f['geometry']['coordinates'] ?? null;
            $d = PHP_FLOAT_MAX;
            if (is_array($c) && isset($c[0], $c[1])) {
                $dx = (float)$c[0] - $lon; $dy = (float)$c[1] - $lat;
                $d = $dx * $dx + $dy * $dy;
            }
            $arr[] = ['d' => $d, 'p' => $p];
        }
        usort($arr, function ($a, $b) { return $a['d'] <=> $b['d']; });
        $rows = array_map(function ($x) { return $x['p']; }, array_slice($arr, 0, $limit));
        return ['rows' => $rows, 'total' => count($arr)];
    }
    $rows = [];
    foreach (array_slice($features, 0, $limit) as $f) {
        $p = $propsOf($f);
        if ($p !== null) $rows[] = $p;
    }
    return ['rows' => $rows, 'total' => count($features)];
}

/**
 * Single source of truth for every remaining 不動産情報ライブラリ public API
 * (everything except XIT001/XIT002/XKT015, which keep their bespoke handlers).
 * Each entry drives both routing (keywords/description) and fetching (endpoint
 * code, tile zoom range, geometry handling, required params, field whitelist).
 *
 *   type    : 'tile' (default, XYZ GIS APIs) | 'query' (XCT001)
 *   geom    : 'polygon' (zone at the point) | 'point' (nearest facilities) | 'other'
 *   zmin/zmax: valid zoom range from each API's manual page
 *   params  : extra query params; a closure(int $year) for year-dependent APIs
 *   fields  : optional [propertyKey => 日本語ラベル] whitelist for cleaner output
 */
function chatReinfoApiCatalog() {
    return [
        // --- 価格・地価・鑑定 ---------------------------------------------
        'XPT001' => [
            'title' => '不動産取引価格ポイント', 'geom' => 'point', 'zmin' => 11, 'zmax' => 15,
            'keywords' => '取引価格|成約価格|取引事例|売買事例',
            'description' => '地点周辺の不動産取引価格・成約価格のポイントデータ',
            'params' => function ($year) { return ['from' => ($year - 2) . '1', 'to' => ($year - 1) . '4']; },
        ],
        'XPT002' => [
            'title' => '地価公示・地価調査ポイント', 'geom' => 'point', 'zmin' => 13, 'zmax' => 15,
            'keywords' => '地価公示|地価調査|公示価格|基準地価|地価',
            'description' => '地点周辺の地価公示・地価調査（標準地・基準地の価格）',
            'params' => function ($year) { return ['year' => ($year - 2)]; },
        ],
        'XCT001' => [
            'type' => 'query',
            'title' => '鑑定評価書情報', 'keywords' => '鑑定|鑑定評価|評価書|路線価',
            'description' => '不動産鑑定評価書情報（標準地の価格・路線価・法令規制・鑑定手法）',
        ],
        // --- 都市計画 -----------------------------------------------------
        'XKT001' => [
            'title' => '都市計画区域・区域区分', 'geom' => 'polygon',
            'keywords' => '都市計画区域|区域区分|市街化区域|市街化調整区域|非線引き',
            'description' => '都市計画区域および市街化区域・市街化調整区域の区分',
            'fields' => [
                'area_classification_ja' => '区域区分', 'city_name' => '市区町村', 'prefecture' => '都道府県',
            ],
        ],
        'XKT002' => [
            'title' => '用途地域', 'geom' => 'polygon',
            'keywords' => '用途地域|建蔽率|建ぺい率|容積率',
            'description' => '用途地域・建蔽率・容積率（都市計画決定GISデータ）',
            'fields' => [
                'use_area_ja' => '用途地域', 'u_building_coverage_ratio_ja' => '建蔽率',
                'u_floor_area_ratio_ja' => '容積率', 'city_name' => '市区町村', 'prefecture' => '都道府県',
            ],
        ],
        'XKT003' => [
            'title' => '立地適正化計画', 'geom' => 'polygon',
            'keywords' => '立地適正化|居住誘導|都市機能誘導',
            'description' => '立地適正化計画（居住誘導区域・都市機能誘導区域）',
        ],
        'XKT014' => [
            'title' => '防火・準防火地域', 'geom' => 'polygon',
            'keywords' => '防火地域|準防火地域|防火',
            'description' => '防火地域・準防火地域（都市計画決定GISデータ）',
            'fields' => [
                'fire_prevention_ja' => '防火・準防火地域', 'city_name' => '市区町村', 'prefecture' => '都道府県',
            ],
        ],
        'XKT023' => [
            'title' => '地区計画', 'geom' => 'polygon',
            'keywords' => '地区計画',
            'description' => '地区計画（都市計画決定GISデータ）',
        ],
        'XKT024' => [
            'title' => '高度利用地区', 'geom' => 'polygon',
            'keywords' => '高度利用地区',
            'description' => '高度利用地区（都市計画決定GISデータ）',
        ],
        'XKT030' => [
            'title' => '都市計画道路', 'geom' => 'other',
            'keywords' => '都市計画道路|計画道路',
            'description' => '都市計画道路（都市計画決定GISデータ）',
        ],
        // --- 災害・ハザード ----------------------------------------------
        'XKT016' => [
            'title' => '災害危険区域', 'geom' => 'polygon',
            'keywords' => '災害危険区域',
            'description' => '災害危険区域（建築制限のある区域）',
        ],
        'XKT020' => [
            'title' => '大規模盛土造成地', 'geom' => 'polygon',
            'keywords' => '盛土|大規模盛土|造成地',
            'description' => '大規模盛土造成地マップ（地盤リスク）',
        ],
        'XKT021' => [
            'title' => '地すべり防止地区', 'geom' => 'polygon',
            'keywords' => '地すべり|地滑り',
            'description' => '地すべり防止地区',
        ],
        'XKT022' => [
            'title' => '急傾斜地崩壊危険区域', 'geom' => 'polygon',
            'keywords' => '急傾斜|がけ崩れ|崖崩れ|崖',
            'description' => '急傾斜地崩壊危険区域',
        ],
        'XKT025' => [
            'title' => '液状化傾向（地形区分）', 'geom' => 'polygon',
            'keywords' => '液状化|液状化傾向',
            'description' => '地形区分に基づく液状化の発生傾向図',
        ],
        'XKT026' => [
            'title' => '洪水浸水想定区域（想定最大規模）', 'geom' => 'polygon', 'zmin' => 14, 'zmax' => 15,
            'keywords' => '洪水|浸水|水害|浸水想定',
            'description' => '洪水浸水想定区域（想定最大規模・河川別の浸水深ランク）',
            'fields' => ['A31a_202' => '河川名', 'A31a_205' => '浸水深ランク', 'A31a_204' => '河川管理者'],
            'note' => '浸水深ランクは数値が大きいほど想定浸水深が深いことを示します（具体的なメートル数はハザードマップで要確認）。',
        ],
        'XKT027' => [
            'title' => '高潮浸水想定区域', 'geom' => 'polygon', 'zmin' => 14, 'zmax' => 15,
            'keywords' => '高潮',
            'description' => '高潮浸水想定区域',
        ],
        'XKT028' => [
            'title' => '津波浸水想定', 'geom' => 'polygon', 'zmin' => 14, 'zmax' => 15,
            'keywords' => '津波',
            'description' => '津波浸水想定（浸水域・浸水深）',
        ],
        'XKT029' => [
            'title' => '土砂災害警戒区域', 'geom' => 'polygon',
            'keywords' => '土砂災害|土砂|警戒区域|レッドゾーン|イエローゾーン',
            'description' => '土砂災害警戒区域・特別警戒区域',
        ],
        'XST001' => [
            'title' => '災害履歴', 'geom' => 'point', 'zmin' => 9, 'zmax' => 15,
            'keywords' => '災害履歴|過去の災害|被災履歴|災害記録',
            'description' => '国土調査による災害履歴（過去の災害の種類・発生年月日）',
            'fields' => ['disaster_name_ja' => '災害分類', 'disaster_date' => '発生年月日', 'disaster_source' => '資料'],
        ],
        // --- 周辺施設・生活利便 ------------------------------------------
        'XKT004' => [
            'title' => '小学校区', 'geom' => 'polygon',
            'keywords' => '小学校区|学区',
            'description' => '小学校の通学区域（学区）',
        ],
        'XKT005' => [
            'title' => '中学校区', 'geom' => 'polygon',
            'keywords' => '中学校区',
            'description' => '中学校の通学区域（学区）',
        ],
        'XKT006' => [
            'title' => '学校', 'geom' => 'point',
            'keywords' => '学校|小学校|中学校|高校|高等学校',
            'description' => '周辺の学校（小・中・高等学校等）の位置',
        ],
        'XKT007' => [
            'title' => '保育園・幼稚園等', 'geom' => 'point',
            'keywords' => '保育園|幼稚園|保育所|認定こども園|こども園',
            'description' => '周辺の保育園・幼稚園・認定こども園',
        ],
        'XKT010' => [
            'title' => '医療機関', 'geom' => 'point', 'zmin' => 13, 'zmax' => 15,
            'keywords' => '病院|医療|クリニック|診療所|医療機関',
            'description' => '周辺の医療機関（病院・診療所等）',
        ],
        'XKT011' => [
            'title' => '福祉施設', 'geom' => 'point', 'zmin' => 13, 'zmax' => 15,
            'keywords' => '福祉施設|介護|福祉|老人ホーム|高齢者施設',
            'description' => '周辺の福祉施設（介護・高齢者・障害者・児童福祉等）',
            'fields' => [
                'P14_008_ja' => '施設名', 'P14_005_name_ja' => '大分類',
                'P14_006_name_ja' => '中分類', 'P14_002' => '市区町村',
            ],
        ],
        'XKT017' => [
            'title' => '図書館', 'geom' => 'point',
            'keywords' => '図書館',
            'description' => '周辺の図書館',
        ],
        'XKT018' => [
            'title' => '市区町村役場・集会施設', 'geom' => 'point',
            'keywords' => '役所|役場|市役所|区役所|町役場|村役場|公民館|集会施設',
            'description' => '周辺の市区町村役場・支所・公民館等の集会施設',
        ],
        'XKT019' => [
            'title' => '自然公園地域', 'geom' => 'polygon',
            'keywords' => '自然公園|国立公園|国定公園|都道府県立自然公園',
            'description' => '自然公園地域（国立・国定・都道府県立自然公園）',
        ],
        'XGT001' => [
            'title' => '指定緊急避難場所', 'geom' => 'point',
            'keywords' => '避難場所|避難所|指定緊急避難場所|防災拠点',
            'description' => '周辺の指定緊急避難場所（災害種別ごとの対応）',
        ],
        // --- 人口 ---------------------------------------------------------
        'XKT013' => [
            'title' => '将来推計人口250mメッシュ', 'geom' => 'polygon',
            'keywords' => '将来人口|推計人口|人口予測|人口推計|将来推計人口',
            'description' => '将来推計人口（250mメッシュ・年齢階級別）',
        ],
        'XKT031' => [
            'title' => '人口集中地区（DID）', 'geom' => 'polygon',
            'keywords' => '人口集中地区|DID',
            'description' => '人口集中地区（DID）の範囲',
        ],
    ];
}

/**
 * Generic fetcher for a reinfolib GIS tile API described by a catalog entry:
 * geocode the area → derive the XYZ tile (clamped to the API's zoom range) →
 * fetch GeoJSON (centre tile for zones, centre+ring for point facilities) →
 * spatially filter to the query point → format. Null-graceful throughout.
 */
function chatReinfoTileContext($db, $code, $def, $message, $area) {
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') return null;
    $geo = chatReinfoResolveLatLon($db, $area, $message);
    if (!$geo) return null;

    $zmin = (int)($def['zmin'] ?? 11);
    $zmax = (int)($def['zmax'] ?? 15);
    $z = max($zmin, min($zmax, 14));
    $center = chatGeoLatLonToTile($geo['lat'], $geo['lon'], $z);
    $geom = $def['geom'] ?? 'polygon';
    // Point facilities: scan a 3x3 ring to catch nearby items across tile edges.
    // Zones/lines: the centre tile already contains the point's polygon.
    $offsets = $geom === 'point'
        ? [[0,0],[-1,0],[1,0],[0,-1],[0,1],[-1,-1],[-1,1],[1,-1],[1,1]]
        : [[0,0]];
    $year = (int)date('Y');
    $extra = [];
    if (isset($def['params'])) {
        $extra = is_callable($def['params']) ? ($def['params'])($year) : (array)$def['params'];
    }

    $features = [];
    $fetchedAt = null;
    $cached = true;
    $anyOk = false;
    foreach ($offsets as $off) {
        $query = array_merge([
            'response_format' => 'geojson',
            'z' => $z,
            'x' => $center['x'] + $off[0],
            'y' => $center['y'] + $off[1],
        ], $extra);
        $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/' . $code . '?' . http_build_query($query);
        chatLandDebugLog('api_request', ['provider' => $code, 'z' => $query['z'], 'x' => $query['x'], 'y' => $query['y'], 'url' => chatPublicDataRedactUrl($url)]);
        $result = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], 2592000);
        $fetchedAt = $result['fetched_at'] ?? $fetchedAt;
        if (empty($result['cached'])) $cached = false;
        chatLandDebugLog('api_response', ['provider' => $code, 'x' => $query['x'], 'y' => $query['y'], 'ok' => !empty($result['ok']), 'status' => $result['status'] ?? null, 'cached' => !empty($result['cached']), 'features' => is_array($result['data']) ? count($result['data']['features'] ?? []) : 0, 'error' => $result['error'] ?? '']);
        if (!$result['ok'] || !is_array($result['data'])) {
            // Genuine fetch FAILURE (transport/HTTP error) — distinct from a
            // successful-but-empty response (= 該当なし/区域外). Log so it is visible
            // in the error log; an empty 200 below is intentionally NOT logged.
            error_log(sprintf('[reinfolib] %s tile z%d x%d y%d fetch error: status=%s error=%s',
                $code, $z, $query['x'], $query['y'], $result['status'] ?? 'n/a', $result['error'] ?? ''));
            continue;
        }
        $anyOk = true;
        $f = $result['data']['features'] ?? [];
        if (is_array($f) && !empty($f)) {
            $features = array_merge($features, $f);
            if ($geom !== 'point') break;      // zones: centre tile is enough
            if (count($features) >= 60) break;  // bound the work for dense layers
        }
    }

    $base = $geo['title'] ?? trim(($area['prefecture_name'] ?? '') . ($area['city_name'] ?? ''));

    // Polygon zone / hazard layers: classify the OUTCOME so the chat can give a
    // definitive answer instead of an ambiguous "確認できません". A successful-but-
    // empty / out-of-zone response is NOT a failure — it means the point is not in
    // such a designated area, which is a useful, accurate answer.
    if ($geom === 'polygon') {
        if (!$anyOk) {
            return chatReinfoZoneStatusItem($code, $def, $base, 'error', $fetchedAt, $cached);
        }
        if (empty($features)) {
            return chatReinfoZoneStatusItem($code, $def, $base, 'not_designated', $fetchedAt, $cached);
        }
        // Only features whose polygon actually contains the point count as a hit —
        // no "nearest feature" fallback, which would wrongly report a neighbouring
        // zone as if it applied to this exact location.
        $matched = [];
        foreach ($features as $f) {
            if (chatGeoPointInFeature($geo['lon'], $geo['lat'], $f['geometry'] ?? null)) {
                $p = (isset($f['properties']) && is_array($f['properties'])) ? $f['properties'] : null;
                if ($p !== null) $matched[] = $p;
            }
        }
        if (empty($matched)) {
            return chatReinfoZoneStatusItem($code, $def, $base, 'out_of_area', $fetchedAt, $cached);
        }
        $rows = chatReinfoFormatRows($matched, $def, 8);
        $note = ($def['note'] ?? 'これは指定地点を含む区域のGISデータです。')
            . ' 取得した区域は基準点（' . $base . '）を含む区域です。';
        return [
            'provider' => 'reinfolib',
            'status' => 'data',
            'title' => $def['title'] . '（' . $code . '）',
            'notice' => $base . 'の' . $def['title'] . 'を不動産情報ライブラリで確認します。',
            'data' => $rows,
            'record_count' => count($rows),
            'total_count' => count($matched),
            'scope_note' => trim($base . 'の' . $def['title']),
            'count_note' => $note,
            'fetched_at' => $fetchedAt ?: date('Y-m-d H:i:s'),
            'cached' => $cached,
        ];
    }

    // Point / line layers: nearest-feature behaviour. Empty stays null (do not
    // claim "周辺に無い" — the search radius is only a few tiles).
    if (empty($features)) return null;
    $filtered = chatGeoFilterFeatures($features, $geo['lon'], $geo['lat'], $geom, 8);
    $rows = chatReinfoFormatRows($filtered['rows'], $def, 8);
    if (empty($rows)) return null;

    $scope = trim($base . '周辺の' . $def['title']);
    $note = ($def['note'] ?? 'これは指定地点周辺のGISデータです。')
        . ' 基準点（' . $base . '）から近い順に表示しています。';
    return [
        'provider' => 'reinfolib',
        'status' => 'data',
        'title' => $def['title'] . '（' . $code . '）',
        'notice' => $base . '周辺の' . $def['title'] . 'を不動産情報ライブラリで確認します。',
        'data' => $rows,
        'record_count' => count($rows),
        'total_count' => (int)$filtered['total'],
        'scope_note' => $scope,
        'count_note' => $note,
        'fetched_at' => $fetchedAt ?: date('Y-m-d H:i:s'),
        'cached' => $cached,
    ];
}

/** Whitelist + label the GeoJSON properties of up to $limit features for the prompt. */
function chatReinfoFormatRows($propsList, $def, $limit = 8) {
    $rows = [];
    foreach (array_slice((array)$propsList, 0, $limit) as $props) {
        if (!is_array($props)) continue;
        if (!empty($def['fields'])) {
            $row = [];
            foreach ($def['fields'] as $key => $label) {
                if (isset($props[$key]) && $props[$key] !== '') $row[$label] = $props[$key];
            }
            $rows[] = !empty($row) ? $row : $props;
        } else {
            $rows[] = $props;
        }
    }
    return $rows;
}

/**
 * Build a non-data outcome item for a polygon zone/hazard layer so the chat can
 * answer definitively. $status is one of:
 *   'not_designated' その区域の指定が無い（HTTP200・該当ポリゴンなし）   → 「該当なし」
 *   'out_of_area'    近隣に区域はあるが地点は区域外（HTTP200）            → 「区域外」
 *   'error'          取得失敗（API/通信エラー、該当の有無は不明）         → 「取得失敗」
 * These carry record_count 0 plus an explicit instruction so the model phrases
 * 該当なし・区域外 (a real answer) differently from 取得失敗 (retry).
 */
function chatReinfoZoneStatusItem($code, $def, $base, $status, $fetchedAt, $cached) {
    $title = $def['title'];
    if ($status === 'error') {
        $notice = $base . 'の' . $title . 'は現在取得できませんでした。';
        $note = '取得失敗（APIエラー・通信エラー）。この地点が' . $title . 'に該当するかは不明です。'
            . 'ユーザーには「現在この情報を取得できませんでした。時間をおいて再度お試しください」と伝え、'
            . '「該当しない」「区域外」とは断定しないでください。';
        $label = '取得失敗';
    } elseif ($status === 'out_of_area') {
        $notice = $base . 'はこの' . $title . 'の区域外です。';
        $note = 'APIは正常に取得完了（HTTP200）。近隣に' . $title . 'は存在しますが、この地点はその区域外です。'
            . 'ユーザーには「この地点は' . $title . 'の区域外です（指定区域に含まれません）」と明確に伝えてください。'
            . '「確認できません」「取得できませんでした」とは言わないでください。';
        $label = '区域外';
    } else { // not_designated
        $notice = $base . 'は' . $title . 'に指定されていません。';
        $note = 'APIは正常に取得完了（HTTP200）。この地点・周辺に' . $title . 'の指定はありません（該当なし）。'
            . 'ユーザーには「' . $title . 'には指定されていません（該当なし）」と明確に伝えてください。'
            . '「確認できません」「取得できませんでした」とは言わないでください。';
        $label = '該当なし';
    }
    return [
        'provider' => 'reinfolib',
        'status' => $status,
        'status_label' => $label,
        'title' => $title . '（' . $code . '）',
        'notice' => $notice,
        'data' => [],
        'record_count' => 0,
        'total_count' => 0,
        'count_note' => $note,
        'fetched_at' => $fetchedAt ?: date('Y-m-d H:i:s'),
        'cached' => $cached,
    ];
}

/**
 * Diagnostic counterpart of chatReinfoTileContext(): runs the SAME geocode →
 * tile → fetch → spatial-filter pipeline but NEVER collapses to null. Returns a
 * full per-API breakdown so a "確認できません" outcome can be classified, telling
 * apart genuinely-no-data cases from transport failures:
 *   - 'no_api_key'      APIキー未設定
 *   - 'geocode_failed'  住所→緯度経度の変換に失敗
 *   - 'http_error'      取得失敗（APIがHTTPエラー／通信エラー）          ← エラー
 *   - 'not_designated'  該当なし（タイル内にその区域ポリゴンが存在しない）  ← 該当なし
 *   - 'out_of_area'     区域外（近隣に区域はあるが地点はポリゴン外）        ← 区域外
 *   - 'data'            データあり（地点が区域内／近接データ取得）
 * Used by the address data-diagnostic endpoint; not part of the live chat path.
 */
function chatReinfoTileDiagnostic($db, $code, $def, $geo) {
    $geom = $def['geom'] ?? 'polygon';
    $out = [
        'code' => $code,
        'title' => $def['title'] ?? $code,
        'geom' => $geom,
        'requested_z' => null,
        'tiles' => [],
        'http_status' => null,
        'error' => '',
        'cached' => null,
        'fetched_at' => null,
        'tile_feature_count' => 0,
        'point_in_zone' => false,
        'matched_count' => 0,
        'status' => 'error',
        'status_label' => '',
        'raw_sample' => '',
        'rows' => [],
    ];
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') {
        $out['status'] = 'no_api_key';
        $out['status_label'] = 'APIキー未設定';
        $out['error'] = 'REINFOLIB_API_KEY is not configured';
        return $out;
    }
    if (!$geo) {
        $out['status'] = 'geocode_failed';
        $out['status_label'] = 'ジオコーディング失敗';
        return $out;
    }
    $zmin = (int)($def['zmin'] ?? 11);
    $zmax = (int)($def['zmax'] ?? 15);
    $z = max($zmin, min($zmax, 14));
    $out['requested_z'] = $z;
    $center = chatGeoLatLonToTile($geo['lat'], $geo['lon'], $z);
    $offsets = $geom === 'point'
        ? [[0,0],[-1,0],[1,0],[0,-1],[0,1],[-1,-1],[-1,1],[1,-1],[1,1]]
        : [[0,0]];
    $year = (int)date('Y');
    $extra = [];
    if (isset($def['params'])) {
        $extra = is_callable($def['params']) ? ($def['params'])($year) : (array)$def['params'];
    }
    $features = [];
    $anyOk = false;
    $lastStatus = null;
    $errMsg = '';
    foreach ($offsets as $off) {
        $tx = $center['x'] + $off[0];
        $ty = $center['y'] + $off[1];
        $query = array_merge(['response_format' => 'geojson', 'z' => $z, 'x' => $tx, 'y' => $ty], $extra);
        $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/' . $code . '?' . http_build_query($query);
        $result = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], 2592000);
        $out['tiles'][] = ['x' => $tx, 'y' => $ty];
        $lastStatus = $result['status'] ?? $lastStatus;
        $out['cached'] = !empty($result['cached']);
        $out['fetched_at'] = $result['fetched_at'] ?? $out['fetched_at'];
        if (!empty($result['error'])) $errMsg = (string)$result['error'];
        if (!$result['ok'] || !is_array($result['data'])) continue;
        $anyOk = true;
        $f = $result['data']['features'] ?? [];
        if (is_array($f) && !empty($f)) {
            if ($out['raw_sample'] === '') {
                $out['raw_sample'] = mb_substr((string)json_encode($f[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 600);
            }
            $features = array_merge($features, $f);
            if ($geom !== 'point') break;
            if (count($features) >= 60) break;
        } elseif ($out['raw_sample'] === '') {
            // HTTP 200 but empty payload — record the shape (= 該当なし evidence, ⑦).
            $out['raw_sample'] = mb_substr((string)json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 600);
        }
    }
    $out['http_status'] = $lastStatus;
    $out['error'] = $errMsg;
    $out['tile_feature_count'] = count($features);

    if (!$anyOk) {
        $out['status'] = 'http_error';
        $out['status_label'] = '取得失敗（APIエラー）';
        return $out;
    }
    if (empty($features)) {
        $out['status'] = 'not_designated';
        $out['status_label'] = '該当なし（区域の指定なし）';
        return $out;
    }
    if ($geom === 'polygon') {
        $inZone = false;
        foreach ($features as $f) {
            if (chatGeoPointInFeature($geo['lon'], $geo['lat'], $f['geometry'] ?? null)) { $inZone = true; break; }
        }
        $out['point_in_zone'] = $inZone;
        if (!$inZone) {
            $out['status'] = 'out_of_area';
            $out['status_label'] = '区域外（近隣に区域はあるが地点は対象外）';
            return $out;
        }
    }
    $filtered = chatGeoFilterFeatures($features, $geo['lon'], $geo['lat'], $geom, 8);
    $rows = [];
    foreach ($filtered['rows'] as $props) {
        if (!empty($def['fields'])) {
            $row = [];
            foreach ($def['fields'] as $key => $label) {
                if (isset($props[$key]) && $props[$key] !== '') $row[$label] = $props[$key];
            }
            $rows[] = !empty($row) ? $row : $props;
        } else {
            $rows[] = $props;
        }
    }
    $out['matched_count'] = count($rows);
    $out['rows'] = array_slice($rows, 0, 3);
    $out['status'] = 'data';
    $out['status_label'] = 'データあり（区域内）';
    return $out;
}

/**
 * Address data diagnostic: geocode the address once, then run a fixed set of
 * reinfolib GIS layers and report, per layer, whether the point has data / is
 * outside the zone / the layer is not designated there / the API errored. Built
 * to root-cause "確認できません" answers and to make 区域外・該当なし clearly
 * distinguishable from 取得失敗・エラー. $codes defaults to 用途地域・防火・洪水・
 * 土砂 plus neighbouring hazard layers.
 */
function chatAddressDataDiagnostic($db, $address, $codes = null) {
    $address = trim((string)$address);
    $result = [
        'address' => $address,
        'geocode' => null,
        'layers' => [],
        'generated_at' => date('Y-m-d H:i:s'),
    ];
    if ($address === '') return $result;
    $geo = chatAddressGeocode($db, $address);
    $result['geocode'] = $geo
        ? ['ok' => true, 'lat' => $geo['lat'], 'lon' => $geo['lon'], 'matched_title' => $geo['title'] ?? null]
        : ['ok' => false, 'lat' => null, 'lon' => null, 'matched_title' => null,
           'note' => 'GSI住所検索で座標を特定できませんでした（実在しない住所、または番地まで一致しなかった可能性）。'];

    $catalog = chatReinfoApiCatalog();
    if (!is_array($codes) || empty($codes)) {
        $codes = ['XKT002', 'XKT014', 'XKT026', 'XKT029', 'XKT028', 'XKT027', 'XKT025', 'XKT022', 'XKT021'];
    }
    foreach ($codes as $code) {
        $code = strtoupper(trim((string)$code));
        if (!isset($catalog[$code])) continue;
        $result['layers'][] = chatReinfoTileDiagnostic($db, $code, $catalog[$code], $geo);
    }
    return $result;
}

/**
 * XCT001 鑑定評価書情報 (query API: year + 都道府県コード + 用途区分). Tries the
 * current year then the previous year; picks a 用途区分 from the question.
 */
function chatReinfoAppraisalContext($db, $message, $area) {
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') return null;
    $pref = $area['prefecture_code'] ?? null;
    if (!$pref) return null;
    $division = '00'; // 住宅地
    if (preg_match('/(商業|店舗|オフィス|繁華街)/u', (string)$message)) $division = '05';
    elseif (preg_match('/(工業|工場|準工業)/u', (string)$message)) $division = '09';
    foreach ([(int)date('Y'), (int)date('Y') - 1] as $year) {
        $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/XCT001?' . http_build_query([
            'year' => $year, 'area' => $pref, 'division' => $division,
        ]);
        $result = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], 604800);
        if (!$result['ok'] || !is_array($result['data'])) continue;
        $allRows = chatPublicDataRows($result['data']);
        if (empty($allRows)) continue;
        $total = count($allRows);
        $rows = array_slice($allRows, 0, 5);
        $scope = $year . '年・' . ($area['prefecture_name'] ?? '') . 'の鑑定評価書情報（用途区分 ' . $division . '）';
        return [
            'provider' => 'reinfolib',
            'title' => '鑑定評価書情報（XCT001）',
            'notice' => ($area['prefecture_name'] ?? '') . 'の鑑定評価書情報を不動産情報ライブラリで確認します。',
            'data' => $rows,
            'record_count' => count($rows),
            'total_count' => $total,
            'scope_note' => $scope,
            'count_note' => 'これは都道府県単位の標準地（鑑定評価書）一覧です。先頭 ' . count($rows) . ' 件のみ添付、該当総件数は ' . $total . ' 件です。特定地点の評価ではない点に注意してください。',
            'fetched_at' => $result['fetched_at'] ?? date('Y-m-d H:i:s'),
            'cached' => !empty($result['cached']),
        ];
    }
    return null;
}

/** Dispatch a catalog entry to the right fetcher based on its type. */
function chatReinfoCatalogContext($db, $code, $def, $message, $area) {
    $type = $def['type'] ?? 'tile';
    if ($type === 'query') {
        if ($code === 'XCT001') return chatReinfoAppraisalContext($db, $message, $area);
        return null;
    }
    return chatReinfoTileContext($db, $code, $def, $message, $area);
}

/**
 * Declarative registry of the server-side data providers the chat can call.
 * The five hand-written providers below are merged with every entry in
 * chatReinfoApiCatalog(), so adding a reinfolib API = one catalog entry only.
 */
function chatPublicDataProviderRegistry() {
    $registry = [
        'mansion_db' => [
            'label' => '当社 全国マンションデータベース',
            'keywords' => 'マンション|物件|建物|基礎情報|基本情報|建物情報|物件情報|マンション情報|築年月|築年数|竣工|構造|総戸数|戸数|階建|最寄り駅|最寄駅|物件名',
            'description' => '特定のマンション・物件・建物の基礎情報（住所/築年月/構造/総戸数/階数/最寄り駅）',
        ],
        'reinfo_price' => [
            'label' => '国土交通省 不動産情報ライブラリ',
            'keywords' => '相場|取引価格|成約|地価|公示|価格',
            'description' => 'エリア（市区町村単位）の不動産取引価格・成約事例の集計データ',
        ],
        'reinfo_station' => [
            'label' => '国土交通省 不動産情報ライブラリ',
            'keywords' => '乗降客数|乗降客|乗降人員|乗車人員|利用者数|乗客数|乗降|混雑',
            'description' => '駅の乗降客数（年別の利用者数・路線/事業者別）',
        ],
        'mlit_dpf' => [
            'label' => '国土交通データプラットフォーム',
            'keywords' => '周辺環境|地域データ|エリア説明',
            'description' => '不動産情報ライブラリに無い国交省データセットの横断検索（カタログ）',
        ],
        'estat' => [
            'label' => '政府統計の総合窓口 e-Stat',
            'keywords' => '人口|世帯|高齢|子育て|子供|子ども|ファミリー|年収|昼夜|外国人|持ち家|政府統計|国勢調査',
            'description' => '人口・世帯・年齢構成などの政府統計（国勢調査等）',
        ],
    ];
    foreach (chatReinfoApiCatalog() as $code => $def) {
        $registry[$code] = [
            'label' => '国土交通省 不動産情報ライブラリ',
            'keywords' => $def['keywords'] ?? '',
            'description' => $def['description'] ?? $def['title'] ?? $code,
        ];
    }
    return $registry;
}

function chatPublicDataInvokeProvider($db, $key, $message, $area) {
    switch ($key) {
        case 'mansion_db':     return chatMansionDbContext($db, $message, true);
        case 'reinfo_price':   return chatReinfoContext($db, $message, $area, true);
        case 'reinfo_station': return chatReinfoStationContext($db, $message, $area);
        case 'mlit_dpf':       return chatMlitDpfContext($db, $message, $area, true);
        case 'estat':          return chatEstatContext($db, $message, $area, true);
    }
    $catalog = chatReinfoApiCatalog();
    if (isset($catalog[$key])) return chatReinfoCatalogContext($db, $key, $catalog[$key], $message, $area);
    return null;
}

/**
 * LLM fallback router. Only called when keyword routing matched nothing but the
 * message still passed the global data gate. Uses the light/cheap model with a
 * short timeout; on any failure it returns no providers (graceful: chat falls
 * back to a normal answer). Returns ['providers'=>[...], 'station'=>string].
 */
function chatPublicDataLlmRouter($message, $registry) {
    if (!function_exists('callOpenAIChat')) return ['providers' => [], 'station' => ''];
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') return ['providers' => [], 'station' => ''];
    $apiKey = defined('OPENAI_API_KEY_LIGHT') && OPENAI_API_KEY_LIGHT !== '' ? OPENAI_API_KEY_LIGHT : OPENAI_API_KEY;
    $model = defined('OPENAI_MODEL_LIGHT') && OPENAI_MODEL_LIGHT !== '' ? OPENAI_MODEL_LIGHT : (defined('OPENAI_CHAT_MODEL') ? OPENAI_CHAT_MODEL : 'gpt-4o-mini');

    $lines = [];
    foreach ($registry as $key => $p) {
        $lines[] = '- ' . $key . ': ' . ($p['description'] ?? '');
    }
    $system = "あなたは不動産チャットの質問を、回答に必要なサーバー側データソースへ振り分けるルーターです。\n"
        . "次のデータソースから、ユーザーの質問に答えるのに本当に必要なものだけを選んでください。該当が無ければ空配列にします。\n"
        . implode("\n", $lines) . "\n"
        . "出力は次のJSONのみ（前後に文章やコードフェンスを付けない）:\n"
        . '{"providers":["キー", ...],"station":"駅名（乗降客数など駅の質問のときだけ。無ければ空文字）"}';
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => chatOpenAITrimPromptText($message, 500)],
    ];
    $resp = callOpenAIChat($messages, $apiKey, $model, [
        'purpose' => 'router', 'max_tokens' => 150, 'temperature' => 0, 'timeout' => 8,
    ]);
    if (empty($resp['reply'])) return ['providers' => [], 'station' => ''];
    $raw = trim($resp['reply']);
    $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
    if (preg_match('/\{.*\}/s', $raw, $m)) $raw = $m[0];
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) return ['providers' => [], 'station' => ''];
    $valid = array_keys($registry);
    $providers = [];
    foreach ((array)($parsed['providers'] ?? []) as $p) {
        if (in_array($p, $valid, true) && !in_array($p, $providers, true)) $providers[] = $p;
    }
    return ['providers' => $providers, 'station' => trim((string)($parsed['station'] ?? ''))];
}

/**
 * Hybrid router (keyword pre-filter → LLM fallback). Returns the provider keys
 * to invoke plus a possibly-enriched $area (LLM may supply a station name).
 */
function chatPublicDataRoute($db, $message, $area) {
    $registry = chatPublicDataProviderRegistry();
    $matched = [];
    foreach ($registry as $key => $p) {
        if (!empty($p['keywords']) && preg_match('/(' . $p['keywords'] . ')/u', (string)$message)) {
            $matched[] = $key;
        }
    }
    if (!empty($matched)) {
        return ['providers' => $matched, 'area' => $area, 'router' => 'keyword'];
    }
    // A message containing a street-level address but no explicit topic → run the
    // standard address report (用途地域・防火・洪水・土砂・液状化) for that exact
    // point, instead of falling through to the LLM router / マンション名検索 which
    // would answer "該当物件が見つかりませんでした". (Order = display priority; within
    // the 5-provider fan-out cap.)
    if (chatMessageContainsAddress($message)) {
        return ['providers' => ['XKT002', 'XKT014', 'XKT026', 'XKT029', 'XKT025'], 'area' => $area, 'router' => 'address'];
    }
    // Ambiguous: the global gate passed but no provider keyword matched. Ask the
    // cheap LLM router to classify (and extract a station name if relevant).
    $llm = chatPublicDataLlmRouter($message, $registry);
    if (!empty($llm['station']) && empty($area['station_name'])) {
        $st = $llm['station'];
        $area['station_name'] = mb_substr($st, -1) === '駅' ? $st : $st . '駅';
    }
    return ['providers' => $llm['providers'], 'area' => $area, 'router' => 'llm'];
}

function chatBuildPublicDataContext($db, $message, $geo = null) {
    // GPS path: the message came with the customer's current-location coordinates
    // (緯度経度). We skip address extraction/geocoding entirely — the lat/lon fixes
    // the tile lookups directly — and run the standard 土地情報 report (用途地域・
    // 防火・洪水・土砂・液状化) for that exact point. A reverse geocode supplies a
    // human-readable place name purely for display.
    $geoArea = null;
    if (is_array($geo) && isset($geo['lat'], $geo['lon']) && is_numeric($geo['lat']) && is_numeric($geo['lon'])) {
        // Google Reverse Geocoding で番地・号レベルの正確な住所を優先取得。キー未設定や
        // APIエラー時は従来のGSI逆ジオコーダ（町丁目レベル）へ自動フォールバックする。
        $rev = chatGoogleReverseGeocode($db, $geo['lat'], $geo['lon']);
        if ($rev === null) $rev = chatReverseGeocode($db, $geo['lat'], $geo['lon']);
        $geoArea = [
            'lat' => (float)$geo['lat'],
            'lon' => (float)$geo['lon'],
            'title' => ($rev['title'] ?? null) ?: sprintf('現在地（緯度%.5f／経度%.5f）', (float)$geo['lat'], (float)$geo['lon']),
            'prefecture_name' => $rev['prefecture'] ?? null,
        ];
    }

    if ($geoArea === null && !chatPublicDataShouldRun($message)) return ['context' => '', 'sources' => [], 'notices' => [], 'meta' => [], 'attempted' => false];

    if ($geoArea !== null) {
        $area = $geoArea;
        // 現在地レポート用の拡張セット。都市計画（区域区分・用途地域・防火・地区計画）と
        // ハザード（洪水・高潮・津波・土砂・液状化・急傾斜）を点照会する。reinfolib に存在
        // しない層（高度地区・日影規制・景観・埋蔵文化財・宅地造成・内水・揺れやすさ・火災
        // 危険度）や点照会非対応の都市計画道路(XKT030)は含めない。該当なし/区域外もLLMへ
        // 渡し、ハザードは「該当なし」と明示できるようにする。
        $providers = ['XKT001', 'XKT002', 'XKT014', 'XKT023', 'XKT026', 'XKT027', 'XKT028', 'XKT029', 'XKT025', 'XKT022'];
    } else {
        $area = chatPublicExtractArea($message);
        $route = chatPublicDataRoute($db, $message, $area);
        $area = $route['area'];
        // Bound per-message fan-out: a broad question (e.g. "災害") can match many
        // catalog APIs, each of which fetches one or more tiles. Cap to keep latency
        // predictable; keyword/registry order keeps the highest-value APIs first.
        $providers = array_slice($route['providers'], 0, 5);
    }
    $items = [];
    foreach ($providers as $providerKey) {
        $item = chatPublicDataInvokeProvider($db, $providerKey, $message, $area);
        if ($item) $items[] = $item;
    }
    if (empty($items)) {
        $context = "【公的・独自データ参照情報】
この質問は公的データ・独自データによる補強対象として判定されましたが、今回のサーバー側取得では回答に使える有効なデータを取得できませんでした。APIキーや内部情報は回答に出さないでください。取得できた事実がないため、出典つきの断定は避け、必要に応じて『公的データの取得結果を確認できませんでした』と自然に伝えてください。";
        return ['context' => $context, 'sources' => [], 'notices' => ['公的データの取得結果を確認できませんでした。'], 'meta' => [], 'attempted' => true];
    }
    $parts = ["【公的・独自データ参照情報】\n以下はサーバー側で実際に取得した補強データです。APIキーや内部情報は回答に出さないでください。該当データが質問と関係する場合だけ、一般ユーザー向けにやさしく要約してください。取得件数・取得日時の数値は、ここに記載された値をそのまま使ってください（推測で件数を作らないでください）。"];
    $sources = [];
    $notices = [];
    $meta = [];
    foreach ($items as $item) {
        $label = chatPublicDataSourceLabel($item['provider']);
        $sources[] = $label;
        if (!empty($item['notice'])) $notices[] = $item['notice'];
        $status = $item['status'] ?? 'data';

        $meta[] = [
            'provider' => $item['provider'],
            'label' => $label,
            'title' => $item['title'] ?? null,
            'status' => $status,
            'status_label' => $item['status_label'] ?? null,
            'record_count' => isset($item['record_count']) ? (int)$item['record_count'] : null,
            'total_count' => array_key_exists('total_count', $item) && $item['total_count'] !== null ? (int)$item['total_count'] : null,
            'fetched_at' => $item['fetched_at'] ?? null,
            'cached' => !empty($item['cached']),
        ];

        // Non-data outcomes (区域外 / 該当なし / 取得失敗): present the judgement and
        // its instruction, with no data block — the count_note tells the model how to
        // phrase it (a real "該当なし" answer vs. a retry-able "取得失敗").
        if ($status !== 'data') {
            $extra = '';
            if (!empty($item['fetched_at'])) $extra = "\n取得日時: " . $item['fetched_at'];
            if (!empty($item['count_note'])) $extra .= "\n" . $item['count_note'];
            $parts[] = "\n【{$item['title']}】\n出典: {$label}\n判定: " . ($item['status_label'] ?? '該当なし') . $extra;
            continue;
        }

        $extra = '';
        if ($item['provider'] === 'estat') $extra .= "\n回答でこのデータを参照する場合は、該当箇所に『政府統計によると、』という前置きを入れてください。";
        $metaLine = '取得件数: ' . (isset($item['record_count']) ? (int)$item['record_count'] . '件' : '不明');
        if (array_key_exists('total_count', $item) && $item['total_count'] !== null) $metaLine .= ' / 該当総件数: ' . (int)$item['total_count'] . '件';
        if (!empty($item['fetched_at'])) $metaLine .= ' / 取得日時: ' . $item['fetched_at'];
        $extra .= "\n" . $metaLine;
        if (!empty($item['count_note'])) $extra .= "\n" . $item['count_note'];
        if (!empty($item['caveat'])) $extra .= "\n注意: " . $item['caveat'];
        $parts[] = "\n【{$item['title']}】\n出典: {$label}{$extra}\n" . chatPublicDataTrimForPrompt($item['data']);
    }
    $sources = array_values(array_unique($sources));
    $parts[] = "\n回答末尾の出典表記は、本文で実際にこの取得データを使った場合だけ付けてください。取得データを使わず一般知識のみで答えた場合は出典を付けないでください。";
    if ($geoArea !== null) {
        $locName = $geoArea['title'];
        $latStr = number_format((float)$geoArea['lat'], 5);
        $lonStr = number_format((float)$geoArea['lon'], 5);
        $parts[] = "\n【現在地レポートの回答ルール（厳守・最優先）】\nこれはお客様の現在地（GPS位置情報・緯度{$latStr}／経度{$lonStr}）からサーバーがAPIで取得した土地情報の照会です。次のルールを厳守し、他の口調・テンプレートより最優先してください。出力は下記の見出し構成のプレーンテキストとし、各項目は「・項目：値」の箇条書きで一覧しやすく整えてください。\n\n1. 挨拶・お礼・前置き（「ありがとうございます」「情報を提供いただき」等）は一切書かない。データはお客様提供ではなくAPI取得です。\n2. まず見出し【現在地】を付け、次の行に「{$locName}付近（測定地点：緯度{$latStr}／経度{$lonStr}）」と記載し、その次の行に必ず「※GPSの測位誤差により、実際の位置と多少異なる場合があります。」と添える。この住所・緯度経度はサーバーが取得した値をそのまま使い、番地・号などを創作・補完しない。用途地域・建ぺい率・容積率は、この測定地点（GPS座標）を含む都市計画区域の値であり、丁目全体の代表値ではありません。\n3. 次の見出し【都市計画情報】を付け、取得できたデータがある項目だけを箇条書きにする：用途地域／建ぺい率／容積率／防火・準防火地域／区域区分（市街化区域・市街化調整区域等）／地区計画 など。取得データに無い項目（高度地区・日影規制・景観計画・埋蔵文化財包蔵地・宅地造成等工事規制区域・都市計画道路など）は推測せず、省略する（「不明」とも書かない）。\n4. 次の見出し【ハザード情報】を付け、取得できたデータを箇条書きにする：洪水浸水想定（対象河川・浸水深ランクを含む）／高潮／津波／土砂災害警戒区域・特別警戒区域／液状化／急傾斜地 など。ハザードは『該当なし』『区域外』のデータも、その旨を明記してよい（例：「・土砂災害警戒区域：該当なし」）。内水氾濫・地震時の揺れやすさ・火災危険度は取得データに無いため記載しない。\n5. 数値（建ぺい率・容積率・浸水深ランク等）は取得データの値をそのまま使い、創作・概算・補完をしない。\n6. 次の見出し【AIコメント】を付け、上で取得できたデータの内容だけに基づいて、用途地域から想定される街の特徴と、災害リスクの傾向（該当なし＝リスク低い等）を2〜4文でやさしく要約する。データから読み取れない購入・建築・投資の可否や具体的な工事の助言、営業的な誘導は書かない。最後に「実際の建築可否や詳細な法規制については、行政窓口等で最終確認してください。」と添える。\n7. 最後に見出し【出典】を付け、次の行だけを記載する：\n・国土交通省 不動産情報ライブラリ\n（自治体オープンデータを使った場合のみ次の行も加える）・各自治体オープンデータ\n8. 上記以外の見出し・分析・感想・出典表記は付けない。取得件数・取得日時・データセットID等の技術情報は表示しない。";
    }
    return ['context' => implode("\n", $parts), 'sources' => $sources, 'notices' => array_values(array_unique($notices)), 'meta' => $meta, 'attempted' => true];
}

/**
 * Build a short, user-facing transparency footer describing what was actually
 * retrieved from APIs/DB for this answer: source, record count, fetch time.
 * Returns '' when no data was retrieved.
 */
function chatPublicDataTransparencyFooter($meta) {
    if (empty($meta) || !is_array($meta)) return '';
    $lines = [];
    foreach ($meta as $m) {
        if (!is_array($m)) continue;
        $label = $m['label'] ?? '';
        if ($label === '') continue;
        $status = $m['status'] ?? 'data';
        if ($status !== 'data') {
            // 区域外 / 該当なし / 取得失敗 — show the judgement, not a misleading "0 件".
            $line = '・' . ($m['title'] ?? $label) . '：' . ($m['status_label'] ?? '該当なし');
            if (!empty($m['fetched_at'])) {
                $line .= '／取得 ' . mb_substr((string)$m['fetched_at'], 0, 16);
            }
            $lines[] = $line;
            continue;
        }
        $count = isset($m['record_count']) ? (int)$m['record_count'] : null;
        $total = array_key_exists('total_count', $m) && $m['total_count'] !== null ? (int)$m['total_count'] : null;
        $line = '・' . $label;
        if ($total !== null && $count !== null && $total > $count) {
            $line .= '：該当 ' . $total . ' 件（うち ' . $count . ' 件を参照）';
        } elseif ($total !== null) {
            $line .= '：' . $total . ' 件';
        } elseif ($count !== null) {
            $line .= '：' . $count . ' 件';
        }
        if (!empty($m['fetched_at'])) {
            $line .= '／取得 ' . mb_substr((string)$m['fetched_at'], 0, 16);
        }
        $lines[] = $line;
    }
    if (empty($lines)) return '';
    return "----\n📊 データ取得情報（実データ）\n" . implode("\n", $lines);
}


function chatPublicDataSourcesForUi($sources, $meta = []) {
    $map = [
        '国土交通省 不動産情報ライブラリ' => 'https://www.reinfolib.mlit.go.jp/',
        '国土交通データプラットフォーム' => 'https://data-platform.mlit.go.jp/',
        '政府統計の総合窓口 e-Stat' => 'https://www.e-stat.go.jp/',
        '当社 全国マンションデータベース' => '',
    ];
    // Several reinfolib layers share one source label. Keep the most informative
    // entry per label (a layer WITH data beats a 該当なし/区域外/取得失敗 one) so the
    // source chip's count is not clobbered to 0 by a no-data layer.
    $metaByLabel = [];
    $metaScore = function ($x) {
        $s = (($x['status'] ?? 'data') === 'data') ? 1000000 : 0;
        $c = array_key_exists('total_count', $x) && $x['total_count'] !== null ? (int)$x['total_count'] : (int)($x['record_count'] ?? 0);
        return $s + $c;
    };
    foreach ((array)$meta as $m) {
        if (!is_array($m) || empty($m['label'])) continue;
        $lbl = $m['label'];
        if (!isset($metaByLabel[$lbl]) || $metaScore($m) > $metaScore($metaByLabel[$lbl])) {
            $metaByLabel[$lbl] = $m;
        }
    }
    $items = [];
    foreach (array_values(array_unique(array_filter((array)$sources))) as $source) {
        $label = is_array($source) ? ($source['title'] ?? '') : (string)$source;
        if ($label === '') continue;
        $url = $map[$label] ?? '';
        $item = ['title' => $label, 'url' => $url];
        if (isset($metaByLabel[$label])) {
            $m = $metaByLabel[$label];
            $item['from_api'] = true;
            if (array_key_exists('total_count', $m) && $m['total_count'] !== null) $item['record_count'] = (int)$m['total_count'];
            elseif (isset($m['record_count'])) $item['record_count'] = (int)$m['record_count'];
            if (!empty($m['fetched_at'])) $item['fetched_at'] = (string)$m['fetched_at'];
            $item['cached'] = !empty($m['cached']);
        }
        $items[] = $item;
    }
    return $items;
}

function ensureChatPublicDataAccessLogTable($db) {
    if (!$db instanceof PDO) return;
    $db->exec("CREATE TABLE IF NOT EXISTS chat_public_data_access_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        session_id CHAR(36) NULL,
        business_card_id INT NULL,
        user_message TEXT NULL,
        provider VARCHAR(60) NOT NULL,
        record_count INT NULL,
        total_count INT NULL,
        cached TINYINT(1) NOT NULL DEFAULT 0,
        fetched_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chat_pda_log_session (session_id),
        INDEX idx_chat_pda_log_card_created (business_card_id, created_at),
        INDEX idx_chat_pda_log_provider_created (provider, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Record, per user message, which public-data providers were actually invoked
 * and how many records they returned. This is the audit trail that answers
 * "which questions used the API vs a normal answer" and "how many records".
 */
function chatLogPublicDataAccess($db, $sessionId, $businessCardId, $message, $meta) {
    if (!$db instanceof PDO || empty($meta) || !is_array($meta)) return;
    try {
        ensureChatPublicDataAccessLogTable($db);
        $stmt = $db->prepare("INSERT INTO chat_public_data_access_log
            (session_id, business_card_id, user_message, provider, record_count, total_count, cached, fetched_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $shortMessage = mb_substr((string)$message, 0, 500);
        foreach ($meta as $m) {
            if (!is_array($m) || empty($m['provider'])) continue;
            $stmt->execute([
                $sessionId !== '' ? $sessionId : null,
                $businessCardId !== null ? (int)$businessCardId : null,
                $shortMessage,
                $m['provider'],
                isset($m['record_count']) ? (int)$m['record_count'] : null,
                array_key_exists('total_count', $m) && $m['total_count'] !== null ? (int)$m['total_count'] : null,
                !empty($m['cached']) ? 1 : 0,
                !empty($m['fetched_at']) ? $m['fetched_at'] : null,
            ]);
        }
    } catch (Throwable $e) {
        error_log('Chat public data access log error: ' . $e->getMessage());
    }
}

function chatAppendPublicDataSourcesToReply($reply, $sources) {
    $sources = array_values(array_unique(array_filter((array)$sources)));
    if (empty($sources)) return $reply;
    foreach ($sources as $source) {
        if (mb_strpos($reply, $source) !== false) return $reply;
    }
    return rtrim($reply) . "\n\n出典：" . implode('／', $sources);
}
