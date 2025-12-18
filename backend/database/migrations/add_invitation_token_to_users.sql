-- Add invitation_token column to users table
-- This links users to their invitation tokens for lifetime access

ALTER TABLE users
ADD COLUMN IF NOT EXISTS invitation_token VARCHAR(64) UNIQUE NULL AFTER email_reset_new_email,
ADD INDEX IF NOT EXISTS idx_invitation_token (invitation_token);

