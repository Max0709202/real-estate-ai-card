-- 宅建業者ライセンスデータベース
-- MLIT Real Estate License Database
-- This table stores locally cached MLIT license data for fast lookups

CREATE TABLE IF NOT EXISTS `real_estate_licenses` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- 都道府県情報
    `prefecture` VARCHAR(50) NOT NULL COMMENT '都道府県名 (e.g., 東京都, 青森県)',
    `prefecture_code` VARCHAR(8) DEFAULT NULL COMMENT 'MLIT都道府県コード (e.g., 13 for 東京)',

    -- 免許情報
    `issuing_authority` VARCHAR(64) DEFAULT NULL COMMENT '免許権者 (e.g., 東京都知事, 国土交通大臣)',
    `renewal_number` INT UNSIGNED DEFAULT NULL COMMENT '更新回数 (e.g., 4)',
    `registration_number` VARCHAR(50) NOT NULL COMMENT '登録番号 (e.g., 12345, 000586)',

    -- 完全な免許番号テキスト
    `full_license_text` VARCHAR(255) DEFAULT NULL COMMENT '完全な免許番号 (e.g., 東京都知事(4)第12345号)',

    -- 会社情報
    `company_name` VARCHAR(255) DEFAULT NULL COMMENT '商号又は名称',
    `representative_name` VARCHAR(255) DEFAULT NULL COMMENT '代表者名',
    `office_name` VARCHAR(255) DEFAULT NULL COMMENT '事務所名 (e.g., 本店)',
    `address` VARCHAR(512) DEFAULT NULL COMMENT '所在地',
    `phone_number` VARCHAR(50) DEFAULT NULL COMMENT '電話番号',

    -- メタデータ
    `raw_source` TEXT COMMENT 'オリジナルデータ (CSV行/JSON)',
    `data_source` VARCHAR(100) DEFAULT 'mlit_csv' COMMENT 'データソース (mlit_csv, manual, scrape)',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'アクティブフラグ',

    -- タイムスタンプ
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- ユニーク制約: 都道府県コード + 更新回数 + 登録番号で一意
    UNIQUE KEY `idx_license_unique` (`prefecture_code`, `renewal_number`, `registration_number`),

    -- 検索用インデックス
    KEY `idx_prefecture` (`prefecture`),
    KEY `idx_prefecture_code` (`prefecture_code`),
    KEY `idx_registration` (`registration_number`),
    KEY `idx_renewal` (`renewal_number`),
    KEY `idx_full_license` (`full_license_text`),
    KEY `idx_company_name` (`company_name`),
    KEY `idx_is_active` (`is_active`),

    -- 全文検索インデックス (会社名検索用)
    FULLTEXT KEY `ft_company` (`company_name`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='MLIT宅建業者ライセンスローカルデータベース';

-- インポート履歴テーブル
CREATE TABLE IF NOT EXISTS `license_import_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_type` ENUM('full', 'incremental', 'manual') NOT NULL DEFAULT 'full',
    `file_name` VARCHAR(255) DEFAULT NULL,
    `records_processed` INT UNSIGNED DEFAULT 0,
    `records_inserted` INT UNSIGNED DEFAULT 0,
    `records_updated` INT UNSIGNED DEFAULT 0,
    `records_failed` INT UNSIGNED DEFAULT 0,
    `status` ENUM('running', 'completed', 'failed') DEFAULT 'running',
    `error_message` TEXT,
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_started_at` (`started_at`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ライセンスデータインポート履歴';

-- 都道府県コードマスター (検索用)
CREATE TABLE IF NOT EXISTS `prefecture_codes` (
    `code` VARCHAR(8) NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `authority_name` VARCHAR(64) NOT NULL COMMENT '知事名 (e.g., 東京都知事)',

    PRIMARY KEY (`code`),
    UNIQUE KEY `idx_name` (`name`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='都道府県コードマスター';

-- 都道府県コード初期データ
INSERT IGNORE INTO `prefecture_codes` (`code`, `name`, `authority_name`) VALUES
('01', '北海道', '北海道知事'),
('02', '青森県', '青森県知事'),
('03', '岩手県', '岩手県知事'),
('04', '宮城県', '宮城県知事'),
('05', '秋田県', '秋田県知事'),
('06', '山形県', '山形県知事'),
('07', '福島県', '福島県知事'),
('08', '茨城県', '茨城県知事'),
('09', '栃木県', '栃木県知事'),
('10', '群馬県', '群馬県知事'),
('11', '埼玉県', '埼玉県知事'),
('12', '千葉県', '千葉県知事'),
('13', '東京都', '東京都知事'),
('14', '神奈川県', '神奈川県知事'),
('15', '新潟県', '新潟県知事'),
('16', '富山県', '富山県知事'),
('17', '石川県', '石川県知事'),
('18', '福井県', '福井県知事'),
('19', '山梨県', '山梨県知事'),
('20', '長野県', '長野県知事'),
('21', '岐阜県', '岐阜県知事'),
('22', '静岡県', '静岡県知事'),
('23', '愛知県', '愛知県知事'),
('24', '三重県', '三重県知事'),
('25', '滋賀県', '滋賀県知事'),
('26', '京都府', '京都府知事'),
('27', '大阪府', '大阪府知事'),
('28', '兵庫県', '兵庫県知事'),
('29', '奈良県', '奈良県知事'),
('30', '和歌山県', '和歌山県知事'),
('31', '鳥取県', '鳥取県知事'),
('32', '島根県', '島根県知事'),
('33', '岡山県', '岡山県知事'),
('34', '広島県', '広島県知事'),
('35', '山口県', '山口県知事'),
('36', '徳島県', '徳島県知事'),
('37', '香川県', '香川県知事'),
('38', '愛媛県', '愛媛県知事'),
('39', '高知県', '高知県知事'),
('40', '福岡県', '福岡県知事'),
('41', '佐賀県', '佐賀県知事'),
('42', '長崎県', '長崎県知事'),
('43', '熊本県', '熊本県知事'),
('44', '大分県', '大分県知事'),
('45', '宮崎県', '宮崎県知事'),
('46', '鹿児島県', '鹿児島県知事'),
('47', '沖縄県', '沖縄県知事'),
('99', '国土交通大臣', '国土交通大臣');

