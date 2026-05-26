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
    return (bool)preg_match('/(住所|所在地|駅|エリア|地域|周辺|公的|データ|国土交通|政府統計|統計|相場|取引価格|成約|地価|公示|災害|防災|浸水|洪水|水害|土砂|地盤|液状化|用途地域|都市計画|再開発|交通|道路|インフラ|人口|世帯|高齢|子育て|子供|子ども|ファミリー|年収|昼夜|外国人|持ち家|マンション|築年月|築年数|竣工|総戸数|階建|最寄り)/u', (string)$message);
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
        $stmt = $db->prepare('SELECT response_json, http_status, error_message FROM chat_public_data_cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
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
            ];
        }
    }
    $result = chatPublicDataHttpPostJson($url, $payload, $headers);
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
        $stmt = $db->prepare('SELECT response_json, http_status, error_message FROM chat_public_data_cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
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
            ];
        }
    }
    $result = chatPublicDataHttpGet($url, $headers, $timeout);
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

function chatReinfoContext($db, $message, $area) {
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') return null;
    if (!preg_match('/(相場|取引価格|成約|地価|公示|価格|マンション|エリア|地域)/u', $message)) return null;
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
    $rows = array_slice(chatPublicDataRows($result['data']), 0, 8);
    if (empty($rows)) return null;
    return [
        'provider' => 'reinfolib',
        'title' => '不動産価格・取引価格の参考データ',
        'notice' => 'このエリアの価格・取引事例を公的データで確認します。',
        'data' => $rows,
    ];
}

function chatMlitDpfContext($db, $message, $area) {
    if (!defined('MLIT_DPF_API_KEY') || MLIT_DPF_API_KEY === '') return null;
    if (!preg_match('/(公的|データ|国土交通|災害|防災|浸水|洪水|水害|土砂|地盤|液状化|都市計画|再開発|交通|道路|インフラ|河川|地域データ|周辺環境|エリア説明|子育て|子供|子ども|ファミリー)/u', $message)) return null;
    $keyword = trim(($area['prefecture_name'] ?? '') . ' ' . ($area['city_name'] ?? '') . ' ' . ($area['station_name'] ?? ''));
    if ($keyword === '') $keyword = mb_substr($message, 0, 80);
    $base = defined('MLIT_DPF_BASE_URL') ? rtrim(MLIT_DPF_BASE_URL, '/') . '/' : 'https://data-platform.mlit.go.jp/api/v1/';
    $term = json_encode($keyword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $graphql = 'query { search(first: 0, size: 10, term: ' . $term . ', phraseMatch: true) { totalNumber searchResults { id title lat lon year dataset_id catalog_id } } }';
    $result = chatPublicDataCachedPostJson($db, 'mlit_dpf', $base, ['query' => $graphql], ['apikey' => MLIT_DPF_API_KEY], 86400);
    if (!$result['ok'] || empty($result['data'])) return null;
    $notice = 'この住所周辺で関連する国交省データを探します。';
    if (preg_match('/(災害|防災)/u', $message)) $notice = 'このエリアの災害リスクを公的データで確認します。';
    elseif (preg_match('/(都市計画|再開発)/u', $message)) $notice = '再開発・都市計画の参考情報を確認します。';
    elseif (preg_match('/(交通|道路|インフラ)/u', $message)) $notice = '周辺インフラや交通環境を確認します。';
    elseif (preg_match('/(河川|浸水|水害|洪水)/u', $message)) $notice = 'ハザード関連の注意点を整理します。';
    return ['provider' => 'mlit_dpf', 'title' => '国土交通データプラットフォーム検索結果', 'notice' => $notice, 'data' => $result['data']];
}

function chatEstatContext($db, $message, $area) {
    if (!defined('ESTAT_APP_ID') || ESTAT_APP_ID === '') return null;
    if (!preg_match('/(人口|世帯|高齢|子育て|子供|子ども|ファミリー|年収|昼夜|外国人|持ち家|統計|政府統計|e-Stat)/u', $message)) return null;
    $keyword = $area['city_name'] ?: mb_substr($message, 0, 40);
    $url = 'https://api.e-stat.go.jp/rest/3.0/app/json/getStatsList?' . http_build_query([
        'appId' => ESTAT_APP_ID,
        'searchWord' => $keyword . ' 国勢調査 人口 世帯',
        'limit' => 10,
    ]);
    $result = chatPublicDataCachedGet($db, 'estat', $url, [], 86400, 5);
    if (!$result['ok'] || empty($result['data'])) return null;
    return ['provider' => 'estat', 'title' => '政府統計の検索結果', 'notice' => '政府統計による地域データを確認します。', 'data' => $result['data']];
}

function chatExtractMansionSearchTerms($message) {
    $message = trim((string)$message);
    $terms = [];
    $patterns = [
        '/「([^」]{2,80})」/u',
        '/([一-龥ぁ-んァ-ンA-Za-z0-9０-９・ー－\s]{2,80})(?:の)?(?:築年月|築年数|築|竣工|構造|総戸数|階建|最寄り駅|住所)/u',
        '/(?:マンション|物件)(?:名)?(?:は|の|：|:)?\s*([一-龥ぁ-んァ-ンA-Za-z0-9０-９・ー－\s]{2,80})/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) $terms[] = trim($m[1]);
    }
    if (preg_match('/(マンション|築年月|築年数|竣工|総戸数|階建)/u', $message)) {
        $clean = preg_replace('/(について|教えて|知りたい|ですか|でしょうか|築年月|築年数|築|竣工|構造|総戸数|階建|最寄り駅|住所|マンション|物件|の|は|を|\?|？)/u', ' ', $message);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));
        if (mb_strlen($clean) >= 2 && mb_strlen($clean) <= 80) $terms[] = $clean;
    }
    return array_values(array_unique(array_filter($terms)));
}

function chatMansionDbContext($db, $message) {
    if (!$db instanceof PDO) return null;
    if (!preg_match('/(マンション|築年月|築年数|竣工|構造|総戸数|階建|最寄り駅|物件名)/u', $message)) return null;
    $terms = chatExtractMansionSearchTerms($message);
    if (empty($terms)) return null;
    try {
        $rows = [];
        foreach (array_slice($terms, 0, 3) as $term) {
            $like = '%' . $term . '%';
            $stmt = $db->prepare("SELECT building_name, full_address, structure, floors_above, floors_below, built_year_month, total_units, nearest_line, nearest_station, nearest_access_method, nearest_minutes, transports_json
                FROM mansion_buildings
                WHERE building_name LIKE ? OR full_address LIKE ? OR search_text LIKE ?
                ORDER BY CASE WHEN building_name = ? THEN 0 WHEN building_name LIKE ? THEN 1 ELSE 2 END, id ASC
                LIMIT 5");
            $stmt->execute([$like, $like, $like, $term, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[] = $row;
            if (!empty($rows)) break;
        }
        if (empty($rows)) return null;
        return ['provider' => 'mansion_db', 'title' => '全国マンションデータベース検索結果', 'notice' => '当社の全国マンションデータベースで物件情報を確認します。', 'data' => array_slice($rows, 0, 5)];
    } catch (Throwable $e) {
        error_log('Mansion DB context error: ' . $e->getMessage());
        return null;
    }
}

function chatPublicDataTrimForPrompt($data, $maxLength = 4000) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) return '';
    return mb_strlen($json) > $maxLength ? mb_substr($json, 0, $maxLength) . "\n...（一部省略）" : $json;
}

function chatBuildPublicDataContext($db, $message) {
    if (!chatPublicDataShouldRun($message)) return ['context' => '', 'sources' => [], 'notices' => [], 'attempted' => false];
    $area = chatPublicExtractArea($message);
    $items = [];
    foreach ([
        chatMansionDbContext($db, $message),
        chatReinfoContext($db, $message, $area),
        chatMlitDpfContext($db, $message, $area),
        chatEstatContext($db, $message, $area),
    ] as $item) {
        if ($item) $items[] = $item;
    }
    if (empty($items)) {
        $context = "【公的・独自データ参照情報】
この質問は公的データ・独自データによる補強対象として判定されましたが、今回のサーバー側取得では回答に使える有効なデータを取得できませんでした。APIキーや内部情報は回答に出さないでください。取得できた事実がないため、出典つきの断定は避け、必要に応じて『公的データの取得結果を確認できませんでした』と自然に伝えてください。";
        return ['context' => $context, 'sources' => [], 'notices' => ['公的データの取得結果を確認できませんでした。'], 'attempted' => true];
    }
    $parts = ["【公的・独自データ参照情報】\n以下はサーバー側で取得した補強データです。APIキーや内部情報は回答に出さないでください。該当データが質問と関係する場合だけ、一般ユーザー向けにやさしく要約してください。"];
    $sources = [];
    $notices = [];
    foreach ($items as $item) {
        $label = chatPublicDataSourceLabel($item['provider']);
        $sources[] = $label;
        if (!empty($item['notice'])) $notices[] = $item['notice'];
        $extra = '';
        if ($item['provider'] === 'estat') $extra = "\n回答でこのデータを参照する場合は、該当箇所に『政府統計によると、』という前置きを入れてください。";
        $parts[] = "\n【{$item['title']}】\n出典: {$label}{$extra}\n" . chatPublicDataTrimForPrompt($item['data']);
    }
    $sources = array_values(array_unique($sources));
    $parts[] = "\n回答末尾に、参照したものだけ『出典：" . implode('／', $sources) . "』を明記してください。";
    return ['context' => implode("\n", $parts), 'sources' => $sources, 'notices' => array_values(array_unique($notices)), 'attempted' => true];
}


function chatPublicDataSourcesForUi($sources) {
    $map = [
        '国土交通省 不動産情報ライブラリ' => 'https://www.reinfolib.mlit.go.jp/',
        '国土交通データプラットフォーム' => 'https://data-platform.mlit.go.jp/',
        '政府統計の総合窓口 e-Stat' => 'https://www.e-stat.go.jp/',
        '当社 全国マンションデータベース' => '',
    ];
    $items = [];
    foreach (array_values(array_unique(array_filter((array)$sources))) as $source) {
        $label = is_array($source) ? ($source['title'] ?? '') : (string)$source;
        if ($label === '') continue;
        $url = $map[$label] ?? '';
        $items[] = ['title' => $label, 'url' => $url];
    }
    return $items;
}

function chatAppendPublicDataSourcesToReply($reply, $sources) {
    $sources = array_values(array_unique(array_filter((array)$sources)));
    if (empty($sources)) return $reply;
    foreach ($sources as $source) {
        if (mb_strpos($reply, $source) !== false) return $reply;
    }
    return rtrim($reply) . "\n\n出典：" . implode('／', $sources);
}
