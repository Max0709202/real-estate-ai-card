-- Migration: Update payment_status column in business_cards table
-- This replaces the old enum values with new status badge system values

-- Step 1: Add a temporary column for migration
ALTER TABLE business_cards
ADD COLUMN payment_status_new ENUM('CR', 'BANK_PENDING', 'BANK_PAID', 'UNUSED') DEFAULT 'UNUSED' AFTER is_published;

-- Step 2: Migrate existing data from payments table and old payment_status column
-- Map existing payment data to new payment_status values
UPDATE business_cards bc
LEFT JOIN (
    SELECT 
        business_card_id,
        MAX(CASE 
            WHEN payment_status = 'completed' AND payment_method = 'credit_card' THEN 'CR'
            WHEN payment_status = 'completed' AND payment_method = 'bank_transfer' THEN 'BANK_PAID'
            WHEN payment_status = 'pending' AND payment_method = 'bank_transfer' THEN 'BANK_PENDING'
            ELSE NULL
        END) as calculated_status
    FROM payments
    GROUP BY business_card_id
) p ON bc.id = p.business_card_id
SET bc.payment_status_new = COALESCE(
    p.calculated_status,
    CASE 
        -- Handle old payment_status enum values if they exist
        WHEN bc.payment_status = 'paid' THEN 'CR'  -- Old 'paid' → 'CR' (assume credit)
        ELSE 'UNUSED'
    END,
    'UNUSED'
);

-- Step 3: Drop old payment_status column and rename new one
ALTER TABLE business_cards
DROP COLUMN payment_status;

ALTER TABLE business_cards
CHANGE COLUMN payment_status_new payment_status ENUM('CR', 'BANK_PENDING', 'BANK_PAID', 'UNUSED') DEFAULT 'UNUSED';

-- Step 4: Add index for faster filtering
CREATE INDEX idx_payment_status ON business_cards(payment_status);

-- Notes:
-- - Existing records with completed credit payments → 'CR'
-- - Existing records with completed bank transfers → 'BANK_PAID'
-- - Existing records with pending bank transfers → 'BANK_PENDING'
-- - Records without payment records or abandoned → 'UNUSED'

