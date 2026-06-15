-- Add referral / UTM tracking fields for agency and campaign attribution.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS agent VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_source VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_medium VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_campaign VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS first_accessed_at DATETIME NULL;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS agent VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_source VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_medium VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_campaign VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS first_accessed_at DATETIME NULL;

ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS agent VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_source VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_medium VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS utm_campaign VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS first_accessed_at DATETIME NULL;

CREATE INDEX IF NOT EXISTS idx_users_agent ON users(agent);
CREATE INDEX IF NOT EXISTS idx_payments_agent ON payments(agent);
CREATE INDEX IF NOT EXISTS idx_subscriptions_agent ON subscriptions(agent);
