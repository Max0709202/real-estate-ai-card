-- Extend payments.payment_type for subscription renewal (bank annual / card first month)
ALTER TABLE payments
MODIFY COLUMN payment_type ENUM('new_user', 'existing_user', 'renewal') NOT NULL;
