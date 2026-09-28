-- 情報BOX（「不動産AI名刺_情報BOX開発仕様書 2026.9.24」）
-- backend/includes/infobox-helper.php の iboxEnsureTables() / iboxEnsureLicenseColumn() と同じ定義。
-- 本番へ先に流しておくと、初回アクセス時の CREATE TABLE / ALTER TABLE を省ける。

-- 会社（免許番号）ごとの ON / OFF。階層機能と同じ org_license_settings に列を足す。
-- （20260810_add_org_license_settings.sql 適用後に実行。列が既にある場合はこの1文をスキップ）
ALTER TABLE org_license_settings
    ADD COLUMN infobox_enabled TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=情報BOXを使える / 0=使えない';

CREATE TABLE IF NOT EXISTS ibox_boxes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT NOT NULL,
  business_card_id INT NULL DEFAULT NULL,
  property_id INT NULL DEFAULT NULL,
  transaction_code VARCHAR(40) NOT NULL,
  property_name VARCHAR(255) NULL DEFAULT NULL,
  address VARCHAR(255) NULL DEFAULT NULL,
  price BIGINT NULL DEFAULT NULL,
  property_type ENUM('mansion','house') NOT NULL DEFAULT 'mansion',
  contract_planned_date DATE NULL DEFAULT NULL,
  owner_side ENUM('buyer','seller') NOT NULL DEFAULT 'buyer',
  dual_agency TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','ended') NOT NULL DEFAULT 'active',
  end_date DATE NULL DEFAULT NULL,
  access_until DATETIME NULL DEFAULT NULL,
  ended_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uk_ibox_boxes_code (transaction_code),
  INDEX idx_ibox_boxes_owner (owner_user_id, status),
  INDEX idx_ibox_boxes_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  box_id INT NOT NULL,
  role VARCHAR(24) NOT NULL,
  is_owner TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT NULL DEFAULT NULL,
  company_name VARCHAR(255) NULL DEFAULT NULL,
  name VARCHAR(128) NULL DEFAULT NULL,
  address VARCHAR(255) NULL DEFAULT NULL,
  email VARCHAR(255) NULL DEFAULT NULL,
  phone VARCHAR(64) NULL DEFAULT NULL,
  phone_norm VARCHAR(32) NULL DEFAULT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  continue_access TINYINT(1) NOT NULL DEFAULT 0,
  registered_by INT NULL DEFAULT NULL,
  updated_by INT NULL DEFAULT NULL,
  notify_status ENUM('unsent','sending','sent','failed') NOT NULL DEFAULT 'unsent',
  notified_at DATETIME NULL DEFAULT NULL,
  notify_error VARCHAR(255) NULL DEFAULT NULL,
  auth_version INT NOT NULL DEFAULT 1,
  fail_count INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL DEFAULT NULL,
  first_access_at DATETIME NULL DEFAULT NULL,
  last_access_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_ibox_participants_box (box_id, status),
  INDEX idx_ibox_participants_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_invites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  participant_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  auth_version INT NOT NULL DEFAULT 1,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_ibox_invites_token (token_hash),
  INDEX idx_ibox_invites_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_folders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  box_id INT NOT NULL,
  template_id VARCHAR(8) NULL DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  target_name VARCHAR(128) NULL DEFAULT NULL,
  is_initial TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL DEFAULT NULL,
  list_principals TEXT NULL DEFAULT NULL,
  upload_principals TEXT NULL DEFAULT NULL,
  unneeded_state ENUM('none','requested','confirmed') NOT NULL DEFAULT 'none',
  unneeded_reason VARCHAR(500) NULL DEFAULT NULL,
  unneeded_requested_by INT NULL DEFAULT NULL,
  unneeded_confirmed_by INT NULL DEFAULT NULL,
  unneeded_at DATETIME NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_ibox_folders_box (box_id, deleted_at, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  box_id INT NOT NULL,
  folder_id INT NOT NULL,
  uploader_id INT NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  current_version INT NOT NULL DEFAULT 1,
  status ENUM('active','deleted') NOT NULL DEFAULT 'active',
  op_key VARCHAR(64) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uk_ibox_documents_op (uploader_id, op_key),
  INDEX idx_ibox_documents_folder (folder_id, status),
  INDEX idx_ibox_documents_box (box_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_document_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  version INT NOT NULL,
  stored_name VARCHAR(128) NOT NULL,
  preview_name VARCHAR(128) NULL DEFAULT NULL,
  original_name VARCHAR(255) NOT NULL,
  ext VARCHAR(8) NOT NULL,
  mime_type VARCHAR(127) NOT NULL,
  byte_size INT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NULL DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_ibox_document_versions (document_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_document_shares (
  document_id INT NOT NULL,
  participant_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (document_id, participant_id),
  INDEX idx_ibox_document_shares_p (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_originals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  box_id INT NOT NULL,
  folder_id INT NOT NULL,
  created_by INT NOT NULL,
  document_id INT NULL DEFAULT NULL,
  target_name VARCHAR(128) NULL DEFAULT NULL,
  doc_name VARCHAR(255) NOT NULL,
  required_state ENUM('unknown','required','not_required') NOT NULL DEFAULT 'unknown',
  copies INT NULL DEFAULT NULL,
  submit_to VARCHAR(255) NULL DEFAULT NULL,
  deadline DATE NULL DEFAULT NULL,
  issued_date DATE NULL DEFAULT NULL,
  deadline_condition VARCHAR(500) NULL DEFAULT NULL,
  keeper VARCHAR(128) NULL DEFAULT NULL,
  remarks TEXT NULL DEFAULT NULL,
  status ENUM('unconfirmed','requesting','obtained','submitted','returned','not_needed') NOT NULL DEFAULT 'unconfirmed',
  viewers TEXT NULL DEFAULT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_ibox_originals_folder (folder_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_original_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  original_id INT NOT NULL,
  status VARCHAR(16) NOT NULL,
  event_date DATE NULL DEFAULT NULL,
  copies INT NULL DEFAULT NULL,
  counterparty VARCHAR(255) NULL DEFAULT NULL,
  confirmed_by_name VARCHAR(128) NULL DEFAULT NULL,
  note VARCHAR(500) NULL DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_ibox_original_events (original_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  box_id INT NOT NULL,
  kind ENUM('all','direct') NOT NULL DEFAULT 'all',
  p_low INT NOT NULL DEFAULT 0,
  p_high INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_ibox_conversations (box_id, kind, p_low, p_high)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  box_id INT NOT NULL,
  sender_id INT NOT NULL,
  body TEXT NULL DEFAULT NULL,
  attach_stored VARCHAR(128) NULL DEFAULT NULL,
  attach_name VARCHAR(255) NULL DEFAULT NULL,
  attach_mime VARCHAR(127) NULL DEFAULT NULL,
  attach_size INT NULL DEFAULT NULL,
  client_uid VARCHAR(64) NULL DEFAULT NULL,
  edited_at DATETIME NULL DEFAULT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_ibox_messages_uid (conversation_id, sender_id, client_uid),
  INDEX idx_ibox_messages_conv (conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_message_reads (
  conversation_id INT NOT NULL,
  participant_id INT NOT NULL,
  last_read_id INT NOT NULL DEFAULT 0,
  PRIMARY KEY (conversation_id, participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_ledger_drafts (
  box_id INT NOT NULL PRIMARY KEY,
  data_json LONGTEXT NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_ledgers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  box_id INT NOT NULL,
  owner_user_id INT NOT NULL,
  version INT NOT NULL,
  ledger_no VARCHAR(64) NULL DEFAULT NULL,
  office_name VARCHAR(255) NULL DEFAULT NULL,
  fiscal_year INT NULL DEFAULT NULL,
  data_json LONGTEXT NOT NULL,
  pdf_name VARCHAR(128) NOT NULL,
  reason VARCHAR(500) NULL DEFAULT NULL,
  op_key VARCHAR(64) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_ibox_ledgers_version (box_id, version),
  UNIQUE KEY uk_ibox_ledgers_op (box_id, op_key),
  INDEX idx_ibox_ledgers_owner (owner_user_id, fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibox_audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  box_id INT NOT NULL,
  participant_id INT NULL DEFAULT NULL,
  action VARCHAR(48) NOT NULL,
  target_type VARCHAR(24) NULL DEFAULT NULL,
  target_id INT NULL DEFAULT NULL,
  detail TEXT NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_ibox_audit_box (box_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
