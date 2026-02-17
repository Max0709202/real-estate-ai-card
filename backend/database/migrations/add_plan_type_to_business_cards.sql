-- Add plan_type to business_cards for Entry vs Standard (chatbot + loan sim gating).
-- Run once. If the column already exists, this ALTER will error - safe to ignore.

ALTER TABLE business_cards
ADD COLUMN plan_type VARCHAR(20) NOT NULL DEFAULT 'standard'
COMMENT 'entry=DX+SNS only, standard=+chatbot+loan_sim';
