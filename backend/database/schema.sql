-- 不動産AI名刺 データベーススキーマ
-- Database Schema for Real Estate AI Business Card System

-- ユーザーテーブル
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    user_type ENUM('new', 'existing') NOT NULL DEFAULT 'new',
    status ENUM('pending', 'active', 'suspended', 'cancelled') NOT NULL DEFAULT 'pending',
    email_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(255),
    verification_token_expires_at TIMESTAMP NULL,
    password_reset_token VARCHAR(255),
    password_reset_token_expires_at TIMESTAMP NULL,
    email_reset_token VARCHAR(255),
    email_reset_token_expires_at TIMESTAMP NULL,
    email_reset_new_email VARCHAR(255),
    is_era_member TINYINT(1) DEFAULT 0 COMMENT 'ERA会員かどうか',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ビジネスカードテーブル
CREATE TABLE IF NOT EXISTS business_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    url_slug VARCHAR(255) NOT NULL UNIQUE,
    
    -- ヘッダー・挨拶部
    company_name VARCHAR(255),
    company_logo VARCHAR(500),
    profile_photo VARCHAR(500),
    
    -- 会社プロフィール部
    real_estate_license_prefecture VARCHAR(10),
    real_estate_license_renewal_number INT,
    real_estate_license_registration_number VARCHAR(50),
    company_postal_code VARCHAR(10),
    company_address VARCHAR(500),
    company_phone VARCHAR(20),
    company_website VARCHAR(500),
    
    -- 個人プロフィール部
    branch_department VARCHAR(255),
    position VARCHAR(255),
    name VARCHAR(255) NOT NULL,
    name_romaji VARCHAR(255),
    mobile_phone VARCHAR(20) NOT NULL,
    birth_date DATE,
    current_residence VARCHAR(255),
    hometown VARCHAR(255),
    alma_mater VARCHAR(255),
    qualifications TEXT,
    hobbies TEXT,
    free_input TEXT,
    
    -- QRコード
    qr_code VARCHAR(500),
    qr_code_issued BOOLEAN DEFAULT FALSE,
    qr_code_issued_at TIMESTAMP NULL,
    
    -- ステータス
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    is_published BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_url_slug (url_slug),
    INDEX idx_user_id (user_id),
    INDEX idx_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 挨拶文テーブル
CREATE TABLE IF NOT EXISTS greeting_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_card_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_business_card_id (business_card_id),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- テックツール選択テーブル
CREATE TABLE IF NOT EXISTS tech_tool_selections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_card_id INT NOT NULL,
    tool_type ENUM('mdb', 'rlp', 'llp', 'ai', 'slp', 'olp', 'alp') NOT NULL,
    tool_url VARCHAR(500) NOT NULL,
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_business_card_id (business_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- コミュニケーション機能テーブル
CREATE TABLE IF NOT EXISTS communication_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_card_id INT NOT NULL,
    method_type ENUM('line', 'messenger', 'whatsapp', 'plus_message', 'chatwork', 'andpad', 'instagram', 'facebook', 'twitter', 'youtube', 'tiktok', 'note', 'pinterest', 'threads') NOT NULL,
    method_name VARCHAR(255) NOT NULL,
    method_url VARCHAR(500),
    method_id VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_business_card_id (business_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 決済テーブル
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_card_id INT NOT NULL,
    payment_type ENUM('new_user', 'existing_user') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    tax_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('credit_card', 'bank_transfer') NOT NULL,
    payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    stripe_payment_intent_id VARCHAR(255),
    stripe_subscription_id VARCHAR(255),
    bank_transfer_reference VARCHAR(255),
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サブスクリプションテーブル
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_card_id INT NOT NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('active', 'cancelled', 'expired') DEFAULT 'active',
    amount DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly', 'yearly') DEFAULT 'monthly',
    next_billing_date DATE,
    cancelled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- アクセスログテーブル
CREATE TABLE IF NOT EXISTS access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_card_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_business_card_id (business_card_id),
    INDEX idx_accessed_at (accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 既存ユーザー（ERA含む）のIPアドレス記録テーブル
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

-- 管理者テーブル
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'client') NOT NULL DEFAULT 'client',
    last_password_change TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_password_changed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL,
    
    FOREIGN KEY (last_password_changed_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- パスワード変更履歴テーブル
CREATE TABLE IF NOT EXISTS admin_password_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    changed_by INT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_admin_id (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 設定テーブル
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- テックツールURL生成用カウンター（新規ユーザー用）
CREATE TABLE IF NOT EXISTS tech_tool_url_counter (
    id INT AUTO_INCREMENT PRIMARY KEY,
    current_number INT NOT NULL DEFAULT 5001,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初期データ挿入
INSERT INTO tech_tool_url_counter (current_number) VALUES (5001);

-- 初期管理者アカウント（パスワード: Admin@2024!Secure - 本番環境で変更推奨）
-- Initial admin account (Password: Admin@2024!Secure - Recommended to change in production)
-- 注意: パスワードはハッシュ化されています。ログイン時は "Admin@2024!Secure" を使用してください。
-- Note: Password is hashed. Use "Admin@2024!Secure" when logging in.
-- このパスワードはより安全で、データ侵害警告をトリガーしません。
-- This password is more secure and should not trigger breach warnings.
DELETE FROM admins WHERE email = 'admin@rchukai.jp';

-- パスワード "Admin@2024!Secure" のハッシュ値
-- Hashed password for "Admin@2024!Secure"
INSERT INTO admins (email, password_hash, role) 
VALUES ('admin@rchukai.jp', '$2y$10$d3CQNK1ciFvSGyyC6e2swuZwgEDpB0fa5P4nU6ZtDDfvUqC2hIw3.', 'admin');



-- 通知メール設定
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('notification_email', 'web@rchukai.jp', '新規登録通知メール送信先'),
('base_url', 'https://www.ai-fcard.com', 'ベースURL'),
('stripe_publishable_key', '', 'Stripe公開キー'),
('stripe_secret_key', '', 'Stripeシークレットキー'),
('stripe_webhook_secret', '', 'Stripe Webhookシークレット');


