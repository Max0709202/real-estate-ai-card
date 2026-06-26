-- 物件選定機能（顧客が検討している物件を一元管理）
-- 提案物件情報管理 / 販売図面（写真）管理 / ハザード確認 / 顧客共有物件管理 / 内見予約連動
-- 物件は chat_sessions（顧客↔担当エージェントの関係）に紐づく。

-- 1) 提案物件マスタ
CREATE TABLE IF NOT EXISTS properties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_card_id INT NOT NULL,
  session_id CHAR(36) NOT NULL,

  -- 提案元（§3）: agent=エージェント提案 / customer=お客様から共有
  source ENUM('agent','customer') NOT NULL DEFAULT 'agent',
  -- 掲載媒体（§19）: suumo / homes / athome / yahoo / flyer(販売図面) / manual(手入力) / photo(撮影) / other
  source_media VARCHAR(32) NOT NULL DEFAULT 'manual',
  source_url VARCHAR(1024) NULL DEFAULT NULL,

  -- 検討/対応ステータス（§5）
  -- 顧客のみ: viewing_request(内見希望) / considering(検討中) / passed(見送り) / application(申込検討)
  -- 担当のみ: brokerage_ok(仲介可) / not_introducible(ご紹介不可)
  status VARCHAR(24) NULL DEFAULT NULL,

  -- 物件種別: mansion(マンション) / house(一戸建て) / land(土地)
  property_type ENUM('mansion','house','land') NOT NULL DEFAULT 'mansion',

  -- 基本情報（§7 / §11）
  property_name VARCHAR(255) NULL DEFAULT NULL,   -- 物件名
  building_name VARCHAR(255) NULL DEFAULT NULL,   -- マンション名（戸建/土地は「川口市弥平2戸建て」等）
  price_text VARCHAR(64) NULL DEFAULT NULL,       -- 価格（表示用 例:5,800万円）
  price_man INT NULL DEFAULT NULL,                -- 価格（万円・数値・並び替え用）
  address VARCHAR(255) NULL DEFAULT NULL,         -- 所在地
  transport VARCHAR(512) NULL DEFAULT NULL,       -- 交通
  exclusive_area VARCHAR(64) NULL DEFAULT NULL,   -- 専有面積（マンション）
  land_area VARCHAR(64) NULL DEFAULT NULL,        -- 土地面積（戸建/土地）
  building_area VARCHAR(64) NULL DEFAULT NULL,    -- 建物面積（戸建）
  balcony_area VARCHAR(64) NULL DEFAULT NULL,     -- バルコニー面積
  layout VARCHAR(64) NULL DEFAULT NULL,           -- 間取り
  built_year_month VARCHAR(32) NULL DEFAULT NULL, -- 築年月
  floor VARCHAR(64) NULL DEFAULT NULL,            -- 所在階
  room_number VARCHAR(64) NULL DEFAULT NULL,      -- 部屋番号
  total_units VARCHAR(32) NULL DEFAULT NULL,      -- 総戸数
  structure VARCHAR(64) NULL DEFAULT NULL,        -- 構造
  land_right VARCHAR(64) NULL DEFAULT NULL,       -- 土地権利
  management_form VARCHAR(64) NULL DEFAULT NULL,  -- 管理形態
  management_company VARCHAR(128) NULL DEFAULT NULL, -- 管理会社
  management_fee VARCHAR(64) NULL DEFAULT NULL,   -- 管理費
  repair_reserve VARCHAR(64) NULL DEFAULT NULL,   -- 修繕積立金
  other_fees VARCHAR(255) NULL DEFAULT NULL,      -- その他費用
  current_status VARCHAR(32) NULL DEFAULT NULL,   -- 現況（空室/居住中/賃貸中）
  delivery VARCHAR(64) NULL DEFAULT NULL,         -- 引渡
  transaction_type VARCHAR(64) NULL DEFAULT NULL, -- 取引態様
  rent VARCHAR(64) NULL DEFAULT NULL,             -- 賃料
  yield_rate VARCHAR(64) NULL DEFAULT NULL,       -- 利回り
  remarks TEXT NULL DEFAULT NULL,                 -- 備考（基本情報・顧客にも表示）

  -- 売主仲介会社情報（§11/§19・エージェント画面のみ表示）
  seller_company VARCHAR(255) NULL DEFAULT NULL,  -- 販売会社名
  seller_branch VARCHAR(255) NULL DEFAULT NULL,   -- 支店名
  seller_person VARCHAR(128) NULL DEFAULT NULL,   -- 担当者名
  seller_email VARCHAR(255) NULL DEFAULT NULL,    -- メールアドレス
  seller_phone VARCHAR(64) NULL DEFAULT NULL,     -- 販売会社電話番号
  seller_remarks TEXT NULL DEFAULT NULL,          -- 備考（フリー入力）

  -- 物件カードのメイン画像（販売図面 or 写真の代表）
  main_image_path VARCHAR(512) NULL DEFAULT NULL,
  -- 一覧サムネイル（建物外観→間取り図から自動選定した property_images.id）
  thumbnail_image_id INT NULL DEFAULT NULL,
  -- 保存期間（既定6か月）。経過後は cron で自動削除
  expires_at TIMESTAMP NULL DEFAULT NULL,

  -- OCR確認フロー（§8）: none=手動 / draft=AI自動保存・未確認 / confirmed=エージェント確認済
  ocr_status ENUM('none','draft','confirmed') NOT NULL DEFAULT 'none',

  -- ハザード等情報（§12/§13・取得結果を保存し再取得まで使い回す）
  hazard_json LONGTEXT NULL DEFAULT NULL,
  hazard_fetched_at TIMESTAMP NULL DEFAULT NULL,

  created_by ENUM('agent','customer') NOT NULL DEFAULT 'agent',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
  FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
  INDEX idx_properties_card (business_card_id),
  INDEX idx_properties_session (session_id),
  INDEX idx_properties_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) 物件画像（販売図面 / 写真・資料）
-- category: flyer=販売図面（§14） / photo=写真,資料等（§15・最大10枚）
CREATE TABLE IF NOT EXISTS property_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  business_card_id INT NOT NULL,
  category ENUM('flyer','photo') NOT NULL DEFAULT 'photo',
  subcategory VARCHAR(32) NULL DEFAULT NULL,   -- 間取り図/外観写真/室内写真/その他資料
  original_name VARCHAR(255) NULL DEFAULT NULL,
  stored_path VARCHAR(512) NOT NULL,
  thumb_path VARCHAR(512) NULL DEFAULT NULL,
  mime_type VARCHAR(127) NULL DEFAULT NULL,
  byte_size INT NULL DEFAULT NULL,
  width INT NULL DEFAULT NULL,
  height INT NULL DEFAULT NULL,
  display_order INT NOT NULL DEFAULT 0,
  -- 販売図面の売主情報マスク（顧客共有時に自動非表示）
  preview_path VARCHAR(512) NULL DEFAULT NULL,   -- 編集・マスク用ラスタJPEG
  masked_path VARCHAR(512) NULL DEFAULT NULL,     -- 顧客用マスク済PDF
  mask_regions TEXT NULL DEFAULT NULL,            -- ページ別マスク領域 {pageIndex:[{x,y,w,h}]} JSON
  mask_status ENUM('none','pending','masked') NOT NULL DEFAULT 'none',
  expires_at TIMESTAMP NULL DEFAULT NULL,         -- 保存期間（既定6か月）。経過後は cron で自動削除
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  INDEX idx_property_images_property (property_id, category, display_order),
  INDEX idx_property_images_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
