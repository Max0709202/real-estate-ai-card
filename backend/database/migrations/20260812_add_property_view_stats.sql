-- 物件閲覧の「即時メール通知」と「閲覧回数の集計・並び替え」のための拡張。
-- ------------------------------------------------------------------
-- 背景（要望）:
--   ① 顧客が提案物件の詳細を開いたら、担当エージェントへすぐメール通知したい。
--      ただし同じ顧客の連続閲覧でメールが大量に届かないよう、
--      前回の閲覧通知から3時間以内は再通知しない。
--   ② 担当の提案物件一覧に「累計閲覧回数 / 直近1週間の閲覧回数 / 最終閲覧日時」を出したい。
--   ③ その3項目で一覧を並び替えたい。
--
-- 方針（既存の仕組みを極力活用）:
--   閲覧の記録は既存の property_views（物件×顧客ごとの累計・最終閲覧日時）をそのまま使う。
--   「直近1週間」は累計値からは出せないため、1回の閲覧＝1行の履歴表を追加する。
--   通知の抑止状態（前回いつ通知したか）は顧客（session_id）単位で本表に持つ。

-- 1) 閲覧履歴（1回の閲覧＝1行）。直近N日の閲覧回数の集計に使う。
--    property_views と同じ判定（PROPERTY_VIEW_DEDUPE_SECONDS 以内の再閲覧は1回）で記録するため、
--    SUM(property_views.view_count) と COUNT(property_view_events) は同じ数え方になる。
CREATE TABLE IF NOT EXISTS property_view_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    session_id CHAR(36) NOT NULL,
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_property_view_events_prop (property_id, viewed_at),
    INDEX idx_property_view_events_session (session_id, viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) 閲覧通知の抑止状態（顧客ごと）。
--    last_notified_at から PROPERTY_VIEW_NOTIFY_INTERVAL_SECONDS（既定3時間）以内は再通知しない。
CREATE TABLE IF NOT EXISTS property_view_notifications (
    session_id CHAR(36) NOT NULL PRIMARY KEY,
    last_property_id INT NULL DEFAULT NULL,
    last_notified_at TIMESTAMP NULL DEFAULT NULL,
    notify_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_property_view_notifications_at (last_notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) 既存データの引き継ぎ。
--    本機能より前の閲覧には履歴が無く「直近1週間」が0件になってしまうため、
--    最終閲覧日時だけを1件分の履歴として取り込む（履歴が既にある組み合わせは対象外）。
INSERT INTO property_view_events (property_id, session_id, viewed_at)
SELECT pv.property_id, pv.session_id, pv.last_viewed_at
FROM property_views pv
LEFT JOIN property_view_events e
       ON e.property_id = pv.property_id AND e.session_id = pv.session_id
WHERE pv.last_viewed_at IS NOT NULL AND e.id IS NULL;
