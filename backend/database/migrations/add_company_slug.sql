-- Add company_slug: tool-only URL identifier (shared per company).
-- url_slug remains the unique card identifier for card.php?slug=.
ALTER TABLE business_cards
ADD COLUMN company_slug VARCHAR(255) NULL DEFAULT NULL AFTER url_slug,
ADD INDEX idx_company_slug (company_slug);

-- Backfill: for rows that have duplicate url_slug, set company_slug = url_slug
-- and assign a new unique url_slug from counter so each card has a unique slug.
-- (Run only if you have duplicates; tech_tool_url_counter must exist.)
-- Example pattern (MySQL 8+ or with variables):
-- 1) Set company_slug = url_slug for all rows where url_slug appears more than once.
-- 2) For each duplicate group, keep first id's url_slug, assign others new from counter.
-- Skipping automatic dedup in migration; run manually if needed after deploy.
