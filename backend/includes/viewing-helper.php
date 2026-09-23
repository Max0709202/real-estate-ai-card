<?php
/**
 * 内見日程調整（「2内見の調整をする仕組み 2026.9.20」仕様）の共通ヘルパー。
 * -------------------------------------------------------------
 * 1案件＝「買主（chat_sessions）×物件（properties）×担当エージェント（business_cards）」。
 * 買主の希望日時（赤）→ エージェントの対応可能日時（黄）→ 売主仲介会社の選択（緑）と進み、
 * 売主仲介会社が「この内容で内見を承諾」を押した時点で内見日時が確定する。
 * 買主への確定メール（M06）は自動送信せず、エージェントが待ち合わせ案内を入力して送った時点で届ける。
 *
 * テーブル（いずれも冪等に CREATE TABLE IF NOT EXISTS で作る。マイグレーション未実行でも動く）:
 *   property_viewings          案件本体。状態・確定日時・鍵情報・待ち合わせ案内・購入検討者属性。
 *   property_viewing_slots     候補枠。1枠1時間。state が buyer(赤)/agent(黄)/seller(緑)。
 *   property_viewing_tokens    外部URL用トークン。売主仲介会社はログインなしで回答する。
 *   property_viewing_reminders 前日18時・当日8時のリマインド予約（案件×確定日時の版×送信回×宛先で一意）。
 *   property_viewing_events    操作・メール送信・開封の履歴。取得できていない情報を「確認済み」にしないため、
 *                              メール開封・ページ閲覧・回答完了は別イベントとして記録する。
 *
 * 日時はすべて日本時間（Asia/Tokyo）で判定する。DBには JST の壁時計時刻をそのまま保存する。
 */

if (!defined('VIEWING_TZ'))            define('VIEWING_TZ', 'Asia/Tokyo');
/** 内見を受け付ける時間帯（10:00〜17:00）。最終枠は 16:00〜17:00。 */
if (!defined('VIEWING_HOUR_START'))    define('VIEWING_HOUR_START', 10);
if (!defined('VIEWING_HOUR_END'))      define('VIEWING_HOUR_END', 17);
/** 開始時刻の刻み（30分）と1枠の長さ（60分）。長時間ドラッグでも1枠は2時間以上にしない。 */
if (!defined('VIEWING_STEP_MINUTES'))  define('VIEWING_STEP_MINUTES', 30);
if (!defined('VIEWING_SLOT_MINUTES'))  define('VIEWING_SLOT_MINUTES', 60);
/** 予約できる期間（当日から30日先まで）。 */
if (!defined('VIEWING_DAYS_AHEAD'))    define('VIEWING_DAYS_AHEAD', 30);
/** 買主が選ぶ希望枠の下限（3枠以上）。上限は設けない。 */
if (!defined('VIEWING_MIN_SLOTS'))     define('VIEWING_MIN_SLOTS', 3);
/** Googleカレンダーの既存予定の前後にあける時間（分）。 */
if (!defined('VIEWING_BUSY_BUFFER_MINUTES')) define('VIEWING_BUSY_BUFFER_MINUTES', 60);

if (!function_exists('viewingTz')) {
    /** 内見日程の判定に使うタイムゾーン（日本時間固定）。 */
    function viewingTz(): DateTimeZone
    {
        static $tz = null;
        if ($tz === null) $tz = new DateTimeZone(VIEWING_TZ);
        return $tz;
    }
}

if (!function_exists('viewingNow')) {
    /** 現在時刻（日本時間）。 */
    function viewingNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', viewingTz());
    }
}

if (!function_exists('viewingEnsureTables')) {
    /** 内見日程調整のテーブルを冪等に作成する。 */
    function viewingEnsureTables(PDO $db): void
    {
        static $done = false;
        if ($done) return;

        $db->exec("CREATE TABLE IF NOT EXISTS property_viewings (
          id INT AUTO_INCREMENT PRIMARY KEY,
          property_id INT NOT NULL,
          session_id CHAR(36) NOT NULL,
          business_card_id INT NOT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'agent_review',
          round INT NOT NULL DEFAULT 1,
          is_rescheduling TINYINT(1) NOT NULL DEFAULT 0,
          buyer_attributes TEXT NULL DEFAULT NULL,
          confirmed_slot_id INT NULL DEFAULT NULL,
          confirmed_start_at DATETIME NULL DEFAULT NULL,
          confirmed_end_at DATETIME NULL DEFAULT NULL,
          confirmed_version INT NOT NULL DEFAULT 0,
          confirmed_at DATETIME NULL DEFAULT NULL,
          prev_start_at DATETIME NULL DEFAULT NULL,
          prev_end_at DATETIME NULL DEFAULT NULL,
          key_method VARCHAR(24) NULL DEFAULT NULL,
          key_json TEXT NULL DEFAULT NULL,
          meeting_note TEXT NULL DEFAULT NULL,
          buyer_notified_at DATETIME NULL DEFAULT NULL,
          cancel_reason VARCHAR(32) NULL DEFAULT NULL,
          cancel_reason_text VARCHAR(500) NULL DEFAULT NULL,
          cancelled_at DATETIME NULL DEFAULT NULL,
          seller_cancel_notified_at DATETIME NULL DEFAULT NULL,
          unavailable_reason VARCHAR(24) NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uk_property_viewings_prop_session (property_id, session_id),
          INDEX idx_property_viewings_card (business_card_id, status),
          INDEX idx_property_viewings_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 候補枠。round ごとに持ち、日時変更のときは新しい round を積む（旧候補と混ざらない）。
        $db->exec("CREATE TABLE IF NOT EXISTS property_viewing_slots (
          id INT AUTO_INCREMENT PRIMARY KEY,
          viewing_id INT NOT NULL,
          round INT NOT NULL DEFAULT 1,
          start_at DATETIME NOT NULL,
          end_at DATETIME NOT NULL,
          state ENUM('buyer','agent','seller') NOT NULL DEFAULT 'buyer',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_property_viewing_slots (viewing_id, round, start_at),
          INDEX idx_property_viewing_slots_viewing (viewing_id, round, start_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 外部URL用トークン。売主仲介会社は専用URLからログインなしで回答する。
        $db->exec("CREATE TABLE IF NOT EXISTS property_viewing_tokens (
          token CHAR(64) NOT NULL PRIMARY KEY,
          viewing_id INT NOT NULL,
          audience ENUM('seller','buyer','agent') NOT NULL,
          expires_at DATETIME NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_property_viewing_tokens (viewing_id, audience),
          INDEX idx_property_viewing_tokens_viewing (viewing_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // リマインド予約。案件・確定日時の版・送信回・宛先ごとに1行＝1回だけ送る。
        $db->exec("CREATE TABLE IF NOT EXISTS property_viewing_reminders (
          id INT AUTO_INCREMENT PRIMARY KEY,
          viewing_id INT NOT NULL,
          slot_version INT NOT NULL,
          kind ENUM('prev_day','same_day') NOT NULL,
          target_start_at DATETIME NOT NULL,
          send_at DATETIME NOT NULL,
          recipient VARCHAR(255) NOT NULL,
          recipient_role ENUM('buyer','agent') NOT NULL DEFAULT 'buyer',
          status ENUM('scheduled','sent','cancelled','failed') NOT NULL DEFAULT 'scheduled',
          attempts INT NOT NULL DEFAULT 0,
          sent_at DATETIME NULL DEFAULT NULL,
          error_message VARCHAR(500) NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uk_property_viewing_reminders (viewing_id, slot_version, kind, recipient),
          INDEX idx_property_viewing_reminders_due (status, send_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 操作・メール送信・開封の履歴。開封／ページ閲覧／回答完了は区別して記録する。
        $db->exec("CREATE TABLE IF NOT EXISTS property_viewing_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          viewing_id INT NOT NULL,
          event VARCHAR(32) NOT NULL,
          mail_code VARCHAR(8) NULL DEFAULT NULL,
          actor VARCHAR(16) NULL DEFAULT NULL,
          recipient VARCHAR(255) NULL DEFAULT NULL,
          result VARCHAR(16) NULL DEFAULT NULL,
          detail TEXT NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_property_viewing_events_viewing (viewing_id, created_at),
          INDEX idx_property_viewing_events_mail (viewing_id, mail_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        viewingEnsureColumns($db);
        $done = true;
    }
}

if (!function_exists('viewingEnsureColumns')) {
    /** 既存テーブルに後から追加したカラムを冪等に足す。 */
    function viewingEnsureColumns(PDO $db): void
    {
        $cols = [
            // 購入検討者属性（エージェントの自由入力。売主仲介会社の回答画面に表示する）。
            'buyer_attributes' => "ADD COLUMN buyer_attributes TEXT NULL DEFAULT NULL AFTER is_rescheduling",
        ];
        foreach ($cols as $name => $ddl) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns
                                      WHERE table_schema = DATABASE() AND table_name = 'property_viewings' AND column_name = ?");
                $stmt->execute([$name]);
                if ((int)$stmt->fetchColumn() === 0) $db->exec("ALTER TABLE property_viewings {$ddl}");
            } catch (Throwable $e) {
                error_log('viewingEnsureColumns(' . $name . ') error: ' . $e->getMessage());
            }
        }
    }
}

/* ──────────────────────────────────────────────────────────
 * 定義（状態・鍵の受け渡し・キャンセル理由）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingStatusDefs')) {
    /**
     * 案件の状態（仕様 §10）。状態を変える操作を description に添える。
     * 「変更調整中か」「メールの送信成功・失敗」「売主側へのキャンセル通知状況」は
     * この状態とは別に記録する（is_rescheduling / property_viewing_events / seller_cancel_notified_at）。
     */
    function viewingStatusDefs(): array
    {
        return [
            'agent_review'   => ['label' => '担当者確認待ち',          'by' => '買主が希望日時を送信'],
            'buyer_reinput'  => ['label' => '買主の再入力待ち',        'by' => 'エージェントが再調整を依頼'],
            'seller_pending' => ['label' => '売主側回答待ち',          'by' => 'エージェントが売主側へ打診'],
            'confirmed'      => ['label' => '日時確定／買主への連絡待ち', 'by' => '売主側が承諾'],
            'buyer_notified' => ['label' => '買主へ確定連絡済み',      'by' => 'エージェントがM06を送信'],
            'cancelled'      => ['label' => 'キャンセル済み',          'by' => '買主がキャンセルを確定'],
            'unavailable'    => ['label' => '内見不可',                'by' => '売主側が成約・申込済みと回答'],
        ];
    }
}

if (!function_exists('viewingKeyMethodDefs')) {
    /**
     * 鍵の受け渡し方法と、方法ごとの入力欄・必須項目（仕様 §6）。
     * required に挙げた項目が空のままでは「この内容で内見を承諾」を押せない。
     */
    function viewingKeyMethodDefs(): array
    {
        return [
            'onsite' => [
                'label' => '現地立ち会い',
                'fields' => ['place' => '待ち合わせ場所', 'time' => '待ち合わせ時刻'],
                'required' => ['place', 'time'],
                'attachment' => false,
            ],
            'shop' => [
                'label' => '店舗受け渡し',
                'fields' => ['shop_name' => '店舗名', 'address' => '住所', 'phone' => '電話番号', 'person' => '担当者名'],
                'required' => ['shop_name', 'address', 'phone', 'person'],
                'attachment' => false,
            ],
            'keybox' => [
                'label' => '現地キーBOX',
                'fields' => ['place' => '設置場所', 'code' => '番号'],
                'required' => ['place', 'code'],
                'attachment' => true,
            ],
            'other' => [
                'label' => 'その他',
                'fields' => ['note' => '受け渡し方法の説明'],
                'required' => ['note'],
                'attachment' => true,
            ],
        ];
    }
}

if (!function_exists('viewingCancelReasonDefs')) {
    /** キャンセル理由（必須。「その他」は自由記入も必須）。 */
    function viewingCancelReasonDefs(): array
    {
        return [
            'no_interest'  => '内見予定の物件に興味がなくなった',
            'other_agency' => '他社で購入を決めた',
            'stop_buying'  => '物件購入をやめた',
            'other'        => 'その他',
        ];
    }
}

/* ──────────────────────────────────────────────────────────
 * 物件の表示名（★2026/9/20 追加依頼：物件名には必ず金額を併記する）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingPropertyPriceText')) {
    /** 物件の金額表記。販売図面から読み取った price_text を優先し、無ければ price_man から組み立てる。 */
    function viewingPropertyPriceText(array $p): string
    {
        $text = trim((string)($p['price_text'] ?? ''));
        if ($text !== '') return $text;
        $man = isset($p['price_man']) ? (int)$p['price_man'] : 0;
        // 依頼書の表記例（例：9800万円）に合わせ、桁区切りは入れない。
        if ($man > 0) return $man . '万円';
        return '';
    }
}

if (!function_exists('viewingPropertyLabel')) {
    /**
     * メール・画面で物件を特定するための表記。
     * 追加依頼（2026/9/20）により、物件名とあわせて必ず金額も表示する。
     *   例）エルザタワー55　6500万円 ／ 中野区本町６一戸建て　9800万円
     * 金額が未登録の物件では物件名のみを返す（「　円」のような欠けた表記にしない）。
     */
    function viewingPropertyLabel(array $p): string
    {
        $name = trim((string)($p['property_name'] ?? ''));
        if ($name === '') $name = trim((string)($p['building_name'] ?? ''));
        if ($name === '') $name = '（物件名未設定）';
        $price = viewingPropertyPriceText($p);
        return $price !== '' ? $name . '　' . $price : $name;
    }
}

/* ──────────────────────────────────────────────────────────
 * 候補枠のルール（10:00〜17:00 / 開始30分刻み / 1枠1時間 / 当日〜30日先）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingSlotStartCandidates')) {
    /** 1日ぶんの開始時刻（"10:00","10:30",…,"16:00"）。最終枠は 16:00〜17:00。 */
    function viewingSlotStartCandidates(): array
    {
        $out = [];
        $minutes = VIEWING_HOUR_START * 60;
        $last = VIEWING_HOUR_END * 60 - VIEWING_SLOT_MINUTES;
        for (; $minutes <= $last; $minutes += VIEWING_STEP_MINUTES) {
            $out[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }
        return $out;
    }
}

if (!function_exists('viewingParseSlotStart')) {
    /**
     * 候補枠の開始日時を検証して DateTimeImmutable（JST）にする。ルール違反なら null。
     *  ・"Y-m-d H:i" / "Y-m-d H:i:s" 形式
     *  ・開始は30分刻み、10:00〜16:00 の範囲
     *  ・当日から30日先まで。過去の時刻は選べない。
     */
    function viewingParseSlotStart(string $raw, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $raw = str_replace('T', ' ', $raw);
        if (strlen($raw) === 16) $raw .= ':00';
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, viewingTz());
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $raw) return null;
        if ((int)$dt->format('s') !== 0) return null;

        $minutes = (int)$dt->format('G') * 60 + (int)$dt->format('i');
        if ($minutes % VIEWING_STEP_MINUTES !== 0) return null;
        if ($minutes < VIEWING_HOUR_START * 60) return null;
        if ($minutes > VIEWING_HOUR_END * 60 - VIEWING_SLOT_MINUTES) return null;

        $now = $now ?: viewingNow();
        if ($dt <= $now) return null;                                   // 過去の時刻は選べない
        $limit = $now->setTime(23, 59, 59)->modify('+' . VIEWING_DAYS_AHEAD . ' days');
        if ($dt > $limit) return null;                                  // 当日から30日先まで
        return $dt;
    }
}

if (!function_exists('viewingNormalizeSlots')) {
    /**
     * 入力された候補日時を検証・重複排除して昇順に並べる。
     * 同じ枠の重複は数えない。1枠は必ず1時間（長いドラッグでも2時間以上にしない）。
     *
     * @param array $raw 開始日時の配列（"2026-10-05 10:00" 等）
     * @return array{slots: array<int, array{start:string,end:string}>, invalid: int}
     */
    function viewingNormalizeSlots(array $raw, ?DateTimeImmutable $now = null): array
    {
        $seen = [];
        $invalid = 0;
        foreach ($raw as $item) {
            if (is_array($item)) $item = $item['start'] ?? ($item['start_at'] ?? '');
            $dt = viewingParseSlotStart((string)$item, $now);
            if ($dt === null) { $invalid++; continue; }
            $seen[$dt->format('Y-m-d H:i:s')] = $dt;
        }
        ksort($seen);
        $slots = [];
        foreach ($seen as $key => $dt) {
            $slots[] = [
                'start' => $key,
                'end'   => $dt->modify('+' . VIEWING_SLOT_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
            ];
        }
        return ['slots' => $slots, 'invalid' => $invalid];
    }
}

if (!function_exists('viewingFormatRange')) {
    /** 「10月5日（月）10:00〜11:00」。メール・画面で同じ表記を使う。 */
    function viewingFormatRange(?string $startAt, ?string $endAt = null): string
    {
        $startAt = trim((string)$startAt);
        if ($startAt === '') return '';
        try {
            $s = new DateTimeImmutable($startAt, viewingTz());
        } catch (Throwable $e) {
            return $startAt;
        }
        $e = null;
        if (trim((string)$endAt) !== '') {
            try { $e = new DateTimeImmutable((string)$endAt, viewingTz()); } catch (Throwable $ex) { $e = null; }
        }
        if ($e === null) $e = $s->modify('+' . VIEWING_SLOT_MINUTES . ' minutes');
        $w = ['日', '月', '火', '水', '木', '金', '土'][(int)$s->format('w')];
        return sprintf('%d月%d日（%s）%s〜%s', (int)$s->format('n'), (int)$s->format('j'), $w, $s->format('H:i'), $e->format('H:i'));
    }
}

if (!function_exists('viewingFormatDateStart')) {
    /** 件名用の「10月5日 11:00」。 */
    function viewingFormatDateStart(?string $startAt): string
    {
        $startAt = trim((string)$startAt);
        if ($startAt === '') return '';
        try {
            $s = new DateTimeImmutable($startAt, viewingTz());
        } catch (Throwable $e) {
            return $startAt;
        }
        return sprintf('%d月%d日 %s', (int)$s->format('n'), (int)$s->format('j'), $s->format('H:i'));
    }
}

/* ──────────────────────────────────────────────────────────
 * 外部URL用トークン
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingTokenFor')) {
    /**
     * 案件×宛先ごとの外部URLトークンを取得する（無ければ発行）。
     * 売主仲介会社・買主それぞれに別トークンを渡し、操作できる範囲を分ける。
     */
    function viewingTokenFor(PDO $db, int $viewingId, string $audience): string
    {
        if ($viewingId <= 0 || !in_array($audience, ['seller', 'buyer', 'agent'], true)) return '';
        try {
            viewingEnsureTables($db);
            $stmt = $db->prepare("SELECT token FROM property_viewing_tokens WHERE viewing_id = ? AND audience = ? LIMIT 1");
            $stmt->execute([$viewingId, $audience]);
            $token = (string)($stmt->fetchColumn() ?: '');
            if ($token !== '') return $token;

            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("INSERT INTO property_viewing_tokens (token, viewing_id, audience)
                                  VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token = token");
            $stmt->execute([$token, $viewingId, $audience]);
            // 同時アクセスで先に発行された場合は、そちらを正とする。
            $stmt = $db->prepare("SELECT token FROM property_viewing_tokens WHERE viewing_id = ? AND audience = ? LIMIT 1");
            $stmt->execute([$viewingId, $audience]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            error_log('viewingTokenFor error: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('viewingTokenLookup')) {
    /** トークンから案件IDと宛先を引く。書式不正・未登録・期限切れなら null。 */
    function viewingTokenLookup(PDO $db, string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        try {
            viewingEnsureTables($db);
            $stmt = $db->prepare("SELECT viewing_id, audience, expires_at FROM property_viewing_tokens WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $exp = trim((string)($row['expires_at'] ?? ''));
            if ($exp !== '' && new DateTimeImmutable($exp, viewingTz()) < viewingNow()) return null;
            return ['viewing_id' => (int)$row['viewing_id'], 'audience' => (string)$row['audience']];
        } catch (Throwable $e) {
            error_log('viewingTokenLookup error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('viewingSellerReplyUrl')) {
    /** 売主仲介会社の回答URL（ログイン不要）。 */
    function viewingSellerReplyUrl(PDO $db, int $viewingId): string
    {
        $token = viewingTokenFor($db, $viewingId, 'seller');
        if ($token === '') return '';
        return rtrim(BASE_URL, '/') . '/viewing-reply.php?t=' . rawurlencode($token);
    }
}

if (!function_exists('viewingAgentUrl')) {
    /**
     * エージェント向けの調整画面URL（ログイン必須。ログイン後に該当物件へ移動する）。
     * 既存の担当連絡通知（notifyDeepLinkUrl）と同じ作りにそろえる。
     */
    function viewingAgentUrl(string $sessionId, int $propertyId): string
    {
        $target = 'edit.php?type=existing&focus=property&session=' . rawurlencode($sessionId)
            . '&property=' . rawurlencode((string)$propertyId) . '&viewing=1';
        return rtrim(BASE_URL, '/') . '/login.php?redirect=' . rawurlencode($target);
    }
}

if (!function_exists('viewingBuyerUrl')) {
    /**
     * 買主向けの確定内容URL。顧客ページ（card.php）の物件詳細を内見タブで開く。
     * 物件提案メールと同じく閲覧トークン（pv）を付け、SMS認証前でも内容を確認できるようにする。
     */
    function viewingBuyerUrl(PDO $db, string $sessionId, int $propertyId, string $view = 'detail'): string
    {
        $slug = '';
        $viewToken = '';
        try {
            $stmt = $db->prepare("SELECT bc.url_slug FROM chat_sessions cs
                                  JOIN business_cards bc ON bc.id = cs.business_card_id
                                  WHERE cs.id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            $slug = (string)($stmt->fetchColumn() ?: '');
            if (function_exists('propertyViewTokenFor')) $viewToken = propertyViewTokenFor($db, $sessionId);
        } catch (Throwable $e) {
            error_log('viewingBuyerUrl error: ' . $e->getMessage());
        }
        if ($slug === '') return rtrim(BASE_URL, '/');
        $url = rtrim(BASE_URL, '/') . '/card.php?slug=' . rawurlencode($slug) . '&open=property';
        // 物件を特定できる場合だけ、該当物件の内見画面まで開くパラメータを付ける。
        if ($propertyId > 0) {
            $url .= '&property=' . rawurlencode((string)$propertyId) . '&viewing=' . rawurlencode($view);
        }
        if ($viewToken !== '') $url .= '&pv=' . rawurlencode($viewToken);
        return $url;
    }
}

/* ──────────────────────────────────────────────────────────
 * 案件の取得・作成
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingLoad')) {
    /** 案件1件を物件情報つきで取得する。 */
    function viewingLoad(PDO $db, int $viewingId): ?array
    {
        if ($viewingId <= 0) return null;
        viewingEnsureTables($db);
        $stmt = $db->prepare("SELECT * FROM property_viewings WHERE id = ? LIMIT 1");
        $stmt->execute([$viewingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['key_data'] = $row['key_json'] ? (json_decode((string)$row['key_json'], true) ?: []) : [];
        return $row;
    }
}

if (!function_exists('viewingLoadProperty')) {
    /** 案件に紐づく物件行。 */
    function viewingLoadProperty(PDO $db, int $propertyId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
        $stmt->execute([$propertyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('viewingFindByPropertySession')) {
    /** 物件×買主の案件を取得する（1物件1買主につき1案件）。 */
    function viewingFindByPropertySession(PDO $db, int $propertyId, string $sessionId): ?array
    {
        viewingEnsureTables($db);
        $stmt = $db->prepare("SELECT id FROM property_viewings WHERE property_id = ? AND session_id = ? LIMIT 1");
        $stmt->execute([$propertyId, $sessionId]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        return $id > 0 ? viewingLoad($db, $id) : null;
    }
}

if (!function_exists('viewingEnsureCase')) {
    /** 物件×買主の案件を取得し、無ければ作る。 */
    function viewingEnsureCase(PDO $db, int $propertyId, string $sessionId, int $businessCardId): array
    {
        $existing = viewingFindByPropertySession($db, $propertyId, $sessionId);
        if ($existing) return $existing;
        $stmt = $db->prepare("INSERT INTO property_viewings (property_id, session_id, business_card_id, status)
                              VALUES (?, ?, ?, 'agent_review')
                              ON DUPLICATE KEY UPDATE business_card_id = VALUES(business_card_id)");
        $stmt->execute([$propertyId, $sessionId, $businessCardId]);
        $case = viewingFindByPropertySession($db, $propertyId, $sessionId);
        if (!$case) throw new RuntimeException('内見案件の作成に失敗しました');
        return $case;
    }
}

if (!function_exists('viewingSlots')) {
    /** 案件の候補枠。round 省略時は現在の round。 */
    function viewingSlots(PDO $db, int $viewingId, ?int $round = null): array
    {
        viewingEnsureTables($db);
        if ($round === null) {
            $stmt = $db->prepare("SELECT round FROM property_viewings WHERE id = ? LIMIT 1");
            $stmt->execute([$viewingId]);
            $round = (int)($stmt->fetchColumn() ?: 1);
        }
        $stmt = $db->prepare("SELECT id, round, start_at, end_at, state FROM property_viewing_slots
                              WHERE viewing_id = ? AND round = ? ORDER BY start_at ASC");
        $stmt->execute([$viewingId, $round]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('viewingReplaceSlots')) {
    /**
     * 買主の希望枠を保存する（その round の候補を入れ替える）。
     * 赤（buyer）で登録し、エージェントが選んだものだけ黄（agent）に変える。
     */
    function viewingReplaceSlots(PDO $db, int $viewingId, int $round, array $slots): int
    {
        viewingEnsureTables($db);
        $db->prepare("DELETE FROM property_viewing_slots WHERE viewing_id = ? AND round = ?")->execute([$viewingId, $round]);
        $ins = $db->prepare("INSERT INTO property_viewing_slots (viewing_id, round, start_at, end_at, state)
                             VALUES (?, ?, ?, ?, 'buyer')");
        $n = 0;
        foreach ($slots as $s) {
            $ins->execute([$viewingId, $round, $s['start'], $s['end']]);
            $n++;
        }
        return $n;
    }
}

if (!function_exists('viewingLogEvent')) {
    /**
     * 履歴を1行残す。メールの送信結果・開封・ページ閲覧・回答完了を区別して記録する。
     * 記録の失敗で業務処理を止めない。
     */
    function viewingLogEvent(PDO $db, int $viewingId, string $event, array $opts = []): void
    {
        try {
            viewingEnsureTables($db);
            $stmt = $db->prepare("INSERT INTO property_viewing_events
                                  (viewing_id, event, mail_code, actor, recipient, result, detail)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $viewingId,
                mb_substr($event, 0, 32),
                isset($opts['mail_code']) ? mb_substr((string)$opts['mail_code'], 0, 8) : null,
                isset($opts['actor']) ? mb_substr((string)$opts['actor'], 0, 16) : null,
                isset($opts['recipient']) ? mb_substr((string)$opts['recipient'], 0, 255) : null,
                isset($opts['result']) ? mb_substr((string)$opts['result'], 0, 16) : null,
                isset($opts['detail']) ? (string)$opts['detail'] : null,
            ]);
        } catch (Throwable $e) {
            error_log('viewingLogEvent error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('viewingEventSummary')) {
    /**
     * 担当者画面に出す送信状況。取得できていない情報を「確認済み」と表示しないため、
     * 送信（mail_sent）・開封（mail_open）・ページ閲覧（page_open）・回答完了（replied）を別々に返す。
     */
    function viewingEventSummary(PDO $db, int $viewingId): array
    {
        viewingEnsureTables($db);
        $out = ['mails' => [], 'seller_opened_at' => null, 'seller_page_opened_at' => null, 'seller_replied_at' => null, 'failures' => []];
        $stmt = $db->prepare("SELECT event, mail_code, recipient, result, detail, created_at
                              FROM property_viewing_events WHERE viewing_id = ? ORDER BY created_at ASC");
        $stmt->execute([$viewingId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $at = (string)$r['created_at'];
            switch ($r['event']) {
                case 'mail_sent':
                    $out['mails'][] = ['code' => $r['mail_code'], 'recipient' => $r['recipient'], 'result' => $r['result'], 'at' => $at];
                    if ($r['result'] === 'failed') {
                        $out['failures'][] = ['code' => $r['mail_code'], 'recipient' => $r['recipient'], 'at' => $at, 'detail' => $r['detail']];
                    }
                    break;
                case 'mail_open':  if ($out['seller_opened_at'] === null) $out['seller_opened_at'] = $at; break;
                case 'page_open':  if ($out['seller_page_opened_at'] === null) $out['seller_page_opened_at'] = $at; break;
                case 'replied':    $out['seller_replied_at'] = $at; break;
            }
        }
        return $out;
    }
}

if (!function_exists('viewingIsOpen')) {
    /** 調整・確定を受け付けられる状態か（キャンセル済み・内見不可は受け付けない）。 */
    function viewingIsOpen(array $case): bool
    {
        return !in_array((string)$case['status'], ['cancelled', 'unavailable'], true);
    }
}

/* ──────────────────────────────────────────────────────────
 * 鍵の受け渡しに添える写真・資料
 * 売主仲介会社が回答画面から添付し、担当エージェントが確認する。
 * 買主には公開しない（買主画面に鍵情報・添付資料は表示しない）。
 * ────────────────────────────────────────────────────────── */

if (!defined('VIEWING_ATTACHMENT_MAX')) define('VIEWING_ATTACHMENT_MAX', 5);

if (!function_exists('viewingAttachmentEnsureTable')) {
    function viewingAttachmentEnsureTable(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS property_viewing_attachments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          viewing_id INT NOT NULL,
          original_name VARCHAR(200) NULL DEFAULT NULL,
          stored_path VARCHAR(512) NOT NULL,
          mime_type VARCHAR(127) NULL DEFAULT NULL,
          byte_size INT NULL DEFAULT NULL,
          uploaded_by ENUM('seller','agent') NOT NULL DEFAULT 'seller',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_property_viewing_attachments (viewing_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

if (!function_exists('viewingStoreAttachment')) {
    /**
     * 添付（画像／PDF）を保存する。保存先は物件の資料とは別ディレクトリにし、
     * 買主向けの配信経路（image.php）からは参照できないようにする。
     *
     * @return array{id?:int, error?:string}
     */
    function viewingStoreAttachment(PDO $db, array $file, int $viewingId, string $uploadedBy = 'seller'): array
    {
        if (!isset($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return ['error' => 'ファイルのアップロードに失敗しました'];
        }
        viewingAttachmentEnsureTable($db);

        $stmt = $db->prepare("SELECT COUNT(*) FROM property_viewing_attachments WHERE viewing_id = ?");
        $stmt->execute([$viewingId]);
        if ((int)$stmt->fetchColumn() >= VIEWING_ATTACHMENT_MAX) {
            return ['error' => '添付は最大' . VIEWING_ATTACHMENT_MAX . '件までです'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = strtolower(trim((string)finfo_file($finfo, $file['tmp_name'])));
        if ($finfo) finfo_close($finfo);
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        $isPdf = ($mime === 'application/pdf' || $ext === 'pdf');
        if (!$isImage && !$isPdf) return ['error' => '対応していない形式です（画像/PDFのみ）'];
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) return ['error' => '対応していない拡張子です'];

        $relDir = 'viewing/' . $viewingId;
        $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return ['error' => '保存先を作成できませんでした'];
        }
        $safeExt = $isImage ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'pdf';
        $stored = bin2hex(random_bytes(16)) . '.' . $safeExt;
        $absPath = $absDir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $absPath)) return ['error' => 'ファイルの保存に失敗しました'];

        if (function_exists('upload_security_clamav_scan')) {
            $scan = upload_security_clamav_scan($absPath);
            if (empty($scan['ok'])) { @unlink($absPath); return ['error' => 'ファイルから脅威が検出されました']; }
        }

        $stmt = $db->prepare("INSERT INTO property_viewing_attachments
                              (viewing_id, original_name, stored_path, mime_type, byte_size, uploaded_by)
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $viewingId,
            mb_substr(basename(str_replace('\\', '/', $file['name'] ?? 'file')), 0, 200),
            $relDir . '/' . $stored,
            $mime,
            filesize($absPath) ?: (int)($file['size'] ?? 0),
            $uploadedBy === 'agent' ? 'agent' : 'seller',
        ]);
        return ['id' => (int)$db->lastInsertId()];
    }
}

if (!function_exists('viewingAttachments')) {
    /** 案件の添付一覧（メタ情報のみ。実体は viewing-attachment.php から配信する）。 */
    function viewingAttachments(PDO $db, int $viewingId): array
    {
        try {
            viewingAttachmentEnsureTable($db);
            $stmt = $db->prepare("SELECT id, original_name, mime_type, byte_size, uploaded_by, created_at
                                  FROM property_viewing_attachments WHERE viewing_id = ? ORDER BY id ASC");
            $stmt->execute([$viewingId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('viewingAttachments error: ' . $e->getMessage());
            return [];
        }
    }
}
