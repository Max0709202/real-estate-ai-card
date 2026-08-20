<?php
/**
 * 物件選定（顧客側）の未読バッジ。
 * -------------------------------------------------------------
 * 担当が提案した物件のうち、顧客がまだ「物件選定」を開いて確認していない件数を数える。
 * 担当連絡の未読（chat_messages.read_at）と同じ役割を、物件選定にも用意するもの。
 * 従来はメール通知しか無く、顧客がカードページを開いていても新しい提案に気づけなかった。
 *
 * 既読の持ち方は「顧客が最後に物件選定を開いた時点の最大 property_id」。
 *   未読件数 = その顧客へ担当が提案した物件（created_by='agent'）のうち、
 *              id が last_seen_property_id より大きく、かつ詳細も未閲覧のものの件数。
 * 物件は個別に削除されうるが、id は再利用されないため取りこぼし・二重カウントは起きない。
 * 下書き（ocr_status='draft'）は担当の確認前で顧客へ共有されていないため数えない
 * （メール通知の対象条件と揃える）。
 *
 * 機能追加時点では既読レコードが無く last_seen_property_id=0 として扱われるため、
 * 過去の提案がすべて未読になってしまう。既に詳細を開いた物件（property_views に記録あり）は
 * 確認済みとみなして除外し、本当に見ていない提案だけをバッジに出す。
 */

if (!function_exists('propertyUnreadEnsureTable')) {
    /** テーブルが無ければ作成（冪等）。 */
    function propertyUnreadEnsureTable(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS property_customer_reads (
          session_id CHAR(36) NOT NULL PRIMARY KEY,
          last_seen_property_id INT NOT NULL DEFAULT 0,
          seen_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

if (!function_exists('propertyUnreadCountFor')) {
    /**
     * 顧客の未読（未確認）提案物件の件数。取得できないときは 0。
     * バッジ表示のためだけの値のため、失敗しても画面を壊さない。
     */
    function propertyUnreadCountFor(PDO $db, string $sessionId): int
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') return 0;
        try {
            propertyUnreadEnsureTable($db);
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM properties p
                 WHERE p.session_id = ?
                   AND p.created_by = 'agent'
                   AND p.ocr_status <> 'draft'
                   AND p.id > COALESCE((SELECT r.last_seen_property_id FROM property_customer_reads r
                                        WHERE r.session_id = p.session_id), 0)
                   AND NOT EXISTS (SELECT 1 FROM property_views pv
                                   WHERE pv.property_id = p.id AND pv.session_id = p.session_id)"
            );
            $stmt->execute([$sessionId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('propertyUnreadCountFor error: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('propertyUnreadMarkSeen')) {
    /**
     * 顧客が物件選定を開いた → その時点までの提案物件をすべて既読にする。
     * 既読位置は後戻りさせない（GREATEST で単調増加）。
     *
     * @return int 既読にした時点の property_id（既読が進まなかった場合も現在値）
     */
    function propertyUnreadMarkSeen(PDO $db, string $sessionId): int
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') return 0;
        try {
            propertyUnreadEnsureTable($db);
            $stmt = $db->prepare(
                "SELECT COALESCE(MAX(id), 0) FROM properties
                 WHERE session_id = ? AND created_by = 'agent' AND ocr_status <> 'draft'"
            );
            $stmt->execute([$sessionId]);
            $maxId = (int)$stmt->fetchColumn();

            $stmt = $db->prepare(
                "INSERT INTO property_customer_reads (session_id, last_seen_property_id, seen_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                   last_seen_property_id = GREATEST(last_seen_property_id, VALUES(last_seen_property_id)),
                   seen_at = NOW()"
            );
            $stmt->execute([$sessionId, $maxId]);
            return $maxId;
        } catch (Throwable $e) {
            error_log('propertyUnreadMarkSeen error: ' . $e->getMessage());
            return 0;
        }
    }
}
