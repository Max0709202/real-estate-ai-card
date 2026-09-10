-- Migration: Add usage_expires_at to business_cards
--
-- 既存・ＥＲＡ会員は月額請求が無くサブスクリプションを持たないため、
-- 管理画面（振込済への変更時）で入力した「利用期限」を保存する先が無く、破棄されていた。
-- その結果、利用期限を過ぎても名刺が公開されたままになっていた。
--
-- 月額請求ユーザーの利用期限は従来どおり subscriptions.next_billing_date（の前日）で管理するため、
-- この列は月額請求が無いユーザー（user_type='existing' または ＥＲＡ会員）にのみ設定する。
--
-- Run this on your database after taking a backup.

SET @dbname = DATABASE();
SET @tablename = 'business_cards';
SET @columnname = 'usage_expires_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' DATE NULL COMMENT ''利用期限（月額請求が無い既存・ＥＲＡ会員向け。当日まで利用可）'' AFTER payment_status')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 期限切れ判定を高速化するインデックス（未作成の場合のみ）
SET @indexname = 'idx_business_cards_usage_expires_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE table_name = @tablename AND table_schema = @dbname AND index_name = @indexname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname, ' (usage_expires_at)')
));
PREPARE addIndexIfNotExists FROM @preparedStatement;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;
