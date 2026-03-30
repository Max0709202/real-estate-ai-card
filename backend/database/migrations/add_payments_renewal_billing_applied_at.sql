-- Idempotency: bank renewal subscription extension runs once per payment (PI + invoice webhooks)
ALTER TABLE payments
ADD COLUMN renewal_billing_applied_at TIMESTAMP NULL DEFAULT NULL
    AFTER paid_at;
