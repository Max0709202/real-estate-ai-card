-- One-time fix script: Close business cards that are OPEN but payment_status is not CR or BANK_PAID
-- This corrects any inconsistent data where is_published=1 but payment_status doesn't allow OPEN

-- Step 1: Find and log invalid OPEN records
-- (This is informational - you can run this first to see what will be fixed)
SELECT 
    bc.id,
    bc.url_slug,
    bc.is_published,
    bc.payment_status,
    u.email
FROM business_cards bc
JOIN users u ON bc.user_id = u.id
WHERE bc.is_published = 1 
  AND bc.payment_status NOT IN ('CR', 'BANK_PAID');

-- Step 2: Close invalid OPEN records
UPDATE business_cards
SET is_published = 0
WHERE is_published = 1 
  AND payment_status NOT IN ('CR', 'BANK_PAID');

-- Step 3: Verify fix (should return 0 rows)
SELECT 
    COUNT(*) as invalid_open_count
FROM business_cards
WHERE is_published = 1 
  AND payment_status NOT IN ('CR', 'BANK_PAID');

