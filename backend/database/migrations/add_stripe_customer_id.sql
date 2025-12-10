-- Migration: Add stripe_customer_id column to users table
-- Date: 2024
-- Description: Adds Stripe Customer ID column to store Stripe customer reference

-- Check if column exists before adding
SET @dbname = DATABASE();
SET @tablename = "users";
SET @columnname = "stripe_customer_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 'Column already exists.' AS result;",
  CONCAT("ALTER TABLE ", @tablename, " ADD COLUMN ", @columnname, " VARCHAR(255) NULL AFTER last_login_at;")
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index for faster lookups
CREATE INDEX IF NOT EXISTS idx_stripe_customer_id ON users(stripe_customer_id);

