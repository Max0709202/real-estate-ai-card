-- Add admin_notes column to business_cards table
ALTER TABLE business_cards
ADD COLUMN admin_notes TEXT NULL AFTER is_published;

-- Add index for better performance (optional, but helpful for searches)
-- Note: Full-text search on TEXT columns requires FULLTEXT index, but we'll keep it simple for now

