<?php
/**
 * 物件閲覧（SMS認証なしの物件詳細閲覧）まわりのヘルパー。
 * -------------------------------------------------------------
 * ・property_view_tokens: 顧客（chat_sessions）ごとに1つ発行する閲覧トークン。
 *   物件提案メールのリンク（card.php?...&open=property&pv=<token>）に付与し、
 *   SMS認証前でも「その顧客に提案された物件」だけを読み取り専用で表示できるようにする。
 *   トークンは物件情報の閲覧のみに使え、ステータス更新・内見予約・チャット等には使えない。
 * ・property_views: どの顧客（session_id）がどの物件を何回見たかの記録（累計・最終閲覧日時）。
 *   同一顧客・同一物件の短時間の再閲覧（既定30分）は1回として数える。
 * ・property_view_events: 上記で「1回」と数えた閲覧を1行ずつ残す履歴。
 *   累計値からは出せない「直近1週間の閲覧回数」の集計に使う。
 * ・property_view_notifications: 担当エージェントへの閲覧通知の抑止状態（顧客ごと）。
 *   実際の通知は property-view-notify-helper.php が行う。
 *
 * 閲覧回数はエージェント側の物件一覧にのみ表示し、顧客側には返さない。
 */

if (!defined('PROPERTY_VIEW_DEDUPE_SECONDS')) {
    // 同一顧客・同一物件の再閲覧を1回にまとめる時間（既定30分）。
    define('PROPERTY_VIEW_DEDUPE_SECONDS', (int)(getenv('PROPERTY_VIEW_DEDUPE_SECONDS') ?: 1800));
}

if (!defined('PROPERTY_VIEW_RECENT_DAYS')) {
    // 「直近1週間の閲覧回数」の集計期間（日）。
    define('PROPERTY_VIEW_RECENT_DAYS', (int)(getenv('PROPERTY_VIEW_RECENT_DAYS') ?: 7));
}

if (!function_exists('propertyViewEnsureTables')) {
    /** テーブルを冪等に作成する（マイグレーション未実行でも動作させる）。 */
    function propertyViewEnsureTables(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS property_view_tokens (
          session_id CHAR(36) NOT NULL PRIMARY KEY,
          token CHAR(64) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_property_view_tokens_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS property_views (
          id INT AUTO_INCREMENT PRIMARY KEY,
          property_id INT NOT NULL,
          session_id CHAR(36) NOT NULL,
          view_count INT NOT NULL DEFAULT 0,
          last_viewed_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_property_views_prop_session (property_id, session_id),
          INDEX idx_property_views_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 直近N日の閲覧回数を出すための履歴（1回の閲覧＝1行）。
        $db->exec("CREATE TABLE IF NOT EXISTS property_view_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          property_id INT NOT NULL,
          session_id CHAR(36) NOT NULL,
          viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_property_view_events_prop (property_id, viewed_at),
          INDEX idx_property_view_events_session (session_id, viewed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 担当エージェントへの閲覧通知の抑止状態（顧客ごと・前回通知日時）。
        $db->exec("CREATE TABLE IF NOT EXISTS property_view_notifications (
          session_id CHAR(36) NOT NULL PRIMARY KEY,
          last_property_id INT NULL DEFAULT NULL,
          last_notified_at TIMESTAMP NULL DEFAULT NULL,
          notify_count INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_property_view_notifications_at (last_notified_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

if (!function_exists('propertyViewTokenIsValidFormat')) {
    /** 閲覧トークンの書式（64桁の16進）。招待トークン（card.php）と同じ形式。 */
    function propertyViewTokenIsValidFormat(string $token): bool
    {
        return (bool)preg_match('/^[a-f0-9]{64}$/', $token);
    }
}

if (!function_exists('propertyViewTokenFor')) {
    /** セッション（顧客）の閲覧トークンを取得する。無ければ発行する。失敗時は空文字。 */
    function propertyViewTokenFor(PDO $db, string $sessionId): string
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') return '';
        try {
            propertyViewEnsureTables($db);
            $stmt = $db->prepare("SELECT token FROM property_view_tokens WHERE session_id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            $token = (string)($stmt->fetchColumn() ?: '');
            if ($token !== '') return $token;

            $token = bin2hex(random_bytes(32));
            // 同時アクセスで先に発行された場合は、そちらを正とする（トークンを作り直さない）。
            $stmt = $db->prepare("INSERT INTO property_view_tokens (session_id, token) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE token = token");
            $stmt->execute([$sessionId, $token]);
            $stmt = $db->prepare("SELECT token FROM property_view_tokens WHERE session_id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            error_log('propertyViewTokenFor error: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('propertyViewTokenSession')) {
    /** 閲覧トークンからセッションID（顧客）を引く。無効なら空文字。 */
    function propertyViewTokenSession(PDO $db, string $token): string
    {
        $token = trim($token);
        if (!propertyViewTokenIsValidFormat($token)) return '';
        try {
            propertyViewEnsureTables($db);
            $stmt = $db->prepare("SELECT session_id FROM property_view_tokens WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            error_log('propertyViewTokenSession error: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('propertyViewRecord')) {
    /**
     * 顧客が物件詳細を表示した記録（閲覧回数 +1）。
     * 同一顧客・同一物件の短時間の再閲覧（PROPERTY_VIEW_DEDUPE_SECONDS）は加算しない。
     * 加算したときは property_view_events にも1行残す（直近N日の集計用）。
     * 閲覧記録の失敗で詳細表示を壊さないよう、例外は握りつぶしてログのみ残す。
     *
     * @return bool 閲覧回数を加算した（＝新しい1回として数えた）とき true
     */
    function propertyViewRecord(PDO $db, int $propertyId, string $sessionId): bool
    {
        $sessionId = trim($sessionId);
        if ($propertyId <= 0 || $sessionId === '') return false;
        try {
            propertyViewEnsureTables($db);
            $window = (int)PROPERTY_VIEW_DEDUPE_SECONDS;
            // ON DUPLICATE KEY UPDATE の右辺は「更新前」の値を参照するため、
            // view_count（last_viewed_at を見る）を先に、last_viewed_at を後に代入する。
            $sql = "INSERT INTO property_views (property_id, session_id, view_count, last_viewed_at)
                    VALUES (?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE
                      view_count = view_count + IF(last_viewed_at IS NULL
                          OR last_viewed_at < DATE_SUB(NOW(), INTERVAL {$window} SECOND), 1, 0),
                      last_viewed_at = IF(last_viewed_at IS NULL
                          OR last_viewed_at < DATE_SUB(NOW(), INTERVAL {$window} SECOND), NOW(), last_viewed_at)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$propertyId, $sessionId]);
            // 新規INSERT=1 / 加算あり=2 / 抑止（更新なし）=0。加算したときだけ履歴を残す。
            $counted = $stmt->rowCount() > 0;
            if ($counted) {
                $ins = $db->prepare("INSERT INTO property_view_events (property_id, session_id, viewed_at)
                                     VALUES (?, ?, NOW())");
                $ins->execute([$propertyId, $sessionId]);
            }
            return $counted;
        } catch (Throwable $e) {
            error_log('propertyViewRecord error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('propertyViewCountFor')) {
    /** 物件の閲覧回数（その物件を提案された顧客の合計）。取得できなければ 0。 */
    function propertyViewCountFor(PDO $db, int $propertyId): int
    {
        if ($propertyId <= 0) return 0;
        try {
            propertyViewEnsureTables($db);
            $stmt = $db->prepare("SELECT COALESCE(SUM(view_count), 0) FROM property_views WHERE property_id = ?");
            $stmt->execute([$propertyId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('propertyViewCountFor error: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('propertyViewStatsSelectSql')) {
    /**
     * 物件一覧のSELECTに足す閲覧集計カラム（累計 / 直近N日 / 最終閲覧日時）。
     * 物件1件ごとに1クエリ投げる代わりに一覧のクエリでまとめて取り、ORDER BY にも使えるようにする。
     * 返す別名は pv_total / pv_week / pv_last（propertyViewStatsFromRow が読む）。
     *
     * @param string $alias properties テーブルの別名
     */
    function propertyViewStatsSelectSql(string $alias = 'p'): string
    {
        $days = (int)PROPERTY_VIEW_RECENT_DAYS;
        return ", (SELECT COALESCE(SUM(pv.view_count), 0) FROM property_views pv
                   WHERE pv.property_id = {$alias}.id) AS pv_total"
             . ", (SELECT MAX(pv2.last_viewed_at) FROM property_views pv2
                   WHERE pv2.property_id = {$alias}.id) AS pv_last"
             . ", (SELECT COUNT(*) FROM property_view_events pe
                   WHERE pe.property_id = {$alias}.id
                     AND pe.viewed_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)) AS pv_week";
    }
}

if (!function_exists('propertyViewSortOrderSql')) {
    /**
     * 閲覧状況での並び替え（§3 要望）。propertyViewStatsSelectSql の別名を使う。
     * 対応外のキーは空文字を返し、呼び出し側の既定の並び順を使わせる。
     *
     * @param string $sort 'views_total' | 'views_week' | 'last_viewed'
     */
    function propertyViewSortOrderSql(string $sort): string
    {
        switch ($sort) {
            // 未閲覧（NULL/0）は常に後ろへ。同数のときは登録が新しい順。
            case 'views_total': return 'pv_total DESC, pv_last IS NULL ASC, pv_last DESC, created_at DESC, id DESC';
            case 'views_week':  return 'pv_week DESC, pv_last IS NULL ASC, pv_last DESC, created_at DESC, id DESC';
            case 'last_viewed': return 'pv_last IS NULL ASC, pv_last DESC, pv_total DESC, created_at DESC, id DESC';
            default:            return '';
        }
    }
}

if (!function_exists('propertyViewStatsFor')) {
    /**
     * 物件1件の閲覧集計（累計 / 直近N日 / 最終閲覧日時）。
     * @return array{total:int, week:int, last_viewed_at:?string}
     */
    function propertyViewStatsFor(PDO $db, int $propertyId): array
    {
        $empty = ['total' => 0, 'week' => 0, 'last_viewed_at' => null];
        if ($propertyId <= 0) return $empty;
        try {
            propertyViewEnsureTables($db);
            $days = (int)PROPERTY_VIEW_RECENT_DAYS;
            $stmt = $db->prepare(
                "SELECT
                   (SELECT COALESCE(SUM(pv.view_count), 0) FROM property_views pv WHERE pv.property_id = :id) AS pv_total,
                   (SELECT MAX(pv2.last_viewed_at) FROM property_views pv2 WHERE pv2.property_id = :id2) AS pv_last,
                   (SELECT COUNT(*) FROM property_view_events pe
                     WHERE pe.property_id = :id3
                       AND pe.viewed_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)) AS pv_week"
            );
            $stmt->execute([':id' => $propertyId, ':id2' => $propertyId, ':id3' => $propertyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return propertyViewStatsFromRow($row);
        } catch (Throwable $e) {
            error_log('propertyViewStatsFor error: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('propertyViewStatsFromRow')) {
    /** 取得済みの行（pv_total / pv_week / pv_last）を集計値の配列に整える。 */
    function propertyViewStatsFromRow(array $row): array
    {
        return [
            'total' => (int)($row['pv_total'] ?? 0),
            'week'  => (int)($row['pv_week'] ?? 0),
            'last_viewed_at' => ($row['pv_last'] ?? null) ?: null,
        ];
    }
}

if (!function_exists('propertyViewStatsOf')) {
    /**
     * 物件行から閲覧集計を得る。一覧（propertyViewStatsSelectSql 付き）で取得済みなら
     * その値をそのまま使い、詳細取得など集計が無い行のときだけ追加でクエリする。
     */
    function propertyViewStatsOf(PDO $db, array $row): array
    {
        if (array_key_exists('pv_total', $row)) {
            return propertyViewStatsFromRow($row);
        }
        return propertyViewStatsFor($db, (int)($row['id'] ?? 0));
    }
}
