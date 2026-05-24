-- OpenAI API usage logging for chat model routing.
CREATE TABLE IF NOT EXISTS chat_openai_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NULL,
    business_card_id INT NULL,
    purpose VARCHAR(60) NOT NULL DEFAULT 'chat',
    requested_model VARCHAR(120) NOT NULL,
    response_model VARCHAR(120) NULL,
    prompt_tokens INT NULL,
    completion_tokens INT NULL,
    total_tokens INT NULL,
    http_status INT NULL,
    error_message TEXT NULL,
    duration_ms INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_openai_usage_session (session_id),
    INDEX idx_chat_openai_usage_card_created (business_card_id, created_at),
    INDEX idx_chat_openai_usage_model_created (requested_model, created_at),
    INDEX idx_chat_openai_usage_purpose_created (purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
