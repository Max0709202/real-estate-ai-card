-- Migration: Add subscription and draft status support
-- This migration adds fields needed for Stripe subscription tracking and draft saving

-- 1. Add stripe_customer_id to users table
ALTER TABLE users
ADD COLUMN stripe_customer_id VARCHAR(255) UNIQUE NULL AFTER email_reset_new_email;

CREATE INDEX idx_stripe_customer_id ON users (stripe_customer_id);

-- 2. Add card_status to business_cards table
ALTER TABLE business_cards
ADD COLUMN card_status ENUM('draft', 'active', 'suspended', 'canceled') NOT NULL DEFAULT 'draft' AFTER payment_status;

CREATE INDEX idx_card_status ON business_cards (card_status);

-- 3. Update subscriptions table to support Stripe subscription statuses
ALTER TABLE subscriptions
MODIFY COLUMN status ENUM('active', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'trialing', 'paused') NOT NULL DEFAULT 'incomplete';

-- 4. Add stripe_customer_id to subscriptions table for easier lookup
ALTER TABLE subscriptions
ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER stripe_subscription_id;

CREATE INDEX idx_subscription_customer_id ON subscriptions (stripe_customer_id);

-- 5. Add webhook_event_log table to prevent duplicate webhook processing
CREATE TABLE IF NOT EXISTS webhook_event_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL UNIQUE,
    event_type VARCHAR(100) NOT NULL,
    processed BOOLEAN DEFAULT FALSE,
    processed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stripe_event_id (stripe_event_id),
    INDEX idx_processed (processed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

