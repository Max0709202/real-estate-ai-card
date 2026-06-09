-- Per-message audit trail of public-data / RAG API access from the chatbot.
-- Records which providers were actually invoked for each user message and how
-- many records they returned, so admins can verify which questions used the API
-- (vs. a general-knowledge answer) and how much data was retrieved.
CREATE TABLE IF NOT EXISTS chat_public_data_access_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NULL,
    business_card_id INT NULL,
    user_message TEXT NULL,
    provider VARCHAR(60) NOT NULL,
    record_count INT NULL,
    total_count INT NULL,
    cached TINYINT(1) NOT NULL DEFAULT 0,
    fetched_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_pda_log_session (session_id),
    INDEX idx_chat_pda_log_card_created (business_card_id, created_at),
    INDEX idx_chat_pda_log_provider_created (provider, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
