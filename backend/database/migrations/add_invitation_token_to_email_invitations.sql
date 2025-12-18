-- Add invitation_token column to email_invitations table
-- This token is used for secure access to registration/login pages

ALTER TABLE email_invitations
ADD COLUMN IF NOT EXISTS invitation_token VARCHAR(64) UNIQUE NULL AFTER email_sent,
ADD INDEX IF NOT EXISTS idx_invitation_token (invitation_token);

-- Update existing records to have tokens (optional - for existing data)
-- UPDATE email_invitations SET invitation_token = SHA2(CONCAT(id, email, created_at), 256) WHERE invitation_token IS NULL;

