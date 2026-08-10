<?php
/**
 * 物件閲覧（SMS認証なしの物件詳細閲覧）まわりのヘルパー。
 * -------------------------------------------------------------
 * ・property_view_tokens: 顧客（chat_sessions）ごとに1つ発行する閲覧トークン。
 *   物件提案メールのリンク（card.php?...&open=property&pv=<token>）に付与し、
 *   SMS認証前でも「その顧客に提案された物件」だけを読み取り専用で表示できるようにする。
 *   トークンは物件情報の閲覧のみに使え、ステータス更新・内見予約・チャット等には使えない。
 * ・property_views: どの顧客（session_id）がどの物件を何回見たかの記録。
 *   同一顧客・同一物件の短時間の再閲覧（既定30分）は1回として数える。
 *
 * 閲覧回数はエージェント側の物件一覧にのみ表示し、顧客側には返さない。
 */

if (!defined('PROPERTY_VIEW_DEDUPE_SECONDS')) {
    // 同一顧客・同一物件の再閲覧を1回にまとめる時間（既定30分）。
    define('PROPERTY_VIEW_DEDUPE_SECONDS', (int)(getenv('PROPERTY_VIEW_DEDUPE_SECONDS') ?: 1800));
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
     * 閲覧記録の失敗で詳細表示を壊さないよう、例外は握りつぶしてログのみ残す。
     */
    function propertyViewRecord(PDO $db, int $propertyId, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($propertyId <= 0 || $sessionId === '') return;
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
        } catch (Throwable $e) {
            error_log('propertyViewRecord error: ' . $e->getMessage());
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
