-- Add email verification fields to admins table
ALTER TABLE admins 
ADD COLUMN email_verified BOOLEAN DEFAULT FALSE AFTER role,
ADD COLUMN verification_token VARCHAR(255) NULL AFTER email_verified,
ADD COLUMN verification_token_expires_at TIMESTAMP NULL AFTER verification_token,
ADD COLUMN password_reset_token VARCHAR(255) NULL AFTER verification_token_expires_at,
ADD COLUMN password_reset_token_expires_at TIMESTAMP NULL AFTER password_reset_token;

-- Add index for verification token
CREATE INDEX idx_admin_verification_token ON admins(verification_token);

