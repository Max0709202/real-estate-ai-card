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

## Migrations / Schema Notes

Run required migrations before deploying features that depend on them, especially:

- `backend/database/migrations/add_payment_type_renewal.sql`
- `backend/database/migrations/add_payments_renewal_subscription_extended.sql`

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

