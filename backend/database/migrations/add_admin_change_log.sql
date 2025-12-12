-- Admin Change Log Table
-- Records all changes made by admins to user data

CREATE TABLE IF NOT EXISTS admin_change_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    admin_email VARCHAR(255) NOT NULL,
    change_type ENUM('payment_confirmed', 'qr_code_issued', 'published_changed', 'user_deleted', 'other') NOT NULL,
    target_type ENUM('user', 'business_card', 'payment', 'other') NOT NULL,
    target_id INT,
    description TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id),
    INDEX idx_changed_at (changed_at),
    INDEX idx_change_type (change_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

