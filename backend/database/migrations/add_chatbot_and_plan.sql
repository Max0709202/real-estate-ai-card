-- Chatbot + Plan gating: chat tables and optional plan_type on business_cards
-- Run once. If plan_type column already exists, skip the ALTER (or run add_plan_type.sql only once).

-- 1. Add plan_type to business_cards (Entry vs Standard) - optional, skip if column exists
-- ALTER TABLE business_cards ADD COLUMN plan_type VARCHAR(20) NOT NULL DEFAULT 'standard' COMMENT 'entry|standard' AFTER payment_status;

-- 2. Chat sessions (one per visitor/card)
CREATE TABLE IF NOT EXISTS chat_sessions (
    id CHAR(36) PRIMARY KEY,
    business_card_id INT NOT NULL,
    visitor_identifier VARCHAR(255) NULL COMMENT 'optional cookie/fingerprint',
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_chat_sessions_card (business_card_id),
    INDEX idx_chat_sessions_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Chat messages
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    role ENUM('user','bot','system') NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_chat_messages_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Lead / intake data (structured answers from hearing)
CREATE TABLE IF NOT EXISTS chat_leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL UNIQUE,
    business_card_id INT NOT NULL,
    structured_data JSON NULL COMMENT 'purpose, area, budget, etc.',
    consent_given TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_chat_leads_card (business_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
