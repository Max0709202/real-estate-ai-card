-- Migration: Make stripe_subscription_id nullable in subscriptions table
-- Date: 2026-01-10
-- Description: Allows stripe_subscription_id to be NULL for cases where subscription is created without Stripe (e.g., bank transfer) or when Stripe subscription creation fails

-- Note: In MySQL, you cannot directly drop a UNIQUE constraint that is part of the column definition
-- You need to drop the index first, then modify the column, then re-add the index

-- Step 1: Drop the unique index if it exists (the column may have a UNIQUE constraint via index)
-- Check if index exists and drop it
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'subscriptions' 
      AND INDEX_NAME = 'stripe_subscription_id'
);

SET @sql = IF(@index_exists > 0, 
    'ALTER TABLE subscriptions DROP INDEX stripe_subscription_id',
    'SELECT "Index does not exist or already dropped" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Modify column to allow NULL
ALTER TABLE subscriptions 
MODIFY COLUMN stripe_subscription_id VARCHAR(255) NULL;

-- Step 3: Re-add UNIQUE index (NULL values are ignored by UNIQUE constraint in MySQL)
-- This allows multiple NULL values but ensures uniqueness for non-NULL values
ALTER TABLE subscriptions 
ADD UNIQUE INDEX idx_stripe_subscription_id (stripe_subscription_id);
