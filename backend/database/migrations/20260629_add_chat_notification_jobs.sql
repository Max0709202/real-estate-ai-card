-- 営業担当へのメール通知（担当連絡 / 物件選定 / 日程調整）用ジョブテーブル。
-- 1セッション × 1機能 につき1行で状態管理する。
--   status: pending = 送信待ち（60秒バッチ集約中）
--           sent    = 送信済み・営業未読（この間は追加通知しない）
--           read    = 営業が該当画面を開いた（次の新規操作で再び通知対象）
CREATE TABLE IF NOT EXISTS chat_notification_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(36) NOT NULL,
  feature ENUM('contact','property','schedule') NOT NULL,
  business_card_id INT NOT NULL,
  recipient_user_id INT NOT NULL,
  recipient_email VARCHAR(255) NOT NULL,
  customer_name VARCHAR(255) NULL,
  status ENUM('pending','sent','read') NOT NULL DEFAULT 'pending',
  event_count INT NOT NULL DEFAULT 0,
  first_event_at TIMESTAMP NULL DEFAULT NULL,
  last_event_at  TIMESTAMP NULL DEFAULT NULL,
  scheduled_at   TIMESTAMP NULL DEFAULT NULL,  -- = last_event_at + 待機秒。cron はこの時刻を過ぎた pending を送信
  sent_at TIMESTAMP NULL DEFAULT NULL,
  read_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_session_feature (session_id, feature),
  INDEX idx_due (status, scheduled_at),
  INDEX idx_recipient (recipient_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
