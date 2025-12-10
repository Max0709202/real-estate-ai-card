-- Email Logs Table Migration
-- Tracks email sending history with delivery times and status

CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_type ENUM('business', 'personal', 'unknown') DEFAULT 'unknown',
    subject VARCHAR(500) NOT NULL,
    email_type VARCHAR(100) NOT NULL, -- 'verification', 'notification', 'password_reset', etc.
    status ENUM('pending', 'sent', 'failed', 'bounced') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    delivery_time_ms INT NULL, -- Time taken in milliseconds
    smtp_response TEXT,
    error_message TEXT,
    user_id INT NULL,
    related_id INT NULL, -- Related user_id or business_card_id, etc.
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_recipient_email (recipient_email),
    INDEX idx_status (status),
    INDEX idx_email_type (email_type),
    INDEX idx_sent_at (sent_at),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Function to detect email type
-- Business emails typically have custom domains (not gmail, yahoo, outlook, etc.)
-- This will be determined in PHP code

