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
require_once __DIR__ . '/chat-phone-helper.php';

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
          thumbnail_image_id INT NULL DEFAULT NULL,
          ocr_status ENUM('none','draft','confirmed') NOT NULL DEFAULT 'none',
          hazard_json LONGTEXT NULL DEFAULT NULL,
          hazard_fetched_at TIMESTAMP NULL DEFAULT NULL,
          expires_at TIMESTAMP NULL DEFAULT NULL,
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
          preview_path VARCHAR(512) NULL DEFAULT NULL,
          masked_path VARCHAR(512) NULL DEFAULT NULL,
          masked_thumb_path VARCHAR(512) NULL DEFAULT NULL,
          mask_regions TEXT NULL DEFAULT NULL,
          mask_status ENUM('none','pending','masked') NOT NULL DEFAULT 'none',
          customer_visible TINYINT(1) NOT NULL DEFAULT 0,
          expires_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_property_images_property (property_id, category, display_order),
          INDEX idx_property_images_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        propertyEnsureFlyerMaskColumns($db);
        propertyEnsureRetentionColumns($db);
        $done = true;
    }
}

if (!function_exists('propertyEnsureFlyerMaskColumns')) {
    /** 既存の property_images に販売図面マスク用カラムを冪等に追加する。 */
    function propertyEnsureFlyerMaskColumns(PDO $db): void
    {
        $cols = [
            'preview_path' => "ADD COLUMN preview_path VARCHAR(512) NULL DEFAULT NULL AFTER display_order",
            'masked_path'  => "ADD COLUMN masked_path VARCHAR(512) NULL DEFAULT NULL AFTER preview_path",
            'mask_regions' => "ADD COLUMN mask_regions TEXT NULL DEFAULT NULL AFTER masked_path",
            'mask_status'  => "ADD COLUMN mask_status ENUM('none','pending','masked') NOT NULL DEFAULT 'none' AFTER mask_regions",
            // 顧客への公開可否（担当が編集・確認を完了して初めて 1。既定 0 = 非公開）
            'customer_visible' => "ADD COLUMN customer_visible TINYINT(1) NOT NULL DEFAULT 0 AFTER mask_status",
            // 顧客用マスク済販売図面のラスタ画像（サムネイル＆ビューア用・1ページ目）
            'masked_thumb_path' => "ADD COLUMN masked_thumb_path VARCHAR(512) NULL DEFAULT NULL AFTER masked_path",
        ];
        foreach ($cols as $name => $ddl) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'property_images' AND COLUMN_NAME = ?");
                $stmt->execute([$name]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $db->exec("ALTER TABLE property_images " . $ddl);
                }
            } catch (Throwable $e) { /* 既に存在 / 権限不足は無視 */ }
        }
    }
}

if (!function_exists('propertyEnsureRetentionColumns')) {
    /** 保存期間・サムネイル用カラムを冪等に追加する（既存テーブル向け）。 */
    function propertyEnsureRetentionColumns(PDO $db): void
    {
        $alters = [
            ['properties', 'thumbnail_image_id', "ADD COLUMN thumbnail_image_id INT NULL DEFAULT NULL AFTER main_image_path"],
            ['properties', 'expires_at', "ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL AFTER hazard_fetched_at"],
            ['property_images', 'expires_at', "ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL AFTER mask_status"],
        ];
        foreach ($alters as [$table, $col, $ddl]) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $stmt->execute([$table, $col]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $db->exec("ALTER TABLE `$table` " . $ddl);
                }
            } catch (Throwable $e) { /* 無視 */ }
        }
    }
}

if (!function_exists('propertyRetentionMonths')) {
    /** 保存期間（月）。デフォルト6か月。 */
    function propertyRetentionMonths(): int
    {
        $v = (int)(getenv('PROPERTY_RETENTION_MONTHS') ?: 6);
        return $v > 0 ? $v : 6;
    }
}
if (!function_exists('propertyRetentionExpiresAt')) {
    /** 現在時刻から保存期間後の 'Y-m-d H:i:s' を返す。 */
    function propertyRetentionExpiresAt(): string
    {
        return date('Y-m-d H:i:s', strtotime('+' . propertyRetentionMonths() . ' months'));
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
            // 物件名は building_name に一本化（マンション=名称 / 戸建・土地=住所から「川口市弥平戸建て」等）
            ['building_name',      '物件名',           'basic',  [], false],
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
            ['rent',               '賃料',             'basic',  [], false],
            ['yield_rate',         '利回り',           'basic',  [], false],
            ['remarks',            '備考',             'basic',  [], false],
            // 売主仲介会社情報（担当のみ）
            ['seller_company',     '販売会社名',       'seller', [], true],
            ['seller_branch',      '支店名',           'seller', [], true],
            ['seller_person',      '担当者名',         'seller', [], true],
            ['seller_email',       'メールアドレス',   'seller', [], true],
            ['seller_phone',       '販売会社電話番号', 'seller', [], true],
            ['transaction_type',   '取引態様',         'seller', [], true],
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
        // セッションに visitor_identifier が登録済みなら一致を要求、未登録なら session_id 所持で許可。
        // ただし同一電話番号でSMS認証済みの別端末（複数端末共有）は認可集合で許可する。
        if (!chatSessionVisitorAuthorized($db, $sessionId, $visitorId, $row['visitor_identifier'] ?? '')) {
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
if (!function_exists('propertyImageBaseUrl')) {
    /**
     * 物件画像配信URLのベース（相対パス）。
     * 同一オリジンで配信するため絶対URL（www固定）ではなく相対パスにする。
     * www/非www 等オリジンが異なるとセッションCookieが送られず担当の画像が403になるため。
     */
    function propertyImageBaseUrl(): string
    {
        $path = parse_url(API_BASE_URL, PHP_URL_PATH);
        return ($path ?: '/backend/api') . '/property/image.php?id=';
    }
}

if (!function_exists('propertyImagesFor')) {
    function propertyImagesFor(PDO $db, int $propertyId, ?string $category = null): array
    {
        $sql = "SELECT id, category, subcategory, original_name, mime_type, width, height, display_order,
                       preview_path, masked_path, masked_thumb_path, mask_regions, mask_status, customer_visible
                FROM property_images WHERE property_id = ?";
        $params = [$propertyId];
        if ($category) { $sql .= " AND category = ?"; $params[] = $category; }
        $sql .= " ORDER BY display_order ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $base = propertyImageBaseUrl();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $r['url'] = $base . $id;                                  // 既定（担当=原本 / 顧客=マスク済）
            $r['preview_url'] = $base . $id . '&variant=preview';     // 編集用ラスタ
            $r['masked_url'] = !empty($r['masked_path']) ? $base . $id . '&variant=masked' : null;
            // サムネイルは masked_thumb_path が無くても masked_path があれば配信時に遅延生成する
            $r['masked_thumb_url'] = (!empty($r['masked_thumb_path']) || !empty($r['masked_path'])) ? $base . $id . '&variant=masked_thumb' : null;
            $r['mask_status'] = $r['mask_status'] ?? 'none';
            $r['customer_visible'] = (int)($r['customer_visible'] ?? 0);
            $r['mask_regions'] = !empty($r['mask_regions']) ? json_decode($r['mask_regions'], true) : [];
            unset($r['preview_path'], $r['masked_path'], $r['masked_thumb_path']);
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

        // 顧客向けには「担当が編集・確認を完了して公開した（customer_visible=1）」販売図面のみ公開する。
        // 編集未完了のものは絶対に出さない（売主仲介会社情報の漏えい防止）。配信は常にマスク済PDF。
        if (!$forAgent) {
            $flyers = array_values(array_filter($flyers, fn($i) =>
                ($i['mask_status'] ?? 'none') === 'masked'
                && (int)($i['customer_visible'] ?? 0) === 1
                && !empty($i['masked_url'])));
            foreach ($flyers as &$fl) {
                $fl['mime_type'] = 'application/pdf';
                if (!empty($fl['masked_url'])) $fl['url'] = $fl['masked_url'];
                // 顧客サムネイル＆ビューア用のラスタ画像（白抜き適用後の1ページ目）
                $fl['thumb_url'] = $fl['masked_thumb_url'] ?? null;
                unset($fl['mask_regions']);
            }
            unset($fl);
        }

        // メイン画像 URL（一覧サムネイル §4-§6）。
        // アップロード時に選定した thumbnail_image_id（建物外観→間取り図）を最優先。無ければ写真の先頭。
        // 担当のみ販売図面プレビュー(JPEG)へのフォールバックを許可（顧客はマスク済PDFのためサムネ不可）。
        $mainImageUrl = null;
        $thumbId = !empty($row['thumbnail_image_id']) ? (int)$row['thumbnail_image_id'] : 0;
        if ($thumbId) {
            // サムネイルは「写真・資料」（クリーンな画像）なので担当・顧客の双方で表示可能。
            foreach ($photos as $ph) { if ((int)$ph['id'] === $thumbId) { $mainImageUrl = $ph['url']; break; } }
        }
        if ($mainImageUrl === null && !empty($photos)) $mainImageUrl = $photos[0]['url'];
        if ($mainImageUrl === null && $forAgent && !empty($flyers)) $mainImageUrl = $flyers[0]['preview_url'] ?? $flyers[0]['url'];

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
            . "building_name（物件名）は次の規則で1つだけ設定してください:\n"
            . "  - マンションの場合: マンションの正式名称（例: コスモ東高円寺ロイヤルフォルム）。\n"
            . "  - 一戸建ての場合: 所在地から「市区町村＋町名＋戸建て」（例: 川口市弥平戸建て）。丁目・番地の数字は付けない。\n"
            . "  - 土地の場合: 所在地から「市区町村＋町名＋土地」（例: 川口市弥平土地）。丁目・番地の数字は付けない。\n"
            . "price_text は「5,800万円」の様に表示用文字列で。\n"
            . "取引態様 transaction_type は『売主／代理／一般媒介／専任媒介／専属専任媒介／媒介』等の記載が図面にあれば、必ずそのまま取得してください。\n"
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

        $model = propertyFlyerModel();
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

if (!function_exists('chatResolvePropertyFromAttachments')) {
    /**
     * 顧客がチャットに添付した画像（販売図面・物件写真等）から、Vision で物件情報を抽出する。
     * 画像添付が1枚も無ければ has_image=false。抽出できた物件名/所在地があれば identified=true。
     * $attachmentIds は client 入力由来のため、必ず当該セッションの画像のみに限定する。
     * @return array ['has_image'=>bool, 'identified'=>bool, 'fields'=>array, 'error'=>?string]
     */
    function chatResolvePropertyFromAttachments(PDO $db, string $sessionId, array $attachmentIds): array
    {
        $attachmentIds = array_values(array_filter(array_map('intval', $attachmentIds)));
        $empty = ['has_image' => false, 'identified' => false, 'fields' => [], 'error' => null];
        if (!$attachmentIds || $sessionId === '') return $empty;

        $place = implode(',', array_fill(0, count($attachmentIds), '?'));
        $stmt = $db->prepare(
            "SELECT stored_path FROM chat_message_attachments
             WHERE id IN ($place) AND session_id = ? AND kind = 'image'
             ORDER BY id ASC"
        );
        $stmt->execute(array_merge($attachmentIds, [$sessionId]));

        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$row['stored_path'], '/');
            if (is_file($abs)) $paths[] = $abs;
        }
        if (!$paths) return $empty; // 画像添付なし（PDF等のみ）→ 通常フローへ

        $res = propertyExtractFromImages($paths);
        $fields = is_array($res['fields'] ?? null) ? $res['fields'] : [];
        $building = trim((string)($fields['building_name'] ?? ''));
        $address  = trim((string)($fields['address'] ?? ''));

        return [
            'has_image'  => true,
            'identified' => ($building !== '' || $address !== ''),
            'fields'     => $fields,
            'error'      => $res['error'] ?? null,
        ];
    }
}

if (!function_exists('chatBuildImagePropertyContext')) {
    /**
     * 添付画像から抽出した物件情報を、AIプロンプトへ最優先で注入するブロックを生成する。
     * 抽出できた項目だけを列挙し、無い項目は書かない（推測させない）。
     * 顧客向けチャットのため、担当のみ（seller_* 等 agent_only）の項目は絶対に含めない。
     */
    function chatBuildImagePropertyContext(array $fields): string
    {
        $typeMap = ['mansion' => 'マンション', 'house' => '一戸建て', 'land' => '土地'];
        $lines = [];

        $ptype = trim((string)($fields['property_type'] ?? ''));
        if ($ptype !== '') {
            $lines[] = '・物件種別: ' . ($typeMap[$ptype] ?? $ptype);
        }
        // propertyFieldDefs の並び順で、顧客に出してよい（agent_only=false）項目のみ列挙する。
        foreach (propertyFieldDefs() as $f) {
            list($key, $label, , , $agentOnly) = array_pad($f, 5, null);
            if ($agentOnly) continue;
            $v = trim((string)($fields[$key] ?? ''));
            if ($v === '') continue;
            $lines[] = '・' . $label . ': ' . $v;
        }
        if (!$lines) return '';

        return "【添付画像から読み取った物件情報（今回の質問の対象物件・最優先）】\n"
            . implode("\n", $lines) . "\n"
            . "※お客様は、この添付画像に写っている上記の物件について質問しています。"
            . "過去の会話に出てきた別の物件と混同せず、必ずこの物件を前提に回答してください。"
            . "上記に記載が無い事項（写っていない住所・築年・価格・設備など）は、"
            . "別途の参照データで確認できない限り、推測で答えないでください。";
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
            // アットホーム等はbot判定のUser-Agentを405で弾くため、実ブラウザ相当のUA・ヘッダーで取得する。
            // （SUUMO/HOME'S/Yahoo!等は従来通り取得でき、UAでbotを拒否する媒体も救済される）
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ja,en-US;q=0.9,en;q=0.8',
            ],
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
            'url' => propertyImageBaseUrl() . $id,
        ];
    }
}

if (!function_exists('propertyGsBinary')) {
    /** Ghostscript 実行ファイルのパスを返す（無ければ null）。 */
    function propertyGsBinary(): ?string
    {
        foreach (['gs', '/usr/bin/gs', '/usr/local/bin/gs'] as $cand) {
            $out = @shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null');
            if ($out && trim($out) !== '') return trim($out);
        }
        return is_file('/usr/bin/gs') ? '/usr/bin/gs' : null;
    }
}

if (!function_exists('propertyRasterizePdfToJpeg')) {
    /**
     * PDF先頭ページを Ghostscript で JPEG にラスタライズし、出力パスを返す（失敗時 null）。
     * Imagick 非搭載環境向け。
     */
    function propertyRasterizePdfToJpeg(string $pdfPath, ?string $outPath = null, int $dpi = 150): ?string
    {
        $gs = propertyGsBinary();
        if (!$gs || !is_file($pdfPath)) return null;
        if ($outPath === null) $outPath = tempnam(sys_get_temp_dir(), 'prop_pdf_') . '.jpg';
        $cmd = escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=1'
            . ' -sDEVICE=jpeg -dJPEGQ=88 -r' . (int)$dpi . ' -dUseCropBox'
            . ' -sOutputFile=' . escapeshellarg($outPath) . ' ' . escapeshellarg($pdfPath) . ' 2>/dev/null';
        @shell_exec($cmd);
        return (is_file($outPath) && filesize($outPath) > 0) ? $outPath : null;
    }
}

if (!function_exists('propertyRasterizePdfFirstPage')) {
    /** PDF先頭ページを一時JPEGに変換しパスを返す（OCR用）。Ghostscript 優先・Imagick代替可。失敗時 null。 */
    function propertyRasterizePdfFirstPage(string $pdfPath): ?string
    {
        $jpg = propertyRasterizePdfToJpeg($pdfPath, null, 150);
        if ($jpg) return $jpg;
        if (class_exists('Imagick')) {
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
            } catch (Throwable $e) { return null; }
        }
        return null;
    }
}

/* ──────────────────────────────────────────────────────────
 * 販売図面マスク機能（売主仲介会社情報の自動非表示）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('propertyFlyerBottomBandRegions')) {
    /**
     * 既定の提案マスク範囲。販売図面の多くは A4横（297×210mm）で、下段に売主情報がある。
     * A4横の下3cm = 30/210 ≈ 14.3% を全幅でデフォルト白抜きにする。
     */
    function propertyFlyerBottomBandRegions(): array
    {
        // 正規化座標（左上原点, 0..1）。下端 約14.3%（A4横の下3cm相当）・全幅。
        return [['x' => 0.0, 'y' => 0.857, 'w' => 1.0, 'h' => 0.143]];
    }
}

if (!function_exists('propertyFlyerMakePreview')) {
    /**
     * 販売図面（画像/PDF）から編集・マスク用のプレビューJPEGを生成し、保存する。
     * 返り値 ['rel'=>相対パス, 'abs'=>絶対パス, 'width'=>, 'height'=>] / 失敗時 null。
     */
    function propertyFlyerMakePreview(string $originalAbsPath, bool $isPdf, int $businessCardId, int $propertyId): ?array
    {
        $relDir = 'property/' . $businessCardId . '/' . $propertyId;
        $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) return null;
        $stored = 'preview_' . bin2hex(random_bytes(8)) . '.jpg';
        $absPath = $absDir . '/' . $stored;
        $relPath = $relDir . '/' . $stored;

        if ($isPdf) {
            $tmp = propertyRasterizePdfFirstPage($originalAbsPath);
            if (!$tmp) return null;
            // 長辺1600pxへ縮小して保存
            if (!propertyRescaleJpeg($tmp, $absPath, 1600)) { @copy($tmp, $absPath); }
            @unlink($tmp);
        } else {
            // 画像はそのまま長辺1600pxのJPEGに正規化
            if (!propertyRescaleAnyToJpeg($originalAbsPath, $absPath, 1600)) return null;
        }
        $size = @getimagesize($absPath);
        if ($size === false) return null;
        return ['rel' => $relPath, 'abs' => $absPath, 'width' => (int)$size[0], 'height' => (int)$size[1]];
    }
}

if (!function_exists('propertyRescaleJpeg')) {
    /** JPEGを長辺 $maxEdge に縮小して $outPath へ保存（GD）。 */
    function propertyRescaleJpeg(string $srcJpeg, string $outPath, int $maxEdge): bool
    {
        return propertyRescaleAnyToJpeg($srcJpeg, $outPath, $maxEdge);
    }
}

if (!function_exists('propertyRescaleAnyToJpeg')) {
    /** 画像（jpeg/png/gif/webp）を読み込み、長辺 $maxEdge 以内のJPEGとして $outPath に保存（GD）。 */
    function propertyRescaleAnyToJpeg(string $srcPath, string $outPath, int $maxEdge, int $quality = 88): bool
    {
        if (!function_exists('imagecreatetruecolor')) return false;
        $info = @getimagesize($srcPath);
        if ($info === false) return false;
        $mime = $info['mime'] ?? '';
        switch ($mime) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($srcPath); break;
            case 'image/png':  $src = @imagecreatefrompng($srcPath); break;
            case 'image/gif':  $src = @imagecreatefromgif($srcPath); break;
            case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false; break;
            default: $src = false;
        }
        if (!$src) return false;
        $w = imagesx($src); $h = imagesy($src);
        $scale = ($w > $maxEdge || $h > $maxEdge) ? ($maxEdge / max($w, $h)) : 1.0;
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // 透過を白で塗りつぶし
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagejpeg($dst, $outPath, $quality);
        imagedestroy($src); imagedestroy($dst);
        return (bool)$ok;
    }
}

if (!function_exists('propertyFlyerDetectRegions')) {
    /**
     * プレビューJPEGをOpenAI Visionで解析し、売主仲介会社情報（会社名/住所/電話/QR/「物件確認はこちら」等）の
     * マスク提案範囲（正規化矩形配列）を返す。検出できなければ下端帯を返す。
     * @return array ['regions'=>[['x','y','w','h'],...], 'note'=>string]
     */
    function propertyFlyerDetectRegions(string $previewAbsPath, array $options = []): array
    {
        $fallback = ['regions' => propertyFlyerBottomBandRegions(), 'note' => 'auto-bottom'];
        if (!is_file($previewAbsPath) || !function_exists('curl_init')) return $fallback;
        $data = @file_get_contents($previewAbsPath);
        if ($data === false) return $fallback;

        $prompt = "あなたは不動産販売図面の画像を解析するアシスタントです。\n"
            . "画像内で『売主仲介会社（広告主）の情報』が記載されている領域を全て特定してください。\n"
            . "対象: 会社名・店舗/支店名・住所・電話番号・FAX・免許番号・QRコード・ロゴ・『物件確認はこちら』『お問い合わせ』等の広告/連絡先ブロック。\n"
            . "対象外: 物件そのものの情報（価格・間取り・所在地・交通・面積・現況など物件説明）。\n"
            . "多くの販売図面では、これらは図面の下段にまとまっています。\n"
            . "各領域を、画像左上を原点(0,0)・右下を(1,1)とした正規化座標の矩形 {x, y, w, h}（x,y=左上, w,h=幅高さ, 0〜1の小数）で返してください。\n"
            . "出力は JSON のみ: {\"found\": true/false, \"regions\": [{\"x\":..,\"y\":..,\"w\":..,\"h\":..}, ...]}\n"
            . "確信が持てない場合は found=false とし regions は空配列。説明文やコードフェンスは付けないこと。";

        $content = [
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($data)]],
        ];
        $model = propertyFlyerModel();
        $apiKey = chatOpenAIApiKeyForModel($model);
        $res = callOpenAIChat([['role' => 'user', 'content' => $content]], $apiKey, $model, [
            'purpose' => 'property_flyer_mask', 'max_tokens' => 500, 'temperature' => 0.0, 'timeout' => 45,
        ] + $options);
        if (!empty($res['error']) || empty($res['reply'])) return $fallback;

        $reply = trim($res['reply']);
        $reply = preg_replace('/^```[a-zA-Z]*\s*/', '', $reply);
        $reply = preg_replace('/```$/', '', trim($reply));
        $s = strpos($reply, '{'); $e = strrpos($reply, '}');
        if ($s === false || $e === false) return $fallback;
        $parsed = json_decode(substr($reply, $s, $e - $s + 1), true);
        if (!is_array($parsed)) return $fallback;

        $regions = [];
        foreach (($parsed['regions'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $x = isset($r['x']) ? (float)$r['x'] : null;
            $y = isset($r['y']) ? (float)$r['y'] : null;
            $wd = isset($r['w']) ? (float)$r['w'] : null;
            $ht = isset($r['h']) ? (float)$r['h'] : null;
            if ($x === null || $y === null || $wd === null || $ht === null) continue;
            $reg = propertyClampRegion($x, $y, $wd, $ht);
            if ($reg) $regions[] = $reg;
        }
        if (empty($regions)) return $fallback;
        return ['regions' => $regions, 'note' => 'ai'];
    }
}

if (!function_exists('propertyMaskRegionsByPage')) {
    /** 保存済 mask_regions（旧:平坦配列 / 新:ページ別オブジェクト）を [pageIndex => [regions]] に正規化。 */
    function propertyMaskRegionsByPage($stored): array
    {
        if (is_string($stored)) $stored = json_decode($stored, true);
        if (!is_array($stored) || !$stored) return [];
        $first = reset($stored);
        if (is_array($first) && isset($first['x'])) { // 旧形式: 領域の平坦配列＝ページ0
            return [0 => array_values(array_filter($stored, 'is_array'))];
        }
        $out = [];
        foreach ($stored as $k => $v) {
            if (is_array($v)) $out[(int)$k] = array_values(array_filter($v, 'is_array'));
        }
        return $out;
    }
}

if (!function_exists('propertyClampRegion')) {
    /** 正規化矩形を 0..1 に丸め、極端に小さい矩形を除外。無効なら null。 */
    function propertyClampRegion($x, $y, $w, $h): ?array
    {
        $x = max(0.0, min(1.0, (float)$x));
        $y = max(0.0, min(1.0, (float)$y));
        $w = max(0.0, min(1.0 - $x, (float)$w));
        $h = max(0.0, min(1.0 - $y, (float)$h));
        if ($w < 0.02 || $h < 0.01) return null;
        return ['x' => round($x, 4), 'y' => round($y, 4), 'w' => round($w, 4), 'h' => round($h, 4)];
    }
}

if (!function_exists('propertyFlyerApplyMask')) {
    /**
     * プレビューJPEGに正規化矩形を黒塗りしたマスク済JPEGを作り、PDFに包んで保存する。
     * @return array ['rel_pdf'=>, 'abs_pdf'=>, 'byte_size'=>, 'width'=>, 'height'=>] / 失敗時 null
     */
    function propertyFlyerApplyMask(string $previewAbsPath, array $regions, int $businessCardId, int $propertyId): ?array
    {
        if (!function_exists('imagecreatefromjpeg')) return null;
        $img = @imagecreatefromjpeg($previewAbsPath);
        if (!$img) return null;
        $w = imagesx($img); $h = imagesy($img);
        $fill = imagecolorallocate($img, 255, 255, 255); // 白べた（塗りつぶし感を抑える）
        foreach ($regions as $r) {
            $reg = propertyClampRegion($r['x'] ?? 0, $r['y'] ?? 0, $r['w'] ?? 0, $r['h'] ?? 0);
            if (!$reg) continue;
            $x0 = (int)round($reg['x'] * $w);
            $y0 = (int)round($reg['y'] * $h);
            $x1 = (int)round(($reg['x'] + $reg['w']) * $w);
            $y1 = (int)round(($reg['y'] + $reg['h']) * $h);
            imagefilledrectangle($img, $x0, $y0, $x1, $y1, $fill);
        }
        $relDir = 'property/' . $businessCardId . '/' . $propertyId;
        $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) { imagedestroy($img); return null; }

        $maskedJpg = $absDir . '/masked_' . bin2hex(random_bytes(8)) . '.jpg';
        $ok = imagejpeg($img, $maskedJpg, 88);
        imagedestroy($img);
        if (!$ok) return null;

        $pdfName = 'masked_' . bin2hex(random_bytes(8)) . '.pdf';
        $absPdf = $absDir . '/' . $pdfName;
        $relPdf = $relDir . '/' . $pdfName;
        if (!propertyJpegToPdf($maskedJpg, $absPdf, $w, $h)) { @unlink($maskedJpg); return null; }
        @unlink($maskedJpg);
        return ['rel_pdf' => $relPdf, 'abs_pdf' => $absPdf, 'byte_size' => filesize($absPdf) ?: 0, 'width' => $w, 'height' => $h];
    }
}

if (!function_exists('propertyJpegToPdf')) {
    /**
     * 1枚のJPEGを埋め込んだ単一ページPDFを生成する（外部ライブラリ不要・DCTDecodeで再エンコードなし）。
     * ページサイズ＝画像ピクセル相当（72dpi）。
     */
    function propertyJpegToPdf(string $jpegPath, string $pdfPath, int $w = 0, int $h = 0): bool
    {
        return propertyMultiJpegToPdf([$jpegPath], $pdfPath);
    }
}

if (!function_exists('propertyMultiJpegToPdf')) {
    /**
     * 複数のJPEGを各ページに埋め込んだ複数ページPDFを生成する（外部ライブラリ不要・DCTDecode）。
     * ページサイズ＝各画像ピクセル相当（72dpi）。
     */
    function propertyMultiJpegToPdf(array $jpegPaths, string $pdfPath): bool
    {
        $pages = [];
        foreach ($jpegPaths as $p) {
            $data = @file_get_contents($p);
            if ($data === false || $data === '') continue;
            $info = @getimagesize($p);
            if ($info === false) continue;
            $channels = isset($info['channels']) ? (int)$info['channels'] : 3;
            $pages[] = [
                'data' => $data, 'w' => (int)$info[0], 'h' => (int)$info[1],
                'cs' => $channels === 4 ? '/DeviceCMYK' : ($channels === 1 ? '/DeviceGray' : '/DeviceRGB'),
                'bits' => isset($info['bits']) ? (int)$info['bits'] : 8,
                'len' => strlen($data),
            ];
        }
        if (!$pages) return false;
        $n = count($pages);
        $objCount = 2 + $n * 3;

        // オブジェクト番号割当: 1=Catalog, 2=Pages, 以降 各ページ(img, content, page)
        $num = 3; $perPage = []; $kidsNums = [];
        foreach ($pages as $i => $_) {
            $img = $num++; $content = $num++; $page = $num++;
            $perPage[$i] = ['img' => $img, 'content' => $content, 'page' => $page];
            $kidsNums[] = $page;
        }
        $kids = implode(' ', array_map(fn($k) => "$k 0 R", $kidsNums));

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $offsets[1] = strlen($pdf); $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offsets[2] = strlen($pdf); $pdf .= "2 0 obj\n<< /Type /Pages /Kids [$kids] /Count $n >>\nendobj\n";
        foreach ($pages as $i => $pg) {
            $pp = $perPage[$i];
            $imgHeader = "<< /Type /XObject /Subtype /Image /Width {$pg['w']} /Height {$pg['h']} /ColorSpace {$pg['cs']} /BitsPerComponent {$pg['bits']} /Filter /DCTDecode /Length {$pg['len']} >>";
            $offsets[$pp['img']] = strlen($pdf);
            $pdf .= "{$pp['img']} 0 obj\n{$imgHeader}\nstream\n" . $pg['data'] . "\nendstream\nendobj\n";
            $content = "q\n{$pg['w']} 0 0 {$pg['h']} 0 0 cm\n/Im0 Do\nQ\n";
            $offsets[$pp['content']] = strlen($pdf);
            $pdf .= "{$pp['content']} 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";
            $pageBody = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pg['w']} {$pg['h']}] /Resources << /XObject << /Im0 {$pp['img']} 0 R >> >> /Contents {$pp['content']} 0 R >>";
            $offsets[$pp['page']] = strlen($pdf);
            $pdf .= "{$pp['page']} 0 obj\n{$pageBody}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $size = $objCount + 1;
        $xref = "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i <= $objCount; $i++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= $xref;
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        return (bool)@file_put_contents($pdfPath, $pdf);
    }
}

if (!function_exists('propertyApplyMaskToJpeg')) {
    /** JPEGに正規化矩形を白べたで塗って $outPath に保存（GD）。成功で true。 */
    function propertyApplyMaskToJpeg(string $srcJpeg, array $regions, string $outPath): bool
    {
        if (!function_exists('imagecreatefromjpeg')) return false;
        $img = @imagecreatefromjpeg($srcJpeg);
        if (!$img) return false;
        $w = imagesx($img); $h = imagesy($img);
        $fill = imagecolorallocate($img, 255, 255, 255); // 白べた（塗りつぶし感を抑える）
        foreach ($regions as $r) {
            $reg = propertyClampRegion($r['x'] ?? 0, $r['y'] ?? 0, $r['w'] ?? 0, $r['h'] ?? 0);
            if (!$reg) continue;
            imagefilledrectangle($img,
                (int)round($reg['x'] * $w), (int)round($reg['y'] * $h),
                (int)round(($reg['x'] + $reg['w']) * $w), (int)round(($reg['y'] + $reg['h']) * $h), $fill);
        }
        $ok = imagejpeg($img, $outPath, 88);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('propertyFlyerModel')) {
    /**
     * 販売図面のOCR・画像認識・写真/間取り領域の検出と分類に使うVisionモデル。
     * OPENAI_MODEL_FLYER で上書き可。
     */
    function propertyFlyerModel(): string
    {
        $m = trim((string)getenv('OPENAI_MODEL_FLYER'));
        // 空・または提供終了済みの旧モデル指定は販売図面用の既定モデルに矯正する。
        if ($m === '' || stripos($m, 'gpt-4.5') !== false) $m = 'gpt-5.4-mini';
        return $m;
    }
}

if (!function_exists('propertyFlyerSaveableCategories')) {
    /** 「写真・資料」へ保存する分類（その他は売主情報を含む可能性があるため除外）。 */
    function propertyFlyerSaveableCategories(): array
    {
        return ['建物外観', '間取り図', '室内写真', '設備写真', '地図'];
    }
}

if (!function_exists('propertyRasterizePdfAllPages')) {
    /** PDF全ページ（最大 $maxPages）を Ghostscript で JPEG にラスタライズ。ファイルパス配列を返す（呼び出し側で削除）。 */
    function propertyRasterizePdfAllPages(string $pdfPath, int $maxPages = 6, int $dpi = 150): array
    {
        $gs = propertyGsBinary();
        if (!$gs || !is_file($pdfPath)) return [];
        $dir = rtrim(sys_get_temp_dir(), '/') . '/prop_pg_' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return [];
        $out = $dir . '/page-%03d.jpg';
        $cmd = escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=' . (int)$maxPages
            . ' -sDEVICE=jpeg -dJPEGQ=88 -r' . (int)$dpi . ' -dUseCropBox'
            . ' -sOutputFile=' . escapeshellarg($out) . ' ' . escapeshellarg($pdfPath) . ' 2>/dev/null';
        @shell_exec($cmd);
        $files = glob($dir . '/page-*.jpg') ?: [];
        sort($files);
        return $files;
    }
}

if (!function_exists('propertyCropRegionToJpeg')) {
    /** ページ画像から正規化矩形領域を切り出し、長辺 $maxEdge のJPEGとして保存（GD）。 */
    function propertyCropRegionToJpeg(string $pageAbs, array $region, string $outAbs, int $maxEdge = 1280): bool
    {
        if (!function_exists('imagecreatefromjpeg')) return false;
        $src = @imagecreatefromjpeg($pageAbs);
        if (!$src) return false;
        $W = imagesx($src); $H = imagesy($src);
        $pad = 0.008;
        $x = max(0.0, ($region['x'] ?? 0) - $pad);
        $y = max(0.0, ($region['y'] ?? 0) - $pad);
        $w = min(1.0 - $x, ($region['w'] ?? 0) + 2 * $pad);
        $h = min(1.0 - $y, ($region['h'] ?? 0) + 2 * $pad);
        $px = (int)round($x * $W); $py = (int)round($y * $H);
        $pw = (int)round($w * $W); $ph = (int)round($h * $H);
        if ($pw < 16 || $ph < 16) { imagedestroy($src); return false; }
        $scale = ($pw > $maxEdge || $ph > $maxEdge) ? ($maxEdge / max($pw, $ph)) : 1.0;
        $nw = max(1, (int)round($pw * $scale)); $nh = max(1, (int)round($ph * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, $px, $py, $nw, $nh, $pw, $ph);
        $ok = imagejpeg($dst, $outAbs, 85);
        imagedestroy($src); imagedestroy($dst);
        return (bool)$ok;
    }
}

if (!function_exists('propertyFlyerDrawGrid')) {
    /**
     * ページ画像に列・行番号つきの格子を重ねたJPEGを生成する（GPTの位置推定補助用）。
     * Visionモデルは自由な小数座標より「見えている格子セル」の指定の方が精度が高い。
     * 実際の切り出しは格子なしの原本から行うため、この画像はGPT入力専用。
     * $cols/$rows は参照渡しで、実際に描いたセル数を返す。
     */
    function propertyFlyerDrawGrid(string $srcJpeg, string $outJpeg, int &$cols, int &$rows): bool
    {
        if (!function_exists('imagecreatefromjpeg')) return false;
        $src = @imagecreatefromjpeg($srcJpeg);
        if (!$src) return false;
        $W = imagesx($src); $H = imagesy($src);
        if ($W < 8 || $H < 8) { imagedestroy($src); return false; }
        $cols = 16;
        $rows = max(8, min(28, (int)round($cols * $H / max(1, $W))));
        $red = imagecolorallocate($src, 255, 0, 0);
        for ($i = 1; $i < $cols; $i++) { $x = (int)round($i * $W / $cols); imageline($src, $x, 0, $x, $H, $red); }
        for ($j = 1; $j < $rows; $j++) { $y = (int)round($j * $H / $rows); imageline($src, 0, $y, $W, $y, $red); }
        // 列番号（上端）・行番号（左端）を各セルに描画
        for ($i = 0; $i < $cols; $i++) { imagestring($src, 3, (int)round(($i + 0.30) * $W / $cols) + 1, 1, (string)($i + 1), $red); }
        for ($j = 0; $j < $rows; $j++) { imagestring($src, 3, 1, (int)round(($j + 0.30) * $H / $rows) + 1, (string)($j + 1), $red); }
        $ok = imagejpeg($src, $outJpeg, 90);
        imagedestroy($src);
        return (bool)$ok;
    }
}

if (!function_exists('propertyFlyerAnalyzePage')) {
    /**
     * 販売図面の1ページ画像を販売図面用Visionモデルで解析し、
     *  - 個々の写真・図版領域の分類（建物外観/間取り図/室内写真/設備写真/地図/その他）
     *  - 顧客用PDFで黒塗りすべき売主仲介会社情報の領域（mask_regions）
     * を返す。失敗時は items=[] / mask=下端帯。
     * @return array ['items'=>[['category','x','y','w','h','thumbnail_candidate'],...], 'mask_regions'=>[['x','y','w','h'],...]]
     */
    function propertyFlyerAnalyzePage(string $pageAbsPath, array $options = []): array
    {
        $fallback = ['items' => [], 'mask_regions' => propertyFlyerBottomBandRegions()];
        if (!is_file($pageAbsPath) || !function_exists('curl_init')) return $fallback;

        // 位置推定の精度を上げるため、列・行番号つきの格子を重ねた画像をGPTへ渡し、
        // 各写真・図版の位置を「セル範囲（列c1-c2 / 行r1-r2）」で答えさせる。
        // 自由な小数座標だと間取り図等を大きく取り違えるため、セル指定に統一する。
        // 切り出しは格子なしの原本から行う（この格子画像はGPT入力専用）。
        $cols = 16; $rows = 12;
        $gridPath = tempnam(sys_get_temp_dir(), 'prop_grid_') . '.jpg';
        $useGrid = propertyFlyerDrawGrid($pageAbsPath, $gridPath, $cols, $rows);
        $imgPath = $useGrid ? $gridPath : $pageAbsPath;
        $data = @file_get_contents($imgPath);
        if ($data === false) { if ($useGrid) @unlink($gridPath); return $fallback; }
        $b64 = 'data:image/jpeg;base64,' . base64_encode($data);

        if ($useGrid) {
            $prompt = "あなたは日本の不動産販売図面（チラシ）画像を解析するアシスタントです。\n"
                . "画像には赤い格子（列1〜{$cols}を上端、行1〜{$rows}を左端に番号表示）を重ねています。\n"
                . "この図面に含まれる個々の写真・図版を検出し、それぞれ次のいずれかに分類してください:\n"
                . "建物外観 / 間取り図 / 室内写真 / 設備写真 / 地図 / その他\n"
                . "「その他」= 会社ロゴ・QRコード・会社名・住所・電話/FAX番号・担当者情報・物件確認QR・アイコン・広告等（売主仲介会社情報を含む可能性のあるもの）。\n"
                . "各写真・図版の位置は、その絵柄が占める格子セル範囲を、列開始c1・行開始r1・列終了c2・行終了r2（いずれも1以上の整数、c1≦c2, r1≦r2）で答えてください。\n"
                . "【重要】絵柄が実際に写っているセルだけを含め、周囲の見出し文字・余白・キャッチコピー・価格帯のセルは含めないでください。実在する写真・図版だけを返し、無い場合や位置が曖昧な場合はその項目を出力しないこと（無理に作らない）。\n"
                . "建物外観が複数ある場合は、建物全体が最も分かりやすい1枚にのみ thumbnail_candidate=true を付けてください。\n"
                . "さらに、顧客に渡すPDFでマスク（非表示に）すべき『売主仲介会社情報』（会社名・住所・電話・FAX・QRコード・『物件確認はこちら』『お問い合わせ』等）の領域も同じセル範囲形式で mask_regions として返してください。\n"
                . "出力はJSONのみ: {\"items\":[{\"category\":\"..\",\"c1\":..,\"r1\":..,\"c2\":..,\"r2\":..,\"thumbnail_candidate\":true}], \"mask_regions\":[{\"c1\":..,\"r1\":..,\"c2\":..,\"r2\":..}]}\n"
                . "説明文やコードフェンスは付けないこと。";
        } else {
            $prompt = "あなたは日本の不動産販売図面（チラシ）画像を解析するアシスタントです。\n"
                . "この画像に含まれる個々の写真・図版の領域を検出し、それぞれ次のいずれかに分類してください:\n"
                . "建物外観 / 間取り図 / 室内写真 / 設備写真 / 地図 / その他\n"
                . "「その他」= 会社ロゴ・QRコード・会社名・住所・電話/FAX番号・担当者情報・物件確認QR・アイコン・広告等（売主仲介会社情報を含む可能性のあるもの）。\n"
                . "各領域は、画像左上を(0,0)・右下を(1,1)とした正規化矩形 {x,y,w,h}（0〜1の小数、小数第3位まで）で示してください。写真の外側の余白・見出し文字・キャッチコピーは含めず、絵柄だけをタイトに囲うこと。実在する写真だけを返し、曖昧なら出力しないこと。\n"
                . "建物外観が複数ある場合は、建物全体が最も分かりやすい1枚にのみ thumbnail_candidate=true を付けてください。\n"
                . "さらに、顧客に渡すPDFでマスクすべき『売主仲介会社情報』の領域を mask_regions として返してください。\n"
                . "出力はJSONのみ: {\"items\":[{\"category\":\"..\",\"x\":..,\"y\":..,\"w\":..,\"h\":..,\"thumbnail_candidate\":true}], \"mask_regions\":[{\"x\":..,\"y\":..,\"w\":..,\"h\":..}]}\n"
                . "説明文やコードフェンスは付けないこと。";
        }
        $messages = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image_url', 'image_url' => ['url' => $b64]],
        ]]];

        $tryModels = [propertyFlyerModel()];
        $reply = null;
        foreach ($tryModels as $model) {
            $apiKey = chatOpenAIApiKeyForModel($model);
            $res = callOpenAIChat($messages, $apiKey, $model, [
                'purpose' => 'property_flyer_analyze', 'max_tokens' => 900, 'temperature' => 0.0, 'timeout' => 60,
            ] + $options);
            if (empty($res['error']) && !empty($res['reply'])) { $reply = $res['reply']; break; }
            error_log('property flyer analyze model ' . $model . ' failed: ' . ($res['error'] ?? 'empty'));
        }
        if ($useGrid) @unlink($gridPath);
        if ($reply === null) return $fallback;

        // セル範囲（1始まり・両端含む）→ 正規化矩形 {x,y,w,h} へ変換する。
        $cellToRegion = function ($c1, $r1, $c2, $r2) use ($cols, $rows) {
            $c1 = (int)$c1; $r1 = (int)$r1; $c2 = (int)$c2; $r2 = (int)$r2;
            if ($c2 < $c1) { $t = $c1; $c1 = $c2; $c2 = $t; }
            if ($r2 < $r1) { $t = $r1; $r1 = $r2; $r2 = $t; }
            $c1 = max(1, min($cols, $c1)); $c2 = max(1, min($cols, $c2));
            $r1 = max(1, min($rows, $r1)); $r2 = max(1, min($rows, $r2));
            return propertyClampRegion(($c1 - 1) / $cols, ($r1 - 1) / $rows, ($c2 - $c1 + 1) / $cols, ($r2 - $r1 + 1) / $rows);
        };
        $regionFromEntry = function ($e) use ($useGrid, $cellToRegion) {
            if (!is_array($e)) return null;
            if ($useGrid && isset($e['c1'], $e['r1'], $e['c2'], $e['r2'])) {
                return $cellToRegion($e['c1'], $e['r1'], $e['c2'], $e['r2']);
            }
            return propertyClampRegion($e['x'] ?? null, $e['y'] ?? null, $e['w'] ?? null, $e['h'] ?? null);
        };

        $reply = trim($reply);
        $reply = preg_replace('/^```[a-zA-Z]*\s*/', '', $reply);
        $reply = preg_replace('/```$/', '', trim($reply));
        $s = strpos($reply, '{'); $e = strrpos($reply, '}');
        if ($s === false || $e === false) return $fallback;
        $parsed = json_decode(substr($reply, $s, $e - $s + 1), true);
        if (!is_array($parsed)) return $fallback;

        $valid = propertyFlyerSaveableCategories();
        $items = [];
        foreach (($parsed['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $cat = trim((string)($it['category'] ?? ''));
            $reg = $regionFromEntry($it);
            if (!$reg) continue;
            $items[] = [
                'category' => $cat,
                'x' => $reg['x'], 'y' => $reg['y'], 'w' => $reg['w'], 'h' => $reg['h'],
                'thumbnail_candidate' => !empty($it['thumbnail_candidate']),
                'saveable' => in_array($cat, $valid, true),
            ];
        }
        $mask = [];
        foreach (($parsed['mask_regions'] ?? []) as $r) {
            $reg = $regionFromEntry($r);
            // 図面の大半を覆う領域（誤検出）は除外。売主情報は通常ページの一部のみ。
            if ($reg && ($reg['w'] * $reg['h']) <= 0.7) $mask[] = $reg;
        }
        // 売主情報マスクが検出できなければ、その他領域＋下端帯を保険にする
        if (empty($mask)) {
            foreach ($items as $it) {
                if (!$it['saveable'] && ($it['w'] * $it['h']) <= 0.7) $mask[] = ['x' => $it['x'], 'y' => $it['y'], 'w' => $it['w'], 'h' => $it['h']];
            }
            if (empty($mask)) $mask = propertyFlyerBottomBandRegions();
        }
        return ['items' => $items, 'mask_regions' => $mask];
    }
}

if (!function_exists('propertyFlyerPageImages')) {
    /**
     * 販売図面（画像/PDF）からページ画像（JPEG絶対パス配列）と、その一時ディレクトリを返す。
     * @return array ['pages'=>[abs,...], 'tmp'=>[削除対象パス...]]
     */
    function propertyFlyerPageImages(string $originalAbsPath, bool $isPdf, int $maxPages = 6): array
    {
        if ($isPdf) {
            $pages = propertyRasterizePdfAllPages($originalAbsPath, $maxPages, 150);
            return ['pages' => $pages, 'tmp' => $pages];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'prop_pg_') . '.jpg';
        if (!propertyRescaleAnyToJpeg($originalAbsPath, $tmp, 2000)) { @copy($originalAbsPath, $tmp); }
        return ['pages' => [$tmp], 'tmp' => [$tmp]];
    }
}

if (!function_exists('propertyFlyerBuildCustomerPdf')) {
    /**
     * ページ画像配列を、ページ毎の売主マスク領域で白塗りし、複数ページの顧客用PDFを生成する。
     * 併せて1ページ目のマスク済ラスタ画像（顧客サムネイル＆ビューア用）を永続保存する。
     * @param array $pageAbs  ページJPEG絶対パス配列
     * @param array $maskByPage  [pageIndex => [{x,y,w,h},...]]
     * @return array  ['pdf'=>相対パス|null, 'thumb'=>相対パス|null]
     */
    function propertyFlyerBuildCustomerPdf(array $pageAbs, array $maskByPage, int $businessCardId, int $propertyId): array
    {
        $relDir = 'property/' . $businessCardId . '/' . $propertyId;
        $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) return ['pdf' => null, 'thumb' => null];
        $maskedPages = [];
        foreach ($pageAbs as $i => $page) {
            $regions = $maskByPage[$i] ?? [];
            if (empty($regions) && $i === 0) $regions = propertyFlyerBottomBandRegions();
            $mp = $absDir . '/maskpage_' . bin2hex(random_bytes(6)) . '.jpg';
            if (propertyApplyMaskToJpeg($page, $regions, $mp)) $maskedPages[] = $mp;
        }
        if (!$maskedPages) return ['pdf' => null, 'thumb' => null];

        // 顧客サムネイル＆ビューア用に1ページ目のマスク済画像を永続保存（長辺1400pxへ）
        $thumbRel = null;
        $thumbName = 'maskedthumb_' . bin2hex(random_bytes(8)) . '.jpg';
        if (propertyRescaleAnyToJpeg($maskedPages[0], $absDir . '/' . $thumbName, 1400, 85)) {
            $thumbRel = $relDir . '/' . $thumbName;
        }

        $pdfName = 'masked_' . bin2hex(random_bytes(8)) . '.pdf';
        $absPdf = $absDir . '/' . $pdfName;
        $ok = propertyMultiJpegToPdf($maskedPages, $absPdf);
        foreach ($maskedPages as $mp) @unlink($mp);
        return ['pdf' => $ok ? ($relDir . '/' . $pdfName) : null, 'thumb' => $thumbRel];
    }
}

/* ──────────────────────────────────────────────────────────
 * PDF埋め込み画像の直接抽出（poppler不要・純PHP）
 *  - /DCTDecode（JPEG）はストリームをそのままJPEGとして保存（原寸・劣化なし）
 *  - /FlateDecode（PNG系）はinflate＋PNGプレディクタ復元してGDで保存
 * ────────────────────────────────────────────────────────── */
if (!function_exists('propertyInflateStream')) {
    /** zlib/raw/gzip いずれかで展開を試みる。失敗時 null。 */
    function propertyInflateStream(string $data): ?string
    {
        $out = @gzuncompress($data); if ($out !== false && $out !== '') return $out; // zlib header
        $out = @gzinflate($data);    if ($out !== false && $out !== '') return $out; // raw deflate
        $out = @gzdecode($data);     if ($out !== false && $out !== '') return $out; // gzip
        return null;
    }
}

if (!function_exists('propertyPdfResolveLength')) {
    /** 間接参照 /Length N 0 R の整数値を本文から解決。失敗時 null。 */
    function propertyPdfResolveLength(string $raw, int $objNum): ?int
    {
        if (preg_match('/\b' . $objNum . '\s+0\s+obj\s+(\d+)/', $raw, $m)) return (int)$m[1];
        return null;
    }
}

if (!function_exists('propertyApplyPngPredictor')) {
    /** PNGプレディクタ（filterタイプ各行先頭1byte）を復元。bpp=1ピクセルあたりバイト数。失敗時 null。 */
    function propertyApplyPngPredictor(string $data, int $rowBytes, int $bpp): ?string
    {
        $stride = $rowBytes + 1;
        if ($stride <= 1) return null;
        $nrows = intdiv(strlen($data), $stride);
        if ($nrows <= 0) return null;
        $out = '';
        $prev = str_repeat("\0", $rowBytes);
        for ($r = 0; $r < $nrows; $r++) {
            $off = $r * $stride;
            $ft = ord($data[$off]);
            $row = substr($data, $off + 1, $rowBytes);
            if (strlen($row) < $rowBytes) $row = str_pad($row, $rowBytes, "\0");
            $rec = '';
            for ($i = 0; $i < $rowBytes; $i++) {
                $x = ord($row[$i]);
                $a = $i >= $bpp ? ord($rec[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                switch ($ft) {
                    case 0: $v = $x; break;
                    case 1: $v = $x + $a; break;
                    case 2: $v = $x + $b; break;
                    case 3: $v = $x + intdiv($a + $b, 2); break;
                    case 4:
                        $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                        $v = $x + $pr; break;
                    default: return null;
                }
                $rec .= chr($v & 0xFF);
            }
            $out .= $rec;
            $prev = $rec;
        }
        return $out;
    }
}

if (!function_exists('propertyBmp24FromRgb')) {
    /** トップダウンRGB（1byte×3×W×H）から24bit BMPバイナリを生成（GD imagecreatefromstring用）。 */
    function propertyBmp24FromRgb(string $rgb, int $W, int $H): string
    {
        $rowSize = $W * 3;
        $pad = (4 - ($rowSize % 4)) % 4;
        $padBytes = str_repeat("\0", $pad);
        $pixels = '';
        for ($y = $H - 1; $y >= 0; $y--) { // BMPは下から上
            $base = $y * $rowSize;
            $row = substr($rgb, $base, $rowSize);
            // RGB -> BGR
            $bgr = '';
            for ($x = 0; $x < $rowSize; $x += 3) {
                $bgr .= $row[$x + 2] . $row[$x + 1] . $row[$x];
            }
            $pixels .= $bgr . $padBytes;
        }
        $dataSize = strlen($pixels);
        $fileSize = 14 + 40 + $dataSize;
        $header = 'BM' . pack('VvvV', $fileSize, 0, 0, 54);
        $dib = pack('VllvvVVllVV', 40, $W, $H, 1, 24, 0, $dataSize, 2835, 2835, 0, 0);
        return $header . $dib . $pixels;
    }
}

if (!function_exists('propertyPdfImageToFile')) {
    /**
     * FlateDecode の生サンプルを RGB 化して JPEG 保存する（DeviceRGB / DeviceGray / Indexed[RGB] を対応）。
     * 失敗時 false。
     */
    function propertyPdfImageToFile(string $inflated, int $W, int $H, string $dict, string $raw, string $outPath): bool
    {
        // カラースペース判定
        $colors = 0; $palette = null;
        if (preg_match('#/ColorSpace\s*/DeviceRGB#', $dict)) { $colors = 3; }
        elseif (preg_match('#/ColorSpace\s*/DeviceGray#', $dict)) { $colors = 1; }
        elseif (preg_match('#/ColorSpace\s*\[\s*/Indexed\s*/DeviceRGB\s+(\d+)\s*([<(])#', $dict, $im)) {
            // インライン パレット（<hex> もしくは (literal)）。間接参照は非対応。
            $colors = 1;
            if ($im[2] === '<') {
                if (preg_match('#/Indexed\s*/DeviceRGB\s+\d+\s*<([0-9A-Fa-f\s]*)>#', $dict, $hx)) {
                    $hex = preg_replace('/\s+/', '', $hx[1]);
                    $bin = @hex2bin(strlen($hex) % 2 === 0 ? $hex : substr($hex, 0, -1));
                    $palette = $bin !== false ? str_split($bin, 3) : null;
                }
            }
            if ($palette === null) return false; // リテラルパレットは未対応
        } else {
            return false; // CMYK / ICCBased / 間接参照などは未対応
        }

        // プレディクタ
        $predictor = preg_match('#/Predictor\s+(\d+)#', $dict, $pm) ? (int)$pm[1] : 1;
        $pcolors = preg_match('#/DecodeParms[^>]*/Colors\s+(\d+)#', $dict, $cm) ? (int)$cm[1] : $colors;
        $columns = preg_match('#/DecodeParms[^>]*/Columns\s+(\d+)#', $dict, $col) ? (int)$col[1] : $W;
        if ($predictor >= 10) {
            $bpp = max(1, $pcolors); // 8bit前提
            $inflated = propertyApplyPngPredictor($inflated, $columns * $pcolors, $bpp);
            if ($inflated === null) return false;
        }

        $rowBytes = $W * $colors;
        if (strlen($inflated) < $rowBytes * $H) return false;

        // RGB 化
        $rgb = '';
        if ($colors === 3) {
            $rgb = substr($inflated, 0, $rowBytes * $H);
        } elseif ($colors === 1 && $palette === null) { // gray
            $px = $rowBytes * $H;
            for ($i = 0; $i < $px; $i++) { $g = $inflated[$i]; $rgb .= $g . $g . $g; }
        } else { // indexed
            $px = $W * $H;
            for ($i = 0; $i < $px; $i++) {
                $idx = ord($inflated[$i]);
                $c = $palette[$idx] ?? "\0\0\0";
                $rgb .= str_pad($c, 3, "\0");
            }
        }

        $bmp = propertyBmp24FromRgb($colors === 3 ? $rgb : $rgb, $W, $H);
        $img = @imagecreatefromstring($bmp);
        if (!$img) return false;
        // 長辺1280へ縮小
        $r = propertyRescaleGdToJpeg($img, $outPath, 1280, 85);
        imagedestroy($img);
        return $r;
    }
}

if (!function_exists('propertyRescaleGdToJpeg')) {
    /** GD画像を長辺 $maxEdge 以内のJPEGとして保存。 */
    function propertyRescaleGdToJpeg($img, string $outPath, int $maxEdge, int $quality = 85): bool
    {
        $w = imagesx($img); $h = imagesy($img);
        $scale = ($w > $maxEdge || $h > $maxEdge) ? ($maxEdge / max($w, $h)) : 1.0;
        if ($scale < 1.0) {
            $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = imagejpeg($dst, $outPath, $quality);
            imagedestroy($dst);
            return (bool)$ok;
        }
        return (bool)imagejpeg($img, $outPath, $quality);
    }
}

if (!function_exists('propertyExtractPdfImages')) {
    /**
     * PDFから埋め込み画像を直接抽出してJPEG群を $outDir に保存し、パス配列を返す（poppler不要）。
     * 小さすぎる画像（アイコン/ロゴ等）は除外。対応: DCTDecode / FlateDecode(8bit RGB/Gray/Indexed)。
     */
    function propertyExtractPdfImages(string $pdfAbs, string $outDir, int $minEdge = 200, int $maxImages = 40): array
    {
        $raw = @file_get_contents($pdfAbs);
        if ($raw === false || strlen($raw) < 100 || strncmp($raw, '%PDF', 4) !== 0) return [];
        if (!is_dir($outDir) && !@mkdir($outDir, 0700, true) && !is_dir($outDir)) return [];
        if (!preg_match_all('/(\d+)\s+(\d+)\s+obj\b/s', $raw, $mm, PREG_OFFSET_CAPTURE)) return [];
        $n = count($mm[0]);
        $saved = [];
        for ($i = 0; $i < $n && count($saved) < $maxImages; $i++) {
            $start = $mm[0][$i][1];
            $end = ($i + 1 < $n) ? $mm[0][$i + 1][1] : strlen($raw);
            $seg = substr($raw, $start, $end - $start);
            if (strpos($seg, '/Image') === false || !preg_match('#/Subtype\s*/Image#', $seg)) continue;
            if (preg_match('#/ImageMask\s+true#', $seg)) continue;
            $sp = strpos($seg, 'stream');
            if ($sp === false) continue;
            $dict = substr($seg, 0, $sp);
            if (!preg_match('#/Width\s+(\d+)#', $dict, $w) || !preg_match('#/Height\s+(\d+)#', $dict, $h)) continue;
            $W = (int)$w[1]; $H = (int)$h[1];
            if ($W < $minEdge || $H < $minEdge) continue; // 小さい画像（アイコン/ロゴ）は除外
            $bpc = preg_match('#/BitsPerComponent\s+(\d+)#', $dict, $bb) ? (int)$bb[1] : 8;
            $filter = preg_match('#/Filter\s*(/[A-Za-z0-9]+|\[[^\]]*\])#', $dict, $ff) ? $ff[1] : '';

            // ストリームデータの開始位置（streamキーワード後のEOLをスキップ）
            $dataStart = $sp + 6;
            if (substr($seg, $dataStart, 2) === "\r\n") $dataStart += 2;
            elseif (isset($seg[$dataStart]) && ($seg[$dataStart] === "\n" || $seg[$dataStart] === "\r")) $dataStart += 1;

            $len = null;
            if (preg_match('#/Length\s+(\d+)\s+(\d+)\s+R#', $dict, $lr)) $len = propertyPdfResolveLength($raw, (int)$lr[1]);
            elseif (preg_match('#/Length\s+(\d+)#', $dict, $ll)) $len = (int)$ll[1];
            $data = null;
            if ($len !== null && $dataStart + $len <= strlen($seg)) {
                $data = substr($seg, $dataStart, $len);
            } else {
                $es = strpos($seg, 'endstream', $dataStart);
                if ($es !== false) $data = preg_replace('/\r?\n$/', '', substr($seg, $dataStart, $es - $dataStart));
            }
            if ($data === null || $data === '') continue;

            $isDCT = stripos($filter, 'DCTDecode') !== false;
            $isFlate = stripos($filter, 'FlateDecode') !== false;
            $out = rtrim($outDir, '/') . '/img_' . bin2hex(random_bytes(6)) . '.jpg';

            if ($isDCT) {
                if ($isFlate) { $d = propertyInflateStream($data); if ($d !== null) $data = $d; }
                if (@file_put_contents($out, $data) && ($gi = @getimagesize($out)) && $gi[0] >= $minEdge && $gi[1] >= $minEdge) {
                    $saved[] = $out;
                } else { @unlink($out); }
            } elseif ($isFlate && $bpc === 8) {
                $inf = propertyInflateStream($data);
                if ($inf === null) continue;
                if (propertyPdfImageToFile($inf, $W, $H, $dict, $raw, $out) && @getimagesize($out)) $saved[] = $out;
                else @unlink($out);
            }
            // JPX/CCITT/JBIG2/16bit 等は非対応（スキップ）
        }
        return $saved;
    }
}

if (!function_exists('propertyClassifyImages')) {
    /**
     * 抽出済み画像群を販売図面用Visionモデルで一度に分類する（建物外観/間取り図/室内写真/設備写真/地図/その他）。
     * @return array  index => ['category'=>..,'thumbnail_candidate'=>bool]
     */
    function propertyClassifyImages(array $imagePaths, array $options = []): array
    {
        $imagePaths = array_values($imagePaths);
        if (!$imagePaths || !function_exists('curl_init')) return [];
        $content = [[
            'type' => 'text',
            'text' => "これらは不動産販売図面から抽出した画像です。各画像を順番（0始まり）に、次のいずれかに分類してください:\n"
                . "建物外観 / 間取り図 / 室内写真 / 設備写真 / 地図 / その他\n"
                . "「その他」= 会社ロゴ・QRコード・地図以外のアイコン・広告・文字主体の画像など。\n"
                . "建物外観が複数ある場合は、建物全体が最も分かりやすい1枚のみ thumbnail_candidate=true。\n"
                . "出力はJSONのみ: {\"results\":[{\"index\":0,\"category\":\"..\",\"thumbnail_candidate\":true}, ...]}。説明やコードフェンスは不要。",
        ]];
        $max = min(count($imagePaths), 20);
        for ($i = 0; $i < $max; $i++) {
            $b = @file_get_contents($imagePaths[$i]);
            if ($b === false) continue;
            $content[] = ['type' => 'text', 'text' => '画像 index=' . $i];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($b)]];
        }
        $messages = [['role' => 'user', 'content' => $content]];
        $reply = null;
        foreach ([propertyFlyerModel()] as $model) {
            $res = callOpenAIChat($messages, chatOpenAIApiKeyForModel($model), $model, [
                'purpose' => 'property_flyer_classify', 'max_tokens' => 900, 'temperature' => 0.0, 'timeout' => 60,
            ] + $options);
            if (empty($res['error']) && !empty($res['reply'])) { $reply = $res['reply']; break; }
            error_log('property classify model ' . $model . ' failed: ' . ($res['error'] ?? 'empty'));
        }
        if ($reply === null) return [];
        $reply = preg_replace('/^```[a-zA-Z]*\s*/', '', trim($reply));
        $reply = preg_replace('/```$/', '', trim($reply));
        $s = strpos($reply, '{'); $e = strrpos($reply, '}');
        if ($s === false || $e === false) return [];
        $parsed = json_decode(substr($reply, $s, $e - $s + 1), true);
        if (!is_array($parsed)) return [];
        $valid = propertyFlyerSaveableCategories();
        $out = [];
        foreach (($parsed['results'] ?? []) as $r) {
            if (!is_array($r) || !isset($r['index'])) continue;
            $cat = trim((string)($r['category'] ?? ''));
            $out[(int)$r['index']] = [
                'category' => $cat,
                'saveable' => in_array($cat, $valid, true),
                'thumbnail_candidate' => !empty($r['thumbnail_candidate']),
            ];
        }
        return $out;
    }
}

if (!function_exists('propertyFlyerProcessUploaded')) {
    /**
     * アップロード直後の販売図面に対して、販売図面用Visionモデルで解析し以下を生成・保存する（再表示時に再解析しない）:
     *  ① 画像抽出: PDF埋め込み画像を純PHPで直接抽出（poppler不要。DCTDecode/FlateDecode）。
     *     取得できない場合はGhostscriptのページラスタからAI領域検出でクロップ（フォールバック）。
     *  ② 抽出画像を販売図面用Visionモデルで一括分類（建物外観/間取り図/室内/設備/地図/その他）
     *  ③ 保存対象（その他以外）のみ「写真・資料」に自動登録（最大10枚・JPEG圧縮/リサイズ）
     *  ④⑤⑥ サムネイル選定（建物外観→間取り図、無ければ未設定）
     *  ⑧ 顧客用マスク済PDF生成（売主情報を黒塗り）
     * すべてのデータに保存期間（既定6か月）を設定する。失敗しても致命的にはしない。
     */
    function propertyFlyerProcessUploaded(PDO $db, int $imageId, string $originalAbsPath, bool $isPdf, int $businessCardId, int $propertyId): void
    {
        $tmpFiles = [];
        try {
            $expiresAt = propertyRetentionExpiresAt();

            // ① ページ画像抽出（gs / 画像）
            $pg = propertyFlyerPageImages($originalAbsPath, $isPdf, 6);
            $pageAbs = $pg['pages'];
            $tmpFiles = $pg['tmp'];

            if (!$pageAbs) {
                // ラスタライズ不可（gs不在等）: 最低限プレビュー＋下端帯マスクで顧客用PDFを試みる
                $preview = propertyFlyerMakePreview($originalAbsPath, $isPdf, $businessCardId, $propertyId);
                if ($preview) {
                    $masked = propertyFlyerApplyMask($preview['abs'], propertyFlyerBottomBandRegions(), $businessCardId, $propertyId);
                    $db->prepare("UPDATE property_images SET preview_path=?, masked_path=?, mask_regions=?, mask_status=?, expires_at=? WHERE id=?")
                       ->execute([$preview['rel'], $masked['rel_pdf'] ?? null, json_encode(['0' => propertyFlyerBottomBandRegions()], JSON_UNESCAPED_UNICODE), $masked ? 'masked' : 'pending', $expiresAt, $imageId]);
                }
                $db->prepare("UPDATE properties SET expires_at=? WHERE id=?")->execute([$expiresAt, $propertyId]);
                return;
            }

            $relDir = 'property/' . $businessCardId . '/' . $propertyId;
            $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
            if (!is_dir($absDir)) @mkdir($absDir, 0755, true);

            // 編集用プレビュー（ページ1を流用・長辺1600pxへ縮小して永続化。gs再実行を避ける）
            $previewRel = null;
            $previewName = 'preview_' . bin2hex(random_bytes(8)) . '.jpg';
            if (propertyRescaleAnyToJpeg($pageAbs[0], $absDir . '/' . $previewName, 1600)) {
                $previewRel = $relDir . '/' . $previewName;
            }

            // 既存「写真・資料」枚数（最大10枚）
            $stmt = $db->prepare("SELECT COUNT(*) FROM property_images WHERE property_id=? AND category='photo'");
            $stmt->execute([$propertyId]);
            $photoCount = (int)$stmt->fetchColumn();
            $stmt = $db->prepare("SELECT COALESCE(MAX(display_order),0) FROM property_images WHERE property_id=? AND category='photo'");
            $stmt->execute([$propertyId]);
            $order = (int)$stmt->fetchColumn();

            $maskByPage = [];
            $pageItems = [];
            $thumb = null; // ['id'=>, 'prio'=>]

            // ② 各ページを販売図面用Visionモデルで解析（写真抽出のフォールバック用 items を取得）。
            //    マスクの既定は「A4横の下3cm（全幅）」に統一する（担当が編集画面で調整・確定する）。
            //    確定するまで顧客には公開されない（customer_visible=0）。
            $defaultBand = propertyFlyerBottomBandRegions();
            foreach ($pageAbs as $pi => $page) {
                $analysis = propertyFlyerAnalyzePage($page);
                $maskByPage[$pi] = $defaultBand;
                $pageItems[$pi] = $analysis['items'] ?? [];
            }

            // 「写真・資料」へ1枚保存するクロージャ（JPEG圧縮・リサイズ＋サムネイル選定）
            $savePhoto = function (string $srcJpeg, string $category, bool $isThumbCand)
                use (&$db, &$photoCount, &$order, &$thumb, $propertyId, $businessCardId, $relDir, $absDir, $expiresAt) {
                if ($photoCount >= 10) return;                           // ⑦ 最大10枚
                $name = 'photo_' . bin2hex(random_bytes(8)) . '.jpg';
                $abs = $absDir . '/' . $name;
                if (!propertyRescaleAnyToJpeg($srcJpeg, $abs, 1280)) return; // ⑦ JPEG圧縮・リサイズ
                $sz = @getimagesize($abs);
                $order++; $photoCount++;
                $db->prepare("INSERT INTO property_images
                    (property_id, business_card_id, category, subcategory, original_name, stored_path, mime_type, byte_size, width, height, display_order, expires_at)
                    VALUES (?, ?, 'photo', ?, ?, ?, 'image/jpeg', ?, ?, ?, ?, ?)")
                   ->execute([$propertyId, $businessCardId, $category, $category . '.jpg',
                        $relDir . '/' . $name, filesize($abs) ?: 0, $sz[0] ?? null, $sz[1] ?? null, $order, $expiresAt]);
                $pid = (int)$db->lastInsertId();
                // ④⑤ サムネイル選定: 建物外観(候補)＞建物外観＞間取り図
                $prio = $category === '建物外観' ? ($isThumbCand ? 1 : 2) : ($category === '間取り図' ? 3 : 9);
                if ($prio <= 3 && ($thumb === null || $prio < $thumb['prio'])) $thumb = ['id' => $pid, 'prio' => $prio];
            };

            // ① 主たる方法: PDFから埋め込み画像を直接抽出 → ② Visionモデルで一括分類 → ③ 保存対象のみ登録
            $usedExtraction = false;
            if ($isPdf) {
                $exDir = rtrim(sys_get_temp_dir(), '/') . '/prop_ex_' . bin2hex(random_bytes(6));
                $extracted = propertyExtractPdfImages($originalAbsPath, $exDir, 200, 40);
                if ($extracted) {
                    $cls = propertyClassifyImages($extracted);
                    foreach ($extracted as $idx => $path) {
                        $c = $cls[$idx] ?? null;
                        if ($c === null || empty($c['saveable'])) continue; // その他/不明は保存しない（売主情報混入回避）
                        if ($photoCount >= 10) break;
                        $savePhoto($path, $c['category'], !empty($c['thumbnail_candidate']));
                        $usedExtraction = true;
                    }
                    foreach ($extracted as $p) { if (is_file($p)) @unlink($p); }
                    if (is_dir($exDir) && !glob($exDir . '/*')) @rmdir($exDir);
                }
            }

            // フォールバック: 埋め込み抽出が無い場合は、ページラスタから領域を切り出して登録
            if (!$usedExtraction) {
                foreach ($pageAbs as $pi => $page) {
                    foreach (($pageItems[$pi] ?? []) as $item) {
                        if (empty($item['saveable'])) continue;
                        if ($photoCount >= 10) break;
                        $cropAbs = $absDir . '/crop_' . bin2hex(random_bytes(6)) . '.jpg';
                        if (!propertyCropRegionToJpeg($page, $item, $cropAbs, 1280)) continue;
                        $savePhoto($cropAbs, $item['category'], !empty($item['thumbnail_candidate']));
                        @unlink($cropAbs);
                    }
                }
            }

            // ⑧ 顧客用マスク済PDF（全ページ・売主情報を白塗り）＋サムネイル画像（下書き。公開は担当の確定後）
            $built = propertyFlyerBuildCustomerPdf($pageAbs, $maskByPage, $businessCardId, $propertyId);
            $maskedRel = $built['pdf'];
            $maskedThumbRel = $built['thumb'];

            // 販売図面（flyer）行を更新
            $db->prepare("UPDATE property_images SET preview_path=?, masked_path=?, masked_thumb_path=?, mask_regions=?, mask_status=?, expires_at=? WHERE id=?")
               ->execute([$previewRel, $maskedRel, $maskedThumbRel, json_encode($maskByPage, JSON_UNESCAPED_UNICODE), $maskedRel ? 'masked' : 'pending', $expiresAt, $imageId]);

            // ⑥ サムネイル（建物外観・間取り図のどちらも無ければ未設定）
            if ($thumb) {
                $db->prepare("UPDATE properties SET thumbnail_image_id=?, expires_at=? WHERE id=?")->execute([$thumb['id'], $expiresAt, $propertyId]);
            } else {
                $db->prepare("UPDATE properties SET expires_at=? WHERE id=?")->execute([$expiresAt, $propertyId]);
            }
        } catch (Throwable $e) {
            error_log('property flyer process error: ' . $e->getMessage());
        } finally {
            foreach ($tmpFiles as $f) { if (is_file($f)) @unlink($f); }
            // 一時ページディレクトリの後始末
            foreach ($tmpFiles as $f) { $d = dirname($f); if (strpos($d, sys_get_temp_dir()) === 0 && is_dir($d) && count(glob($d . '/*')) === 0) @rmdir($d); }
        }
    }
}

if (!function_exists('propertyMaskedThumbEnsure')) {
    /**
     * マスク済PDF（masked_path）はあるがサムネイル画像（masked_thumb_path）が無い販売図面に対し、
     * PDF1ページ目をラスタライズしてサムネイルを遅延生成・保存し、相対パスを返す（旧データのバックフィル）。
     */
    function propertyMaskedThumbEnsure(PDO $db, int $imageId, string $maskedRel, int $businessCardId, int $propertyId): ?string
    {
        $absPdf = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($maskedRel, '/');
        if (!is_file($absPdf)) return null;
        $tmp = propertyRasterizePdfFirstPage($absPdf);
        if (!$tmp) return null;
        $relDir = 'property/' . $businessCardId . '/' . $propertyId;
        $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) { @unlink($tmp); return null; }
        $name = 'maskedthumb_' . bin2hex(random_bytes(8)) . '.jpg';
        $ok = propertyRescaleAnyToJpeg($tmp, $absDir . '/' . $name, 1400, 85);
        @unlink($tmp);
        if (!$ok) return null;
        $rel = $relDir . '/' . $name;
        $db->prepare("UPDATE property_images SET masked_thumb_path = ? WHERE id = ?")->execute([$rel, $imageId]);
        return $rel;
    }
}

if (!function_exists('propertyFlyerVerifyAgentImage')) {
    /** 画像が担当(userId)の名刺に属する販売図面か検証し、行を返す（property_images.* と business_card_id）。 */
    function propertyFlyerVerifyAgentImage(PDO $db, int $imageId, int $userId): array
    {
        $stmt = $db->prepare("
            SELECT pi.* FROM property_images pi
            JOIN business_cards bc ON bc.id = pi.business_card_id
            WHERE pi.id = ? AND bc.user_id = ? AND pi.category = 'flyer' LIMIT 1
        ");
        $stmt->execute([$imageId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) sendErrorResponse('販売図面が見つかりません', 404);
        return $row;
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

        // 担当が物件を追加 → 顧客へメール通知（60秒バッチ・未読中は抑制）。
        // 顧客のメール未登録・失敗は内部で握りつぶす（業務処理は壊さない）。
        // OCR・URL解析の draft は担当の確認前であり、まだ顧客へ共有した扱いにしない。
        // 手動登録または確認済み物件だけを通知対象にする。
        if (($meta['created_by'] ?? 'agent') === 'agent'
            && ($meta['ocr_status'] ?? 'none') !== 'draft') {
            try {
                require_once __DIR__ . '/customer-notification-helper.php';
                if (function_exists('customerNotifyEnqueue')) {
                    customerNotifyEnqueue($db, (string)$meta['session_id'], 'property');
                }
            } catch (Throwable $e) {
                error_log('property create notify error: ' . $e->getMessage());
            }
        }
        return $id;
    }
}
