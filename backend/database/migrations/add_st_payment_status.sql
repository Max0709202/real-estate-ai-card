-- Migration: Add ST (Stripe Bank Transfer) payment status
-- This adds a new payment status for Stripe bank transfers that should be treated like credit card payments

-- Step 1: Modify payment_status enum to include 'ST'
ALTER TABLE business_cards
MODIFY COLUMN payment_status ENUM('CR', 'BANK_PENDING', 'BANK_PAID', 'ST', 'UNUSED') DEFAULT 'UNUSED';

-- Step 2: Update enforceOpenPaymentStatusRule function logic
-- Note: This is handled in PHP code, not SQL

-- Notes:
-- - 'ST' = Stripe Bank Transfer (Stripe口座への送金)
-- - ST status should allow OPEN (is_published = 1) like CR status
-- - ST status should have green background like CR status
