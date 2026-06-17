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
    return (bool)preg_match('/(住所|所在地|駅|エリア|地域|周辺|公的|データ|国土交通|政府統計|統計|相場|取引価格|成約|地価|公示|地価調査|基準地価|鑑定|評価書|路線価|災害|防災|浸水|洪水|水害|土砂|地盤|液状化|津波|高潮|地すべり|地滑り|急傾斜|崖|がけ|盛土|造成地|災害危険|被災|用途地域|建蔽率|建ぺい率|容積率|都市計画|区域区分|市街化|立地適正化|防火|地区計画|高度利用|再開発|交通|道路|インフラ|学校|小学校|中学校|高校|学区|病院|医療|クリニック|診療|図書館|公園|役所|役場|公民館|避難|保育|幼稚園|こども園|福祉|介護|老人ホーム|人口|世帯|高齢|子育て|子供|子ども|ファミリー|年収|昼夜|外国人|持ち家|人口集中|DID|将来人口|推計人口|マンション|物件|建物|基礎情報|基本情報|概要|詳細|築年月|築年数|竣工|総戸数|階建|最寄り|乗降|乗降客|乗降人員|利用者数|乗客|混雑)/u', (string)$message);
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
            !empty($result['ok']) ? max(60, (int)$ttlSeconds) : 300,
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
            !empty($result['ok']) ? max(60, (int)$ttlSeconds) : 300,
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
    $s = mb_convert_kana($s, 'KVC');
    $s = mb_strtolower($s);
    // Long-vowel marks and hyphen/dash/tilde variants → removed (treated as noise).
    $s = preg_replace('/[ー―‐\x{2010}-\x{2015}\x{2212}\x{301C}\x{FF5E}\-－〜~ｰ]/u', '', $s);
    // Spaces, middle dots, quotes, punctuation, brackets and common symbols → removed.
    $s = preg_replace('/[\s\x{3000}・･,，、。.／\/「」『』（）()\[\]【】｛｝{}＆&\x{2019}\x{2018}\x{201C}\x{201D}\x{0027}\x{0060}"’‘`*~!！?？:：;；|｜＿_]/u', '', $s);
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
        '/([' . $nameChars . ']{2,80}?)(?:の)?(?:' . $fieldWords . ')/u',
        '/(?:マンション|物件|建物)(?:名)?(?:は|の|：|:)?\s*([' . $nameChars . ']{2,80})/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) {
            $term = chatNormalizeMansionSearchTerm($m[1]);
            if ($term !== '') $terms[] = $term;
        }
    }
    if (preg_match('/(マンション|物件|建物|' . $fieldWords . ')/u', $message)) {
        $clean = preg_replace('/(について|教えて|ください|下さい|知りたい|調べて|検索して|確認して|どこ|ですか|でしょうか|' . $fieldWords . '|マンション名|物件名|建物名|マンション|物件|建物|の|は|を|。|、|\?|？)/u', ' ', $message);
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
        if ($cand !== '' && mb_strlen($cand) >= 4 && preg_match('/[ァ-ヶ]/u', $cand)) {
            $terms[] = $cand;
        }
    }
    return array_values(array_unique(array_filter($terms)));
}

function chatMansionDbSearchRows($db, $terms, $limit = 5) {
    if (!$db instanceof PDO) return [];
    $limit = max(1, min(10, (int)$limit));
    $rows = [];
    $seen = [];
    foreach (array_slice((array)$terms, 0, 4) as $term) {
        $term = chatNormalizeMansionSearchTerm($term);
        if ($term === '') continue;
        // Canonical 表記ブレ-insensitive match against the normalized columns, with
        // a raw building_name LIKE kept as a recall fallback (collation already
        // folds 全半角/かな/大小文字 there). name_norm/search_norm collapse spaces,
        // 中黒, 長音, ハイフン, 記号 so those variants all hit the same row.
        $norm = chatMansionNormalizeText($term);
        if ($norm === '') continue;
        $nlike = '%' . $norm . '%';
        $nprefix = $norm . '%';
        $rawLike = '%' . $term . '%';
        $stmt = $db->prepare("SELECT building_name, postal_code, prefecture, city, town, address_detail, full_address, structure, floors_above, floors_below, built_year_month, total_units, nearest_line, nearest_station, nearest_access_method, nearest_minutes, transports_json
            FROM mansion_buildings
            WHERE name_norm LIKE :n1 OR search_norm LIKE :n2 OR building_name LIKE :raw
            ORDER BY CASE WHEN name_norm = :neq THEN 0 WHEN name_norm LIKE :n3 THEN 1 WHEN search_norm LIKE :n4 THEN 2 ELSE 3 END, id ASC
            LIMIT {$limit}");
        $stmt->execute([':n1' => $nlike, ':n2' => $nlike, ':raw' => $rawLike, ':neq' => $norm, ':n3' => $nprefix, ':n4' => $nlike]);
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
        if (preg_match('/^[一-龥ぁ-んァ-ン]+(?:都|道|府|県|市|区|町|村)(?:の)?(?:マンション|物件|建物)?$/u', $term) && mb_strlen($term) <= 14) continue;
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

function chatMansionDbDirectAnswer($db, $message) {
    if (!$db instanceof PDO) return null;
    if (!preg_match('/(マンション|物件|建物|基礎情報|基本情報|建物情報|物件情報|マンション情報|概要|詳細|情報|築年月|築年数|築|竣工|構造|総戸数|戸数|階建|最寄り駅|最寄駅|住所|所在地|所在|アクセス|どこ|場所|について|教えて|調べて|知りたい|検索)/u', (string)$message)) return null;
    $terms = chatExtractMansionSearchTerms($message);
    if (empty($terms) || !chatMansionTermLooksSpecific($terms, $message)) return null;
    $fields = chatMansionRequestedFields($message);
    if (empty($fields)) return null;

    try {
        $rows = chatMansionDbSearchRows($db, $terms, 3);
        if (empty($rows)) return null;
        $row = $rows[0];
        $facts = chatMansionFormatFacts($row, $fields);
        if (empty($facts)) return null;

        $source = chatPublicDataSourceLabel('mansion_db');
        $fetchedAt = date('Y-m-d H:i:s');
        $reply = ($row['building_name'] ?? '該当マンション') . 'について、当社データベースでは次の内容を確認できます。' . "\n\n・" . implode("\n・", $facts);
        if (count($rows) > 1) {
            $reply .= "\n\n※似た名称の候補が複数あります。必要であれば住所やエリアを添えていただくと、より絞り込めます。";
        }
        $reply .= "\n\n出典：" . $source;
        $meta = [[
            'provider' => 'mansion_db',
            'label' => $source,
            'record_count' => count($rows),
            'total_count' => count($rows),
            'fetched_at' => $fetchedAt,
            'cached' => false,
        ]];
        $footer = chatPublicDataTransparencyFooter($meta);
        if ($footer !== '') $reply .= "\n\n" . $footer;
        return [
            'reply' => $reply,
            'sources' => chatPublicDataSourcesForUi([$source], $meta),
            'row' => $row,
            'meta' => $meta,
        ];
    } catch (Throwable $e) {
        error_log('Mansion DB direct answer error: ' . $e->getMessage());
    }

    return null;
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
    $c = $data[0]['geometry']['coordinates'];
    if (!isset($c[0], $c[1])) return null;
    return [
        'lon' => (float)$c[0],
        'lat' => (float)$c[1],
        'title' => $data[0]['properties']['title'] ?? $q,
        'prefecture' => null,
    ];
}

/**
 * Resolve a lat/lon for the area a question is about: prefer a named station,
 * then fall back to GSI geocoding of 都道府県+市区町村. Used by every reinfolib
 * GIS tile API to address the right XYZ tile. Null when nothing is resolvable.
 */
function chatReinfoResolveLatLon($db, $area, $message) {
    if (!empty($area['station_name'])) {
        $geo = chatStationGeocode($db, $area['station_name'], $area['prefecture_name'] ?? null);
        if ($geo) return $geo;
    }
    $addr = trim(($area['prefecture_name'] ?? '') . ($area['city_name'] ?? ''));
    if ($addr !== '') {
        $geo = chatAddressGeocode($db, $addr);
        if ($geo) return $geo;
    }
    return null;
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
    foreach ($offsets as $off) {
        $query = array_merge([
            'response_format' => 'geojson',
            'z' => $z,
            'x' => $center['x'] + $off[0],
            'y' => $center['y'] + $off[1],
        ], $extra);
        $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/' . $code . '?' . http_build_query($query);
        $result = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], 2592000);
        $fetchedAt = $result['fetched_at'] ?? $fetchedAt;
        if (empty($result['cached'])) $cached = false;
        if (!$result['ok'] || !is_array($result['data'])) continue;
        $f = $result['data']['features'] ?? [];
        if (is_array($f) && !empty($f)) {
            $features = array_merge($features, $f);
            if ($geom !== 'point') break;      // zones: centre tile is enough
            if (count($features) >= 60) break;  // bound the work for dense layers
        }
    }
    if (empty($features)) return null;

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
    if (empty($rows)) return null;

    $base = $geo['title'] ?? trim(($area['prefecture_name'] ?? '') . ($area['city_name'] ?? ''));
    $scope = trim($base . '周辺の' . $def['title']);
    $note = ($def['note'] ?? 'これは指定地点周辺のGISデータです。');
    if ($geom === 'polygon') {
        $note .= ' 取得した区域は基準点（' . $base . '）を含む/近接する区域です。';
    } else {
        $note .= ' 基準点（' . $base . '）から近い順に表示しています。';
    }
    return [
        'provider' => 'reinfolib',
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
    // Ambiguous: the global gate passed but no provider keyword matched. Ask the
    // cheap LLM router to classify (and extract a station name if relevant).
    $llm = chatPublicDataLlmRouter($message, $registry);
    if (!empty($llm['station']) && empty($area['station_name'])) {
        $st = $llm['station'];
        $area['station_name'] = mb_substr($st, -1) === '駅' ? $st : $st . '駅';
    }
    return ['providers' => $llm['providers'], 'area' => $area, 'router' => 'llm'];
}

function chatBuildPublicDataContext($db, $message) {
    if (!chatPublicDataShouldRun($message)) return ['context' => '', 'sources' => [], 'notices' => [], 'meta' => [], 'attempted' => false];
    $area = chatPublicExtractArea($message);
    $route = chatPublicDataRoute($db, $message, $area);
    $area = $route['area'];
    // Bound per-message fan-out: a broad question (e.g. "災害") can match many
    // catalog APIs, each of which fetches one or more tiles. Cap to keep latency
    // predictable; keyword/registry order keeps the highest-value APIs first.
    $providers = array_slice($route['providers'], 0, 5);
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

        $meta[] = [
            'provider' => $item['provider'],
            'label' => $label,
            'record_count' => isset($item['record_count']) ? (int)$item['record_count'] : null,
            'total_count' => array_key_exists('total_count', $item) && $item['total_count'] !== null ? (int)$item['total_count'] : null,
            'fetched_at' => $item['fetched_at'] ?? null,
            'cached' => !empty($item['cached']),
        ];

        $extra = '';
        if ($item['provider'] === 'estat') $extra .= "\n回答でこのデータを参照する場合は、該当箇所に『政府統計によると、』という前置きを入れてください。";
        $metaLine = '取得件数: ' . (isset($item['record_count']) ? (int)$item['record_count'] . '件' : '不明');
        if (array_key_exists('total_count', $item) && $item['total_count'] !== null) $metaLine .= ' / 該当総件数: ' . (int)$item['total_count'] . '件';
        if (!empty($item['fetched_at'])) $metaLine .= ' / 取得日時: ' . $item['fetched_at'] . ($item['cached'] ? '（キャッシュ）' : '（最新取得）');
        $extra .= "\n" . $metaLine;
        if (!empty($item['count_note'])) $extra .= "\n" . $item['count_note'];
        if (!empty($item['caveat'])) $extra .= "\n注意: " . $item['caveat'];
        $parts[] = "\n【{$item['title']}】\n出典: {$label}{$extra}\n" . chatPublicDataTrimForPrompt($item['data']);
    }
    $sources = array_values(array_unique($sources));
    $parts[] = "\n回答末尾の出典表記は、本文で実際にこの取得データを使った場合だけ付けてください。取得データを使わず一般知識のみで答えた場合は出典を付けないでください。";
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
            $line .= '／取得 ' . mb_substr((string)$m['fetched_at'], 0, 16) . ($m['cached'] ? '（キャッシュ）' : '');
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
    $metaByLabel = [];
    foreach ((array)$meta as $m) {
        if (is_array($m) && !empty($m['label'])) $metaByLabel[$m['label']] = $m;
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
