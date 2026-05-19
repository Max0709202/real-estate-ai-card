-- Separate customer contact details captured from chatbot conversations.
CREATE TABLE IF NOT EXISTS chat_lead_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL UNIQUE,
    business_card_id INT NOT NULL,
    customer_name VARCHAR(255) NULL,
    contact_method VARCHAR(50) NULL,
    contact_value VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    line_id VARCHAR(255) NULL,
    raw_contact TEXT NULL,
    consent_given TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_chat_lead_contacts_card (business_card_id),
    INDEX idx_chat_lead_contacts_method (contact_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
