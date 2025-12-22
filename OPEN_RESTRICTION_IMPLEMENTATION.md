# OPEN Restriction Implementation Summary

## Overview
Implemented restrictions to prevent OPEN (is_published=1) from being enabled unless payment_status is CR or BANK_PAID. Includes both frontend UI restrictions and backend validation.

## Changes Made

### 1. Email Clickable (Requirement 1)
**File**: `frontend/admin/dashboard.php`
- Changed email display from plain text to `<a href="mailto:EMAIL">EMAIL</a>`
- Added proper HTML escaping using `htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8')`
- Styled with blue color and underline on hover

**CSS**: `frontend/assets/css/admin.css`
- Added hover styling for mailto links
- Added dark theme support for email links

### 2. Frontend OPEN Restriction (Requirement 2 - Frontend)
**File**: `frontend/admin/dashboard.php`
- Modified OPEN checkbox to be disabled when `payment_status` is not CR or BANK_PAID
- Shows tooltip "入金完了（CR / 振込済）後にOPEN可能" when disabled
- Forces checkbox to unchecked if record is open but payment_status doesn't allow it
- Added data attribute `data-payment-status` for JavaScript validation

**File**: `frontend/assets/js/admin.js`
- Added frontend validation before sending request
- Checks payment_status before allowing checkbox change
- Shows appropriate error message if payment_status doesn't allow OPEN
- Reverts checkbox state if validation fails

### 3. Backend OPEN Restriction (Requirement 2 - Backend)
**File**: `backend/api/admin/users.php` (action: `update_published`)
- Added validation to check `payment_status` before allowing `is_published=1`
- Returns 403 error with message "未入金のためOPENできません（CR/振込済のみ）" if payment_status is not allowed
- Logs blocked attempts as `published_change_blocked` in admin_change_logs
- Logs successful changes with before/after states and payment_status

**File**: `backend/includes/functions.php`
- Added helper function `enforceOpenPaymentStatusRule($db, $businessCardId, $paymentStatus)`
- Automatically forces `is_published=0` when payment_status changes to disallowed state
- Called whenever payment_status changes to ensure data consistency

**Files Updated to Enforce Rule on Payment Status Change**:
- `backend/api/payment/create-intent.php`: Calls `enforceOpenPaymentStatusRule` when setting BANK_PENDING
- `backend/api/admin/update-payment-status.php`: Ensures OPEN is closed when changing to disallowed status
- `backend/api/payment/webhook.php`: Already sets `is_published=TRUE` only for CR/BANK_PAID (correct)

### 4. Audit Logging
**Enhanced in**: `backend/api/admin/users.php`
- Logs successful OPEN changes with:
  - Admin ID and email
  - Business card ID
  - Before/after states
  - Payment status
  - User email and URL slug
- Logs blocked attempts with:
  - Change type: `published_change_blocked`
  - Reason: Payment status doesn't allow OPEN
  - Current payment status label

### 5. Data Correction Script
**File**: `backend/database/migrations/fix_invalid_open_records.sql`
- One-time script to close business cards that are OPEN but payment_status is not CR or BANK_PAID
- Includes SELECT query to preview what will be fixed
- Updates invalid records
- Includes verification query

## Enforcement Points

### Frontend Enforcement
1. **Checkbox State**: Checkbox is disabled when payment_status is not CR or BANK_PAID
2. **Visual Feedback**: Tooltip shows why checkbox is disabled
3. **JavaScript Validation**: Double-checks payment_status before sending request
4. **Error Messages**: Clear messages explaining why OPEN cannot be enabled

### Backend Enforcement
1. **API Validation**: `update_published` endpoint validates payment_status before allowing open=1
2. **Automatic Correction**: `enforceOpenPaymentStatusRule()` forces close when payment_status changes to disallowed state
3. **Payment Status Updates**: All places where payment_status is updated call the enforcement function

## Test Checklist Results

✅ **A) payment_status=CR → admin can set OPEN=1**
- Frontend: Checkbox enabled
- Backend: Validation passes, OPEN allowed

✅ **B) payment_status=BANK_PAID → admin can set OPEN=1**
- Frontend: Checkbox enabled
- Backend: Validation passes, OPEN allowed

✅ **C) payment_status=BANK_PENDING → OPEN control disabled; backend blocks any direct request**
- Frontend: Checkbox disabled with tooltip
- Backend: Returns 403 if direct API call attempted

✅ **D) payment_status=UNUSED → OPEN control disabled; backend blocks any direct request**
- Frontend: Checkbox disabled with tooltip
- Backend: Returns 403 if direct API call attempted

✅ **E) If an account was OPEN=1 and then payment_status becomes BANK_PENDING/UNUSED, system forces OPEN=0**
- `enforceOpenPaymentStatusRule()` is called when payment_status changes
- Automatically sets `is_published=0` if card was open

✅ **F) Client role remains read-only and cannot change OPEN**
- Checkbox disabled for client role (existing behavior)
- Backend role check remains in place

## Files Modified

### Frontend
- `frontend/admin/dashboard.php` - Email link, OPEN checkbox restriction
- `frontend/assets/js/admin.js` - Frontend validation
- `frontend/assets/css/admin.css` - Email link styling

### Backend
- `backend/api/admin/users.php` - Backend validation and audit logging
- `backend/includes/functions.php` - Helper function for automatic enforcement
- `backend/api/payment/create-intent.php` - Enforcement on payment creation
- `backend/api/admin/update-payment-status.php` - Enforcement on status update

### Database
- `backend/database/migrations/fix_invalid_open_records.sql` - One-time fix script

## Notes

1. **Email Links**: Use `mailto:` protocol which opens default mail client. Properly escaped for security.

2. **OPEN Restriction**: 
   - Frontend prevents accidental clicks
   - Backend blocks any direct API calls
   - Automatic enforcement ensures data consistency

3. **Audit Logging**: 
   - Both successful changes and blocked attempts are logged
   - Includes all relevant context (admin, user, payment status, etc.)

4. **Data Consistency**: 
   - The `enforceOpenPaymentStatusRule()` function ensures that if payment_status changes to a disallowed state, OPEN is automatically closed
   - This prevents inconsistent data states

5. **One-time Fix**: 
   - Run `fix_invalid_open_records.sql` to correct any existing invalid OPEN records
   - Should be run after deployment to clean up any legacy data

