-- 担当連絡（顧客↔担当エージェント リアルタイムチャット）
-- 既存の chat_sessions / chat_messages を共有チャネルにし、role='agent'（人間の担当発言）を追加する。

-- 1) 役割に 'agent'（人間の担当発言）を追加
ALTER TABLE chat_messages
  MODIFY COLUMN role ENUM('user','bot','system','agent') NOT NULL;

-- 2) 送信者（担当発言時の users.id。user/bot/system は NULL）
ALTER TABLE chat_messages
  ADD COLUMN sender_user_id INT NULL DEFAULT NULL AFTER role;

-- 3) 既読管理（その発言を相手が読んだ時刻。user発言は担当が読んだ時刻 / agent発言は顧客が読んだ時刻）
ALTER TABLE chat_messages
  ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER message;

-- 4) 未読集計用の複合インデックス
ALTER TABLE chat_messages
  ADD INDEX idx_chat_messages_unread (session_id, role, read_at);

-- 5) ボット↔担当のハンドオフ状態（bot=従来のAI応答 / agent=担当が会話に参加中はAI応答を抑制）
ALTER TABLE chat_sessions
  ADD COLUMN handoff_mode ENUM('bot','agent') NOT NULL DEFAULT 'bot' AFTER visitor_identifier;

-- 6) 添付ファイル（メッセージ:添付 = 1:N）
CREATE TABLE IF NOT EXISTS chat_message_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NULL DEFAULT NULL,
  session_id CHAR(36) NOT NULL,
  business_card_id INT NOT NULL,
  uploaded_by ENUM('customer','agent') NOT NULL,
  kind ENUM('image','pdf','word','excel','other') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(512) NOT NULL,
  mime_type VARCHAR(127) NOT NULL,
  byte_size INT NOT NULL,
  width INT NULL,
  height INT NULL,
  thumb_path VARCHAR(512) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
  INDEX idx_attach_message (message_id),
  INDEX idx_attach_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
