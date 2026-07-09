CREATE TABLE IF NOT EXISTS chat_verified_phones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_card_id INT NOT NULL,
    phone_e164 VARCHAR(32) NOT NULL,
    phone_normalized VARCHAR(32) NOT NULL,
    display_phone VARCHAR(50) NULL,
    firebase_uid VARCHAR(128) NULL,
    customer_name VARCHAR(255) NULL,
    last_session_id CHAR(36) NULL,
    first_verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_chat_verified_phone_card_phone (business_card_id, phone_normalized),
    INDEX idx_chat_verified_phone_card (business_card_id),
    INDEX idx_chat_verified_phone_session (last_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_session_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    visitor_identifier VARCHAR(128) NOT NULL,
    phone_normalized VARCHAR(32) NULL,
    customer_name VARCHAR(255) NULL,
    verified_until DATETIME NULL,
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chat_session_device (session_id, visitor_identifier),
    INDEX idx_chat_session_device_session (session_id),
    INDEX idx_chat_session_device_verified (session_id, visitor_identifier, verified_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
