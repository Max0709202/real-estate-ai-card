-- Idempotent bank-renewal: only one subscription extension per payment (invoice + payment_intent webhooks)
ALTER TABLE payments
ADD COLUMN renewal_subscription_extended TINYINT(1) NOT NULL DEFAULT 0
AFTER paid_at;
