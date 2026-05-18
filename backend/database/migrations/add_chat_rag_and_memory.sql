-- Local RAG cache and conversation memory for the real estate chatbot.
CREATE TABLE IF NOT EXISTS chat_knowledge_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(120) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    source_type VARCHAR(50) NOT NULL DEFAULT 'official',
    priority INT NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_fetched_at TIMESTAMP NULL DEFAULT NULL,
    last_status VARCHAR(30) NULL DEFAULT NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_chat_knowledge_sources_enabled (enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_knowledge_chunks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    chunk_index INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_hash CHAR(64) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id) REFERENCES chat_knowledge_sources(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_chat_knowledge_chunk (source_id, chunk_index),
    INDEX idx_chat_knowledge_chunks_source (source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_session_memory (
    session_id CHAR(36) PRIMARY KEY,
    business_card_id INT NULL,
    memory_json JSON NULL,
    last_summary TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_chat_session_memory_card (business_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
