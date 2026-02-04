-- Migration: Create table to store IP addresses for existing/ERA users
-- Run this on your database after taking a backup.

CREATE TABLE IF NOT EXISTS existing_user_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uniq_user_ip (user_id, ip_address),
    KEY idx_ip_address (ip_address),
    
    CONSTRAINT fk_existing_user_ips_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

