<?php
/**
 * 物件選定機能 共通ヘルパー。
 * 提案物件（properties）・物件画像（property_images）の CRUD、
 * 販売図面/写真からの AI抽出（OCR）、物件URLの解析、ラベル定義などを提供する。
 *
 * 物件は chat_sessions（顧客↔担当エージェントの関係）に紐づく。
 * 担当側: requireAuth() + 名刺所有を検証 / 顧客側: visitor_id + session 所有を検証。
 */

require_once __DIR__ . '/openai-chat-helper.php';

/* ──────────────────────────────────────────────────────────
 * テーブル自動作成（マイグレーション未実行でも動作するよう冪等に作成）
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyEnsureTables')) {
    function propertyEnsureTables(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS properties (
          id INT AUTO_INCREMENT PRIMARY KEY,
          business_card_id INT NOT NULL,
          session_id CHAR(36) NOT NULL,
          source ENUM('agent','customer') NOT NULL DEFAULT 'agent',
          source_media VARCHAR(32) NOT NULL DEFAULT 'manual',
          source_url VARCHAR(1024) NULL DEFAULT NULL,
          status VARCHAR(24) NULL DEFAULT NULL,
          property_type ENUM('mansion','house','land') NOT NULL DEFAULT 'mansion',
          property_name VARCHAR(255) NULL DEFAULT NULL,
          building_name VARCHAR(255) NULL DEFAULT NULL,
          price_text VARCHAR(64) NULL DEFAULT NULL,
          price_man INT NULL DEFAULT NULL,
          address VARCHAR(255) NULL DEFAULT NULL,
          transport VARCHAR(512) NULL DEFAULT NULL,
          exclusive_area VARCHAR(64) NULL DEFAULT NULL,
          land_area VARCHAR(64) NULL DEFAULT NULL,
          building_area VARCHAR(64) NULL DEFAULT NULL,
          balcony_area VARCHAR(64) NULL DEFAULT NULL,
          layout VARCHAR(64) NULL DEFAULT NULL,
          built_year_month VARCHAR(32) NULL DEFAULT NULL,
          floor VARCHAR(64) NULL DEFAULT NULL,
          room_number VARCHAR(64) NULL DEFAULT NULL,
          total_units VARCHAR(32) NULL DEFAULT NULL,
          structure VARCHAR(64) NULL DEFAULT NULL,
          land_right VARCHAR(64) NULL DEFAULT NULL,
          management_form VARCHAR(64) NULL DEFAULT NULL,
          management_company VARCHAR(128) NULL DEFAULT NULL,
          management_fee VARCHAR(64) NULL DEFAULT NULL,
          repair_reserve VARCHAR(64) NULL DEFAULT NULL,
          other_fees VARCHAR(255) NULL DEFAULT NULL,
          current_status VARCHAR(32) NULL DEFAULT NULL,
          delivery VARCHAR(64) NULL DEFAULT NULL,
          transaction_type VARCHAR(64) NULL DEFAULT NULL,
          rent VARCHAR(64) NULL DEFAULT NULL,
          yield_rate VARCHAR(64) NULL DEFAULT NULL,
          remarks TEXT NULL DEFAULT NULL,
          seller_company VARCHAR(255) NULL DEFAULT NULL,
          seller_branch VARCHAR(255) NULL DEFAULT NULL,
          seller_person VARCHAR(128) NULL DEFAULT NULL,
          seller_email VARCHAR(255) NULL DEFAULT NULL,
          seller_phone VARCHAR(64) NULL DEFAULT NULL,
          seller_remarks TEXT NULL DEFAULT NULL,
          main_image_path VARCHAR(512) NULL DEFAULT NULL,
          ocr_status ENUM('none','draft','confirmed') NOT NULL DEFAULT 'none',
          hazard_json LONGTEXT NULL DEFAULT NULL,
          hazard_fetched_at TIMESTAMP NULL DEFAULT NULL,
          created_by ENUM('agent','customer') NOT NULL DEFAULT 'agent',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_properties_card (business_card_id),
          INDEX idx_properties_session (session_id),
          INDEX idx_properties_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS property_images (
          id INT AUTO_INCREMENT PRIMARY KEY,
          property_id INT NOT NULL,
          business_card_id INT NOT NULL,
          category ENUM('flyer','photo') NOT NULL DEFAULT 'photo',
          subcategory VARCHAR(32) NULL DEFAULT NULL,
          original_name VARCHAR(255) NULL DEFAULT NULL,
          stored_path VARCHAR(512) NOT NULL,
          thumb_path VARCHAR(512) NULL DEFAULT NULL,
          mime_type VARCHAR(127) NULL DEFAULT NULL,
          byte_size INT NULL DEFAULT NULL,
          width INT NULL DEFAULT NULL,
          height INT NULL DEFAULT NULL,
          display_order INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_property_images_property (property_id, category, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

/* ──────────────────────────────────────────────────────────
 * ラベル定義（§3 提案元 / §5 ステータス / §10 種別）
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertySourceLabels')) {
    /** 提案元（§3）: ラベル・色 */
    function propertySourceLabels(): array
    {
        return [
            'agent'    => ['label' => 'エージェント提案', 'color' => 'blue'],
            'customer' => ['label' => 'お客様から共有', 'color' => 'orange'],
        ];
    }
}

if (!function_exists('propertyStatusDefs')) {
    /**
     * 検討/対応ステータス（§5）。
     * role: 'customer'（顧客のみ選択可）/ 'agent'（エージェントのみ選択可）
     */
    function propertyStatusDefs(): array
    {
        return [
            'viewing_request'  => ['label' => '内見希望', 'role' => 'customer', 'color' => '#e8384f', 'icon' => 'viewing'],
            'considering'      => ['label' => '検討中',   'role' => 'customer', 'color' => '#2d6cdf', 'icon' => 'considering'],
            'passed'           => ['label' => '見送り',   'role' => 'customer', 'color' => '#8a8f98', 'icon' => 'passed'],
            'application'      => ['label' => '申込検討', 'role' => 'customer', 'color' => '#f08a24', 'icon' => 'application'],
            'brokerage_ok'     => ['label' => '仲介可',   'role' => 'agent',    'color' => '#1f9d57', 'icon' => 'brokerage'],
            'not_introducible' => ['label' => 'ご紹介不可', 'role' => 'agent',  'color' => '#6b3fd1', 'icon' => 'notintro'],
        ];
    }
}

if (!function_exists('propertyTypeLabels')) {
    function propertyTypeLabels(): array
    {
        return [
            'mansion' => 'マンション',
            'house'   => '一戸建て',
            'land'    => '土地',
        ];
    }
}

if (!function_exists('propertyMediaLabels')) {
    /** 掲載媒体（§19） */
    function propertyMediaLabels(): array
    {
        return [
            'suumo'  => 'SUUMO',
            'homes'  => "HOME'S",
            'athome' => 'アットホーム',
            'yahoo'  => 'Yahoo!不動産',
            'flyer'  => '販売図面',
            'photo'  => '写真撮影',
            'manual' => '手入力',
            'other'  => 'その他',
        ];
    }
}

/* ──────────────────────────────────────────────────────────
 * 基本情報フィールド定義（§7 抽出 / §11 表示・編集）
 * group: basic（基本情報・顧客にも表示） / seller（売主仲介会社情報・担当のみ）
 * types: 表示対象の物件種別（空なら全種別）
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyFieldDefs')) {
    function propertyFieldDefs(): array
    {
        return [
            // key, label, group, types(空=全), agent_only
            ['property_name',      '物件名',           'basic',  [], false],
            ['building_name',      'マンション名',     'basic',  [], false],
            ['price_text',         '価格',             'basic',  [], false],
            ['address',            '所在地',           'basic',  [], false],
            ['transport',          '交通',             'basic',  [], false],
            ['exclusive_area',     '専有面積',         'basic',  ['mansion'], false],
            ['land_area',          '土地面積',         'basic',  ['house','land'], false],
            ['building_area',      '建物面積',         'basic',  ['house'], false],
            ['balcony_area',       'バルコニー面積',   'basic',  ['mansion'], false],
            ['layout',             '間取り',           'basic',  [], false],
            ['built_year_month',   '築年月',           'basic',  [], false],
            ['floor',              '所在階',           'basic',  ['mansion'], false],
            ['room_number',        '部屋番号',         'basic',  ['mansion'], false],
            ['total_units',        '総戸数',           'basic',  ['mansion'], false],
            ['structure',          '構造',             'basic',  [], false],
            ['land_right',         '土地権利',         'basic',  [], false],
            ['management_form',    '管理形態',         'basic',  ['mansion'], false],
            ['management_company', '管理会社',         'basic',  ['mansion'], false],
            ['management_fee',     '管理費',           'basic',  ['mansion'], false],
            ['repair_reserve',     '修繕積立金',       'basic',  ['mansion'], false],
            ['other_fees',         'その他費用',       'basic',  [], false],
            ['current_status',     '現況',             'basic',  [], false],
            ['delivery',           '引渡',             'basic',  [], false],
            ['transaction_type',   '取引態様',         'basic',  [], false],
            ['rent',               '賃料',             'basic',  [], false],
            ['yield_rate',         '利回り',           'basic',  [], false],
            ['remarks',            '備考',             'basic',  [], false],
            // 売主仲介会社情報（担当のみ）
            ['seller_company',     '販売会社名',       'seller', [], true],
            ['seller_branch',      '支店名',           'seller', [], true],
            ['seller_person',      '担当者名',         'seller', [], true],
            ['seller_email',       'メールアドレス',   'seller', [], true],
            ['seller_phone',       '販売会社電話番号', 'seller', [], true],
            ['seller_remarks',     '備考',             'seller', [], true],
        ];
    }
}

if (!function_exists('propertyEditableColumns')) {
    /** save 時に書き込み可能なカラム一覧（基本情報＋種別＋ステータス＋提案元） */
    function propertyEditableColumns(): array
    {
        $cols = [];
        foreach (propertyFieldDefs() as $f) $cols[] = $f[0];
        return array_merge($cols, [
            'property_type', 'source', 'source_media', 'source_url', 'status', 'price_man',
        ]);
    }
}

/* ──────────────────────────────────────────────────────────
 * 所有検証（担当 / 顧客）
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyVerifyAgentSession')) {
    /** セッションが担当（userId）の名刺に属するか検証。属さなければ404終了。business_card_id を返す。 */
    function propertyVerifyAgentSession(PDO $db, string $sessionId, int $userId): int
    {
        $stmt = $db->prepare("
            SELECT cs.business_card_id
            FROM chat_sessions cs
            JOIN business_cards bc ON bc.id = cs.business_card_id
            WHERE cs.id = ? AND bc.user_id = ? LIMIT 1
        ");
        $stmt->execute([$sessionId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) sendErrorResponse('セッションが見つかりません', 404);
        return (int)$row['business_card_id'];
    }
}

if (!function_exists('propertyVerifyCustomerSession')) {
    /** セッションが顧客（visitor_id）のものか検証。属さなければ403/404終了。business_card_id を返す。 */
    function propertyVerifyCustomerSession(PDO $db, string $sessionId, string $visitorId): int
    {
        $stmt = $db->prepare("SELECT business_card_id, visitor_identifier FROM chat_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) sendErrorResponse('セッションが見つかりません', 404);
        // 既存の添付配信（download.php）と同じ認可方針:
        // セッションに visitor_identifier が登録済みなら一致を要求、未登録なら session_id 所持で許可。
        $stored = $row['visitor_identifier'] ?? '';
        if ($stored !== '' && $visitorId !== '' && $stored !== $visitorId) {
            sendErrorResponse('アクセス権がありません', 403);
        }
        return (int)$row['business_card_id'];
    }
}

if (!function_exists('propertyVerifyAgentProperty')) {
    /** 物件が担当（userId）の名刺に属するか検証。属する行（連想配列）を返す。 */
    function propertyVerifyAgentProperty(PDO $db, int $propertyId, int $userId): array
    {
        $stmt = $db->prepare("
            SELECT p.* FROM properties p
            JOIN business_cards bc ON bc.id = p.business_card_id
            WHERE p.id = ? AND bc.user_id = ? LIMIT 1
        ");
        $stmt->execute([$propertyId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) sendErrorResponse('物件が見つかりません', 404);
        return $row;
    }
}

/* ──────────────────────────────────────────────────────────
 * 取得・整形
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyImagesFor')) {
    function propertyImagesFor(PDO $db, int $propertyId, ?string $category = null): array
    {
        $sql = "SELECT id, category, subcategory, original_name, mime_type, width, height, display_order
                FROM property_images WHERE property_id = ?";
        $params = [$propertyId];
        if ($category) { $sql .= " AND category = ?"; $params[] = $category; }
        $sql .= " ORDER BY display_order ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['url'] = API_BASE_URL . '/property/image.php?id=' . (int)$r['id'];
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('propertySerialize')) {
    /**
     * 物件行を API レスポンス用に整形する。
     * $forAgent=false（顧客向け）の場合、売主仲介会社情報（seller_*）は出力しない。
     */
    function propertySerialize(PDO $db, array $row, bool $forAgent, bool $withImages = false): array
    {
        $sources = propertySourceLabels();
        $statuses = propertyStatusDefs();
        $types = propertyTypeLabels();
        $media = propertyMediaLabels();
        $src = $row['source'] ?? 'agent';
        $st = $row['status'] ?? null;

        $images = propertyImagesFor($db, (int)$row['id']);
        $flyers = array_values(array_filter($images, fn($i) => $i['category'] === 'flyer'));
        $photos = array_values(array_filter($images, fn($i) => $i['category'] === 'photo'));

        // メイン画像 URL（main_image_path 優先 → 写真 → 販売図面）
        $mainImageUrl = null;
        if (!empty($flyers)) $mainImageUrl = $flyers[0]['url'];
        if (!empty($photos)) $mainImageUrl = $photos[0]['url'];

        $out = [
            'id' => (int)$row['id'],
            'session_id' => $row['session_id'],
            'source' => $src,
            'source_label' => $sources[$src]['label'] ?? $src,
            'source_color' => $sources[$src]['color'] ?? 'blue',
            'source_media' => $row['source_media'] ?? 'manual',
            'source_media_label' => $media[$row['source_media'] ?? 'manual'] ?? ($row['source_media'] ?? ''),
            'source_url' => $row['source_url'] ?? null,
            'status' => $st,
            'status_label' => $st && isset($statuses[$st]) ? $statuses[$st]['label'] : null,
            'status_color' => $st && isset($statuses[$st]) ? $statuses[$st]['color'] : null,
            'property_type' => $row['property_type'] ?? 'mansion',
            'property_type_label' => $types[$row['property_type'] ?? 'mansion'] ?? '',
            'ocr_status' => $row['ocr_status'] ?? 'none',
            'main_image_url' => $mainImageUrl,
            'has_hazard' => !empty($row['hazard_json']) ? 1 : 0,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];

        // 基本情報フィールド（顧客向けは seller を除外）
        foreach (propertyFieldDefs() as $f) {
            list($key, , $group, , $agentOnly) = $f;
            if (!$forAgent && $agentOnly) continue;
            $out[$key] = $row[$key] ?? null;
        }

        if ($withImages) {
            $out['flyers'] = $flyers;
            $out['photos'] = $photos;
        } else {
            $out['flyer_count'] = count($flyers);
            $out['photo_count'] = count($photos);
        }

        if ($forAgent) {
            $out['hazard'] = !empty($row['hazard_json']) ? json_decode($row['hazard_json'], true) : null;
            $out['hazard_fetched_at'] = $row['hazard_fetched_at'] ?? null;
        } else {
            $out['hazard'] = !empty($row['hazard_json']) ? json_decode($row['hazard_json'], true) : null;
            $out['hazard_fetched_at'] = $row['hazard_fetched_at'] ?? null;
        }
        return $out;
    }
}

if (!function_exists('propertyPriceToMan')) {
    /** 「5,800万円」「2980万」「1億2000万円」等から万円数値を推定。失敗時 null。 */
    function propertyPriceToMan(?string $text): ?int
    {
        if ($text === null) return null;
        $t = trim($text);
        if ($t === '') return null;
        $t = mb_convert_kana($t, 'n'); // 全角数字→半角
        $man = 0; $matched = false;
        if (preg_match('/([0-9,\.]+)\s*億/u', $t, $m)) {
            $man += (float)str_replace(',', '', $m[1]) * 10000; $matched = true;
        }
        if (preg_match('/([0-9,\.]+)\s*万/u', $t, $m)) {
            $man += (float)str_replace(',', '', $m[1]); $matched = true;
        }
        if (!$matched) {
            // 「58,000,000円」等
            $digits = preg_replace('/[^0-9]/', '', $t);
            if ($digits !== '' && (int)$digits >= 10000) return (int)round((int)$digits / 10000);
            return null;
        }
        return (int)round($man);
    }
}

/* ──────────────────────────────────────────────────────────
 * AI抽出（§7 販売図面OCR / §18 URL解析）
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyExtractionPrompt')) {
    /** 抽出指示プロンプト（販売図面・URL共通）。JSONのみで返させる。 */
    function propertyExtractionPrompt(): string
    {
        $fields = [];
        foreach (propertyFieldDefs() as $f) {
            $fields[] = '"' . $f[0] . '"(' . $f[1] . ')';
        }
        return "あなたは日本の不動産販売図面・物件情報を読み取り、構造化するアシスタントです。\n"
            . "与えられた資料から以下のJSONキーの値を抽出してください。\n"
            . "推測や創作は厳禁です。資料に記載が無い項目は空文字 \"\" にしてください（特に部屋番号・担当者名・管理会社・バルコニー面積・賃料・利回りは無ければ空）。\n"
            . "物件種別 property_type は \"mansion\"(マンション) / \"house\"(一戸建て) / \"land\"(土地) のいずれか。\n"
            . "土地・戸建ての building_name は「川口市弥平2戸建て」「川口市弥平土地」の様に地名＋種別で表記してください。\n"
            . "price_text は「5,800万円」の様に表示用文字列で。\n"
            . "出力は JSON オブジェクトのみ。前後に説明文やコードフェンスを付けないこと。\n"
            . "キー: property_type, " . implode(', ', $fields);
    }
}

if (!function_exists('propertyParseExtractionJson')) {
    /** モデル出力からJSONを取り出し、編集可能カラムのみ抽出して返す。 */
    function propertyParseExtractionJson(?string $reply): array
    {
        if (!$reply) return [];
        $reply = trim($reply);
        // コードフェンス除去
        $reply = preg_replace('/^```[a-zA-Z]*\s*/', '', $reply);
        $reply = preg_replace('/```$/', '', trim($reply));
        // 最初の { から最後の } まで
        $s = strpos($reply, '{'); $e = strrpos($reply, '}');
        if ($s === false || $e === false || $e < $s) return [];
        $json = substr($reply, $s, $e - $s + 1);
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        $allowed = array_merge(propertyEditableColumns(), ['property_type']);
        $out = [];
        foreach ($data as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if (is_array($v)) $v = implode(' ', array_map('strval', $v));
            $v = trim((string)$v);
            if ($v === '') continue;
            $out[$k] = $v;
        }
        if (isset($out['property_type']) && !in_array($out['property_type'], ['mansion','house','land'], true)) {
            unset($out['property_type']);
        }
        if (isset($out['price_text'])) {
            $man = propertyPriceToMan($out['price_text']);
            if ($man !== null) $out['price_man'] = $man;
        }
        return $out;
    }
}

if (!function_exists('propertyExtractFromImages')) {
    /**
     * 販売図面の画像（複数可）を OpenAI Vision で解析し、抽出フィールド配列を返す。
     * $imagePaths: 絶対パスの配列（jpg/png/webp）。PDFは事前に画像化が必要。
     * @return array ['fields'=>[], 'error'=>string|null]
     */
    function propertyExtractFromImages(array $imagePaths, array $options = []): array
    {
        $content = [['type' => 'text', 'text' => propertyExtractionPrompt()]];
        $count = 0;
        foreach ($imagePaths as $path) {
            if (!is_file($path)) continue;
            $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/jpeg') : 'image/jpeg';
            if (strpos($mime, 'image/') !== 0) continue;
            $data = @file_get_contents($path);
            if ($data === false) continue;
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($data)],
            ];
            if (++$count >= 4) break; // 最大4枚
        }
        if ($count === 0) return ['fields' => [], 'error' => '解析できる画像がありません（PDFは画像化が必要です）。'];

        $model = 'gpt-4o-mini'; // vision対応・lightキー
        $apiKey = chatOpenAIApiKeyForModel($model);
        $messages = [['role' => 'user', 'content' => $content]];
        $res = callOpenAIChat($messages, $apiKey, $model, [
            'purpose' => 'property_ocr',
            'max_tokens' => 1024,
            'temperature' => 0.0,
            'timeout' => 60,
        ] + $options);
        if (!empty($res['error'])) return ['fields' => [], 'error' => $res['error']];
        return ['fields' => propertyParseExtractionJson($res['reply']), 'error' => null];
    }
}

if (!function_exists('propertyFetchUrlHtml')) {
    /** 物件URLのHTMLを取得し、本文テキストを抽出（タグ除去・圧縮）。 */
    function propertyFetchUrlHtml(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AIFcardBot/1.0)',
            CURLOPT_HTTPHEADER => ['Accept-Language: ja,en;q=0.8'],
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($html === false || $code >= 400) return null;
        // 文字コード→UTF-8
        $enc = mb_detect_encoding($html, ['UTF-8', 'SJIS-win', 'EUC-JP', 'JIS'], true) ?: 'UTF-8';
        if ($enc !== 'UTF-8') $html = mb_convert_encoding($html, 'UTF-8', $enc);
        // script/style 除去
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') return null;
        return mb_substr($text, 0, 12000);
    }
}

if (!function_exists('propertyDetectMediaFromUrl')) {
    /** URLから掲載媒体（§18/§19）を判定。 */
    function propertyDetectMediaFromUrl(string $url): string
    {
        $u = strtolower($url);
        if (strpos($u, 'suumo.jp') !== false) return 'suumo';
        if (strpos($u, 'homes.co.jp') !== false) return 'homes';
        if (strpos($u, 'athome.co.jp') !== false || strpos($u, 'athome.jp') !== false) return 'athome';
        if (strpos($u, 'realestate.yahoo') !== false || strpos($u, 'yahoo.co.jp') !== false) return 'yahoo';
        return 'other';
    }
}

if (!function_exists('propertyExtractFromUrl')) {
    /**
     * 物件URLのページ本文を取得し、OpenAIで構造化抽出する（§18）。
     * @return array ['fields'=>[], 'media'=>string, 'error'=>string|null]
     */
    function propertyExtractFromUrl(string $url, array $options = []): array
    {
        $media = propertyDetectMediaFromUrl($url);
        $text = propertyFetchUrlHtml($url);
        if ($text === null) return ['fields' => [], 'media' => $media, 'error' => 'URLの内容を取得できませんでした。'];

        $prompt = propertyExtractionPrompt() . "\n\n--- ページ本文 ---\n" . $text;
        $model = 'gpt-4o-mini';
        $apiKey = chatOpenAIApiKeyForModel($model);
        $messages = [['role' => 'user', 'content' => $prompt]];
        $res = callOpenAIChat($messages, $apiKey, $model, [
            'purpose' => 'property_url',
            'max_tokens' => 1024,
            'temperature' => 0.0,
            'timeout' => 45,
        ] + $options);
        if (!empty($res['error'])) return ['fields' => [], 'media' => $media, 'error' => $res['error']];
        $fields = propertyParseExtractionJson($res['reply']);
        $fields['source_url'] = $url;     // §18 URLを忘れずに保存
        $fields['source_media'] = $media; // §19 掲載媒体
        return ['fields' => $fields, 'media' => $media, 'error' => null];
    }
}

/* ──────────────────────────────────────────────────────────
 * 書き込み（作成・更新）
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyApplyFields')) {
    /**
     * 物件レコードに編集可能フィールドを適用（UPDATE）。
     * $fields: ['property_name'=>..., ...]（編集可能カラムのみ反映）。
     */
    function propertyApplyFields(PDO $db, int $propertyId, array $fields): void
    {
        $cols = propertyEditableColumns();
        $set = []; $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $cols, true)) continue;
            $set[] = "`$k` = ?";
            $params[] = ($v === '' ? null : $v);
        }
        if (!$set) return;
        // price_text が来ていて price_man 未指定なら補完
        if (isset($fields['price_text']) && !isset($fields['price_man'])) {
            $man = propertyPriceToMan((string)$fields['price_text']);
            if ($man !== null) { $set[] = "`price_man` = ?"; $params[] = $man; }
        }
        $params[] = $propertyId;
        $sql = "UPDATE properties SET " . implode(', ', $set) . " WHERE id = ?";
        $db->prepare($sql)->execute($params);
    }
}

/* ──────────────────────────────────────────────────────────
 * 画像保存（販売図面 §14 / 写真・資料 §15）
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyResizeImage')) {
    /** 画像を長辺 $maxEdge px 以内へリサイズしメタデータ除去。返り値に width/height。 */
    function propertyResizeImage(string $path, string $mime, int $maxEdge = 2000, int $quality = 85): array
    {
        $info = @getimagesize($path);
        if ($info === false) return ['width' => null, 'height' => null];
        $w = (int)$info[0]; $h = (int)$info[1];
        if (class_exists('Imagick')) {
            try {
                $img = new Imagick($path);
                if (method_exists($img, 'autoOrient')) { @$img->autoOrient(); }
                if ($w > $maxEdge || $h > $maxEdge) {
                    $img->resizeImage($w >= $h ? $maxEdge : 0, $w >= $h ? 0 : $maxEdge, Imagick::FILTER_LANCZOS, 1);
                }
                $img->stripImage();
                if ($mime === 'image/jpeg' || $mime === 'image/webp') $img->setImageCompressionQuality($quality);
                $img->writeImage($path);
                $nw = $img->getImageWidth(); $nh = $img->getImageHeight();
                $img->clear(); $img->destroy();
                return ['width' => $nw, 'height' => $nh];
            } catch (Throwable $e) { /* fall through to GD */ }
        }
        if (!function_exists('imagecreatetruecolor')) return ['width' => $w, 'height' => $h];
        switch ($mime) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
            case 'image/png':  $src = @imagecreatefrompng($path); break;
            case 'image/gif':  $src = @imagecreatefromgif($path); break;
            case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
            default: $src = false;
        }
        if (!$src) return ['width' => $w, 'height' => $h];
        $scale = ($w > $maxEdge || $h > $maxEdge) ? ($maxEdge / max($w, $h)) : 1.0;
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        switch ($mime) {
            case 'image/jpeg': imagejpeg($dst, $path, $quality); break;
            case 'image/png':  imagepng($dst, $path, 6); break;
            case 'image/gif':  imagegif($dst, $path); break;
            case 'image/webp': if (function_exists('imagewebp')) imagewebp($dst, $path, $quality); break;
        }
        imagedestroy($src); imagedestroy($dst);
        return ['width' => $nw, 'height' => $nh];
    }
}

if (!function_exists('propertyStoreUploadedFile')) {
    /**
     * アップロードされた1ファイルを物件画像として保存し property_images へ登録、行を返す。
     * 画像/PDFのみ許可。画像は自動リサイズ（§15）。失敗時は ['error'=>...] を返す。
     */
    function propertyStoreUploadedFile(PDO $db, array $file, int $propertyId, int $businessCardId, string $category, ?string $subcategory = null): array
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return ['error' => 'ファイルのアップロードに失敗しました'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = strtolower(trim((string)finfo_file($finfo, $file['tmp_name'])));
        if ($finfo) finfo_close($finfo);
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $isImage = in_array($mime, $imageMimes, true);
        $isPdf = ($mime === 'application/pdf' || $ext === 'pdf');
        if (!$isImage && !$isPdf) return ['error' => '対応していない形式です（画像/PDFのみ）'];
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'], true)) return ['error' => '対応していない拡張子です'];

        $relDir = 'property/' . $businessCardId . '/' . $propertyId;
        $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return ['error' => '保存先を作成できませんでした'];
        }
        $safeExt = $isImage ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'pdf';
        $stored = bin2hex(random_bytes(16)) . '.' . $safeExt;
        $absPath = $absDir . '/' . $stored;
        $relPath = $relDir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $absPath)) return ['error' => 'ファイルの保存に失敗しました'];

        if (function_exists('upload_security_clamav_scan')) {
            $scan = upload_security_clamav_scan($absPath);
            if (empty($scan['ok'])) { @unlink($absPath); return ['error' => 'ファイルから脅威が検出されました']; }
        }

        $width = null; $height = null;
        if ($isImage) {
            $r = propertyResizeImage($absPath, $mime, 2000, 85);
            $width = $r['width']; $height = $r['height'];
        }
        $byteSize = filesize($absPath) ?: (int)($file['size'] ?? 0);
        $origName = mb_substr(basename(str_replace('\\', '/', $file['name'] ?? 'file')), 0, 200);

        // display_order = 末尾
        $stmt = $db->prepare("SELECT COALESCE(MAX(display_order),0)+1 FROM property_images WHERE property_id = ? AND category = ?");
        $stmt->execute([$propertyId, $category]);
        $order = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("
            INSERT INTO property_images
              (property_id, business_card_id, category, subcategory, original_name, stored_path, mime_type, byte_size, width, height, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$propertyId, $businessCardId, $category, $subcategory, $origName, $relPath, $mime, $byteSize, $width, $height, $order]);
        $id = (int)$db->lastInsertId();

        return [
            'id' => $id, 'category' => $category, 'subcategory' => $subcategory,
            'original_name' => $origName, 'mime_type' => $mime, 'width' => $width, 'height' => $height,
            'stored_path' => $relPath, 'abs_path' => $absPath, 'is_image' => $isImage ? 1 : 0, 'is_pdf' => $isPdf ? 1 : 0,
            'url' => API_BASE_URL . '/property/image.php?id=' . $id,
        ];
    }
}

if (!function_exists('propertyRasterizePdfFirstPage')) {
    /** PDFの先頭ページを一時JPEGに変換しパスを返す（OCR用）。Imagick必須。失敗時 null。 */
    function propertyRasterizePdfFirstPage(string $pdfPath): ?string
    {
        if (!class_exists('Imagick')) return null;
        try {
            $img = new Imagick();
            $img->setResolution(150, 150);
            $img->readImage($pdfPath . '[0]');
            $img->setImageFormat('jpeg');
            $img->setImageBackgroundColor('white');
            if (method_exists($img, 'flattenImages')) { $img = $img->flattenImages(); }
            $tmp = tempnam(sys_get_temp_dir(), 'prop_pdf_') . '.jpg';
            $img->writeImage($tmp);
            $img->clear(); $img->destroy();
            return is_file($tmp) ? $tmp : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('propertyCreate')) {
    /**
     * 物件を新規作成し id を返す。
     * $meta: business_card_id, session_id, source, source_media, created_by, ocr_status
     * $fields: 基本情報フィールド
     */
    function propertyCreate(PDO $db, array $meta, array $fields = []): int
    {
        $stmt = $db->prepare("
            INSERT INTO properties (business_card_id, session_id, source, source_media, source_url, created_by, ocr_status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$meta['business_card_id'],
            $meta['session_id'],
            $meta['source'] ?? 'agent',
            $meta['source_media'] ?? 'manual',
            $meta['source_url'] ?? null,
            $meta['created_by'] ?? 'agent',
            $meta['ocr_status'] ?? 'none',
        ]);
        $id = (int)$db->lastInsertId();
        if ($fields) propertyApplyFields($db, $id, $fields);
        return $id;
    }
}
