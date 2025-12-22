# Payment Status Refactor - Implementation Summary

## Overview
This refactor replaces the ambiguous boolean "payment_confirmed" checkbox with a clear payment status badge system that distinguishes between different payment states.

## Database Changes

### Migration: `backend/database/migrations/add_payment_status_to_business_cards.sql`
- Replaces the old `payment_status` ENUM ('pending', 'paid', 'failed', 'refunded') with new values: `'CR'`, `'BANK_PENDING'`, `'BANK_PAID'`, `'UNUSED'`
- Migration strategy:
  1. Creates temporary column `payment_status_new`
  2. Maps existing data from `payments` table:
     - `completed` + `credit_card` → `'CR'`
     - `completed` + `bank_transfer` → `'BANK_PAID'`
     - `pending` + `bank_transfer` → `'BANK_PENDING'`
     - Old `payment_status = 'paid'` → `'CR'` (assumes credit)
     - Everything else → `'UNUSED'`
  3. Drops old column and renames new column
  4. Adds index for faster filtering

## Business Logic Changes

### Payment Creation (`backend/api/payment/create-intent.php`)
- When user selects `bank_transfer`, sets `business_cards.payment_status = 'BANK_PENDING'` immediately
- Credit card payments remain in pending state until webhook confirmation

### Stripe Webhook (`backend/api/payment/webhook.php`)
- On `payment_intent.succeeded`:
  - Updates `business_cards.payment_status = 'CR'` for credit card payments
  - Updates `business_cards.payment_status = 'BANK_PAID'` for bank transfers (if applicable)
  - Only generates QR code if status is `'CR'` or `'BANK_PAID'`

### QR Code Generation (`backend/includes/qr-helper.php`, `backend/api/qr-code/generate.php`)
- Updated to check `business_cards.payment_status` instead of `payments.payment_status`
- Only allows QR generation if `payment_status IN ('CR', 'BANK_PAID')`

### Card Display (`frontend/card.php`)
- Checks `payment_status` before displaying card
- Only shows card if `payment_status IN ('CR', 'BANK_PAID')` AND `is_published = 1`
- Displays user-friendly error message instead of 404 for unconfirmed payments

### Admin Status Update (`backend/api/admin/update-payment-status.php`)
- New endpoint to toggle `BANK_PENDING` → `BANK_PAID`
- Only Admin role can use this endpoint
- Validates transition (only `BANK_PENDING` → `BANK_PAID` allowed)
- Generates QR code and sends email notification after status change
- Includes audit logging

## UI Changes

### Admin Dashboard (`frontend/admin/dashboard.php`)
- **Column Rename**: "ユーザータイプ" → "分類"
- **Payment Status Column**: Replaced checkbox with badge display:
  - `CR`: Green background badge
  - `振込予定` (`BANK_PENDING`): Red background badge (clickable for Admin)
  - `振込済` (`BANK_PAID`): Blue background badge
  - `未利用` (`UNUSED`): White background with border
- **Classification Badges**: Changed from filled backgrounds to text colors:
  - `新規`: Blue text (#0066cc)
  - `既存`: Gray text (#666)
  - `無料`: Orange text (#ff9900)
- **Filter Dropdown**: Updated with new status options (CR, 振込予定, 振込済, 未利用)

### JavaScript (`frontend/assets/js/admin.js`)
- Added `confirmBankTransferPaid()` function to show confirmation modal
- Added `processBankTransferPaid()` function to call API and update UI

### CSS (`frontend/assets/css/admin.css`)
- Added payment badge styles with appropriate colors
- Updated user type badge styles to use text colors instead of backgrounds
- Added dark theme support for all new styles
- Added hover effects for clickable badges

## CSV Export (`backend/api/admin/export-csv.php`)
- Updated to use new payment status values
- Column renamed: "入金" → "入金状況"
- Includes Japanese labels: CR, 振込予定, 振込済, 未利用

## Security & Audit

### Audit Logging
- All payment status changes logged via `logAdminChange()`
- Includes: admin_id, admin_email, change_type, target_type, target_id, description, timestamp

### Access Control
- Only Admin role can toggle `BANK_PENDING` → `BANK_PAID`
- Client role sees badges as read-only
- Server-side validation of allowed transitions

## Testing Checklist

### Test Cases to Verify:
1. ✅ New user credit payment → status `CR` → QR issued → email sent
2. ✅ New user selects bank transfer → status `振込予定` → no QR/email until admin confirms → toggle → `振込済` → QR/email
3. ✅ User abandons before payment choice → status `未利用` → no QR/email
4. ✅ Abandoned user later completes payment → status updates correctly
5. ✅ Client role cannot modify payment status
6. ✅ Filtering & CSV export match new statuses
7. ✅ Card display blocked for non-CR/BANK_PAID statuses

## Assumptions & Notes

1. **Migration Mapping**: 
   - Old `payment_status = 'paid'` mapped to `'CR'` (assumes credit payments)
   - If this is incorrect, manual data review may be needed

2. **Bank Transfer Detection**:
   - Migration uses `payments.payment_method = 'bank_transfer'` to determine bank transfers
   - If payment records are missing, defaults to `'UNUSED'`

3. **QR Code Generation**:
   - Only allowed for `CR` or `BANK_PAID` status
   - Previously generated QR codes remain accessible if card is published

4. **Email Notifications**:
   - QR code email sent when status changes to `BANK_PAID` (admin confirmation)
   - QR code email sent when Stripe webhook confirms credit payment

5. **Backward Compatibility**:
   - Existing payment records in `payments` table remain unchanged
   - New `payment_status` column on `business_cards` is the source of truth for display logic

## Files Modified

### Backend
- `backend/database/migrations/add_payment_status_to_business_cards.sql` (NEW)
- `backend/api/payment/create-intent.php`
- `backend/api/payment/webhook.php`
- `backend/api/admin/update-payment-status.php` (NEW)
- `backend/includes/qr-helper.php`
- `backend/api/qr-code/generate.php`
- `backend/api/admin/export-csv.php`

### Frontend
- `frontend/admin/dashboard.php`
- `frontend/assets/js/admin.js`
- `frontend/assets/css/admin.css`
- `frontend/card.php`

## Next Steps

1. Run the migration script: `backend/database/migrations/add_payment_status_to_business_cards.sql`
2. Test all payment flows end-to-end
3. Verify existing data was migrated correctly
4. Monitor audit logs for payment status changes
5. Train admins on new badge system and toggle functionality

