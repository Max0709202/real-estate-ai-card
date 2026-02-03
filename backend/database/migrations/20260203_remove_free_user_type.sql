-- Migration: Remove 'free' user type and related payment/invitation roles
-- NOTE: Run this on your production database after taking a backup.

-- 1) Normalize existing data: convert 'free' users/roles to 'existing'
UPDATE users
SET user_type = 'existing'
WHERE user_type = 'free';

UPDATE email_invitations
SET role_type = 'existing'
WHERE role_type = 'free';

UPDATE payments
SET payment_type = 'existing_user'
WHERE payment_type = 'free';

-- 2) Update ENUM definitions to remove 'free'
ALTER TABLE users
MODIFY COLUMN user_type ENUM('new', 'existing') NOT NULL DEFAULT 'new';

ALTER TABLE email_invitations
MODIFY COLUMN role_type ENUM('new', 'existing') DEFAULT 'new';

ALTER TABLE payments
MODIFY COLUMN payment_type ENUM('new_user', 'existing_user') NOT NULL;

