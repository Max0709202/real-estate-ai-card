-- 内見日程調整（「2内見の調整をする仕組み 2026.9.20」仕様）
-- viewing-helper.php の viewingEnsureTables() と同じ定義。
-- 本番へ先に流しておくと、初回アクセス時の CREATE TABLE を省ける。

-- 案件本体（買主 × 物件 × 担当エージェント）
CREATE TABLE IF NOT EXISTS property_viewings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  session_id CHAR(36) NOT NULL,
  business_card_id INT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'agent_review',
  round INT NOT NULL DEFAULT 1,
  is_rescheduling TINYINT(1) NOT NULL DEFAULT 0,
  -- 購入検討者属性（エージェントの自由入力。売主仲介会社の回答画面に表示する）
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 候補枠（1枠1時間）。state: buyer=赤（買主の希望） / agent=黄（担当者対応可） / seller=緑（売主側が選択）
CREATE TABLE IF NOT EXISTS property_viewing_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  viewing_id INT NOT NULL,
  round INT NOT NULL DEFAULT 1,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  state ENUM('buyer','agent','seller') NOT NULL DEFAULT 'buyer',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_property_viewing_slots (viewing_id, round, start_at),
  INDEX idx_property_viewing_slots_viewing (viewing_id, round, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 外部URL用トークン（売主仲介会社はログインなしで回答する）
CREATE TABLE IF NOT EXISTS property_viewing_tokens (
  token CHAR(64) NOT NULL PRIMARY KEY,
  viewing_id INT NOT NULL,
  audience ENUM('seller','buyer','agent') NOT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_property_viewing_tokens (viewing_id, audience),
  INDEX idx_property_viewing_tokens_viewing (viewing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- リマインド予約（案件 × 確定日時の版 × 送信回 × 宛先で1回だけ送る）
CREATE TABLE IF NOT EXISTS property_viewing_reminders (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 操作・メール送信・開封の履歴（開封／ページ閲覧／回答完了は区別して記録する）
CREATE TABLE IF NOT EXISTS property_viewing_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
