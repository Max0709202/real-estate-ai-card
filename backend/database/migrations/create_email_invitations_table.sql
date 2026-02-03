-- Email Invitations Table
-- Stores imported contacts for sending invitation emails

CREATE TABLE IF NOT EXISTS email_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255),
    email VARCHAR(255) NOT NULL UNIQUE,
    role_type ENUM('new', 'existing') DEFAULT 'new',
    email_sent TINYINT(1) DEFAULT 0,
    sent_at TIMESTAMP NULL,
    invitation_token VARCHAR(64) UNIQUE NULL COMMENT '招待トークン',
    invitation_token_expires_at TIMESTAMP NULL COMMENT '招待トークンの有効期限（15分）',
    is_era_member TINYINT(1) DEFAULT 0 COMMENT 'ERA会員かどうか',
    imported_by INT NOT NULL COMMENT 'Admin ID who imported',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (imported_by) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_email (email),
    INDEX idx_role_type (role_type),
    INDEX idx_email_sent (email_sent),
    INDEX idx_invitation_token (invitation_token),
    INDEX idx_invitation_token_expires (invitation_token_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

