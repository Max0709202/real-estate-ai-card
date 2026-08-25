-- 物件選定: 提案物件のフォルダー管理。
-- 担当者が「2026年8月21日ご案内物件」のような名前のフォルダーを作り、提案物件を格納できるようにする。
-- 一覧（担当のマイページ / お客様の物件選定画面）は、フォルダーを上に、
-- フォルダーに入っていない物件をその下に表示する。
-- フォルダーは chat_sessions（顧客↔担当エージェントの関係）単位で管理する。

CREATE TABLE IF NOT EXISTS property_folders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_card_id INT NOT NULL,
  session_id CHAR(36) NOT NULL,
  name VARCHAR(100) NOT NULL,              -- 担当者が付けたフォルダー名（例: 2026年8月21日ご案内物件）
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
  FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
  INDEX idx_property_folders_session (session_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 物件の格納先フォルダー。NULL = フォルダーに入っていない（一覧ではフォルダーの下に表示）。
-- フォルダーを削除しても中の物件は削除せず、フォルダー未格納（NULL）に戻す。
ALTER TABLE properties
  ADD COLUMN folder_id INT NULL DEFAULT NULL AFTER session_id,
  ADD INDEX idx_properties_folder (folder_id);
