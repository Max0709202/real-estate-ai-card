# AI-Fcard Public App

This repository contains the public web app for the AI business card service.

## Tech Stack

- PHP (server-rendered pages + API endpoints)
- MySQL
- Stripe (payment, subscriptions, billing portal, bank transfer)
- Plain JavaScript / CSS

## Main Directories

- `backend/api/` - API endpoints
- `backend/config/` - app and DB config
- `backend/includes/` - shared helpers/functions
- `backend/database/` - schema and migrations
- `assets/` - frontend JS/CSS/images
- project root (`*.php`) - public pages

## Local/Server Configuration

At minimum, ensure these are configured in environment/config:

- `STRIPE_SECRET_KEY`
- `STRIPE_PUBLISHABLE_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_BILLING_PORTAL_CONFIGURATION_ID` (optional; used when set)
- DB credentials (`backend/config/database.php` usage)

Also ensure `BASE_URL` is correct for your environment.

## Payment Overview

### Credit Card

- Initial payment is created via `backend/api/payment/create-intent.php`.
- Monthly subscription is created/linked through Stripe.
- Card update is done through Stripe Billing Portal:
  - API: `backend/api/mypage/billing-portal-session.php`
  - UI button: shown in `edit.php` when subscription/payment conditions are met.

### Bank Transfer

- Bank transfer info page: `bank-transfer-info.php`
- For new-user initial bank transfer, current behavior is:
  - initial fee + annual fee are combined in one transfer request.
- Renewal bank transfer uses renewal pricing path.

## URL Slug Behavior

- `business_cards.url_slug` now uses a random alphanumeric string generator.
- Generation helper: `generateUniqueBusinessCardUrlSlug()` in `backend/includes/functions.php`.
- Existing slugs remain unchanged; only newly generated records use the random format.

## Organization Hierarchy (統括 → 店長 → 営業)

- `users.org_role` (`staff` / `manager` / `admin`) and `users.parent_user_id` express a
  three-level sales organization. Everything is derived from the `parent_user_id` chain.
- **Two clearly separate screens — do not mix them up:**
  - `admin/org-hierarchy.php` — **operator (リニュアル仲介) only**. Shows every company, so it
    is never handed to a client. Requires an `admins` row; client `users` accounts cannot reach it.
    Its job is appointing each client company's top person as `admin` (統括).
  - `edit.php` → **組織・メンバー顧客** — the client company's own screen. A 統括/店長 builds their
    own hierarchy here, scoped to their own company.
- Company scoping: `orgCompanyKey()` normalizes `business_cards.company_name`
  (strips 株式会社/(株)/whitespace, folds full-width) and assignment candidates must match it
  exactly. A user with no company name gets no candidates.
- Tier rules enforced server-side: 統括 → 店長 → 営業 only (max 3). A 店長 may only take
  担当者 directly under them; only a 統括 may promote a *direct* subordinate to 店長.
- Customer data stays read-only for supervisors; the only writes are to the hierarchy itself
  (`parent_user_id` / `org_role`) and always within the actor's own company and subtree.
- Shared logic: `backend/includes/org-hierarchy-helper.php`.
  Read APIs: `backend/api/org/{members,customers,candidates,export-customers-csv}.php`.
  Hierarchy writes: `backend/api/org/{assign,unassign,update-role}.php`.
- Migration: `backend/database/migrations/20260731_add_user_org_hierarchy.sql`
  (the helper also adds the columns at runtime if the migration has not been applied).

## 情報BOX (per-transaction document sharing)

Spec: `不動産AI名刺_情報BOX開発仕様書2026.9.24.docx`.

- **On/off per company**, like the hierarchy feature: `admin/infobox-settings.php` (operator only)
  sets `org_license_settings.infobox_enabled` by license key. OFF hides the マイページ menu and
  every `backend/api/infobox/*` endpoint refuses; data is kept.
- Pages: `infobox.php` (owner's list, or `?box=<id>`), `infobox-access.php?t=<token>`
  (invited participants verify registered email + phone; 7-day URL, 5 failures → 15 min lock,
  30 min idle / 8 h max session).
- **Top rule:** a document is visible only to its uploader and the people the uploader chose —
  the card owner and admins included. Every read path goes through `iboxDocVisibleTo()`;
  files are served only by `backend/api/infobox/file.php` (re-checked on every request) and
  `backend/uploads/infobox/` denies direct HTTP access.
- 取引台帳: `backend/api/infobox/ledger.php` fills the form from the documents in folders
  07 (売買契約書) and 09 (重要事項説明書) that the owner can view — AI read first, then fixed
  patterns for the 全宅連 form. Missing either document → error, no ledger. PDF is produced by the
  built-in writer in `infobox-pdf-helper.php` (no library needed).
- Invited participants who verify while logged in to an AI名刺 account get that account linked to
  their participant row, so they can reopen the BOX from `infobox.php` later (unlinked on contact
  change / suspension). The document viewer uses PDF.js from jsDelivr (page nav, zoom, print —
  no standalone download button); the ledger PDF needs its CMaps, which are loaded from the same CDN.
- Ledger versions are frozen per save (preview first, then confirm). Closing a fiscal year
  (`ibox_ledger_closures`) blocks new versions for that year and records a 5-year retention date;
  nothing is ever auto-deleted.
- Word/Excel previews need LibreOffice (`soffice`, or `SOFFICE_BIN`) on the server; without it
  such uploads are refused with a "convert to PDF" message. Text extraction uses `pdftotext`
  (falls back to Ghostscript).
- Shared logic: `backend/includes/infobox-*.php`. Migration:
  `backend/database/migrations/20260928_add_infobox.sql` (tables are also created at runtime).

## Migrations / Schema Notes

Run required migrations before deploying features that depend on them, especially:

- `backend/database/migrations/add_payment_type_renewal.sql`
- `backend/database/migrations/add_payments_renewal_subscription_extended.sql`
- `backend/database/migrations/20260731_add_user_org_hierarchy.sql`

Keep `backend/database/schema.sql` in sync with production DB changes.

## Operational Notes

- Stripe mode must match keys and data (test vs live).
- If Stripe IDs exist in DB but not in the same Stripe mode, billing portal and subscription operations fail.
- Avoid manual insertion of Stripe object IDs unless they are real objects in the same Stripe account/mode.

## Quick Health Checks

- PHP syntax checks:
  - `php -l backend/api/payment/create-intent.php`
  - `php -l backend/api/payment/webhook.php`
  - `php -l edit.php`
- Verify webhook delivery in Stripe Dashboard and application logs.

