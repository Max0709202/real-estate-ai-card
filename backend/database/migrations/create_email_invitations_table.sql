-- Email Invitations Table
-- Stores imported contacts for sending invitation emails

CREATE TABLE IF NOT EXISTS email_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255),
    email VARCHAR(255) NOT NULL UNIQUE,
    role_type ENUM('new', 'existing', 'free') DEFAULT 'new',
    email_sent TINYINT(1) DEFAULT 0,
    sent_at TIMESTAMP NULL,
    imported_by INT NOT NULL COMMENT 'Admin ID who imported',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (imported_by) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_email (email),
    INDEX idx_role_type (role_type),
    INDEX idx_email_sent (email_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

