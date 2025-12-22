# Subscription & Payment State Handling Implementation

This document describes the implementation of subscription and payment state handling with Stripe, including draft saving, auto-suspension on payment failure, and cancellation flow.

## Database Schema Changes

### Migration File: `backend/database/migrations/add_subscription_support.sql`

**Changes:**
1. Added `stripe_customer_id` to `users` table
2. Added `card_status` enum field to `business_cards` table ('draft', 'active', 'suspended', 'canceled')
3. Updated `subscriptions.status` enum to support Stripe statuses: 'active', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'trialing', 'paused'
4. Added `stripe_customer_id` to `subscriptions` table
5. Created `webhook_event_log` table for idempotency

## New API Endpoints

### 1. Autosave Draft
**Endpoint:** `POST /backend/api/mypage/autosave.php`

**Purpose:** Save business card data as draft even if payment is not completed.

**Authentication:** Required

**Request Body:**
```json
{
  "company_name": "...",
  "name": "...",
  // ... other business card fields
  "greetings": [...],
  "tech_tools": [...],
  "communication_methods": [...]
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "business_card_id": 123,
    "status": "draft"
  },
  "message": "ドラフトが保存されました"
}
```

**Behavior:**
- Creates business card if doesn't exist
- Sets `card_status = 'draft'`
- Saves all data fields, greetings, tech tools, communication methods

### 2. Cancel Subscription
**Endpoint:** `POST /backend/api/mypage/cancel.php`

**Purpose:** Cancel user's subscription from My Page.

**Authentication:** Required

**Request Body:**
```json
{
  "cancel_immediately": false  // Optional: true for immediate cancellation, false for cancel at period end
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "subscription_id": 123,
    "status": "canceled",
    "canceled_at_period_end": true
  },
  "message": "サブスクリプションを期間終了時にキャンセルするよう設定しました"
}
```

**Behavior:**
- Cancels subscription in Stripe (at period end by default)
- Updates `subscriptions.status` to 'canceled'
- Sets `business_cards.card_status = 'canceled'`
- Sets `business_cards.is_published = FALSE`
- Logs cancellation to admin_change_logs

## Stripe Service Class

### File: `backend/includes/stripe-service.php`

**Class:** `StripeService`

**Methods:**
- `getOrCreateCustomer($email, $userId, $db)` - Create or retrieve Stripe customer
- `createSubscriptionWithInitialFee($customerId, $initialAmount, $monthlyAmount, $metadata)` - Create subscription with initial setup fee
- `cancelSubscription($subscriptionId, $cancelImmediately)` - Cancel subscription
- `getSubscription($subscriptionId)` - Get subscription details

## Enhanced Webhook Handler

### File: `backend/api/payment/webhook.php`

**Idempotency:** Uses `webhook_event_log` table to prevent duplicate processing.

**Handled Events:**

1. **checkout.session.completed**
   - Updates `card_status` from 'draft' to 'active'

2. **invoice.payment_succeeded**
   - Updates `subscriptions.status` to 'active'
   - Updates `business_cards.card_status` to 'active'
   - Handles one-time payment intents
   - Generates QR code if payment confirmed

3. **invoice.payment_failed**
   - Updates `subscriptions.status` to 'past_due'
   - Sets `business_cards.card_status = 'suspended'`
   - Sets `business_cards.is_published = FALSE`
   - Logs suspension to admin_change_logs

4. **customer.subscription.updated**
   - Updates subscription status in database
   - Maps Stripe statuses to local statuses
   - Updates `business_cards.card_status` based on subscription status:
     - 'past_due', 'unpaid', 'canceled', 'incomplete_expired' → 'suspended' or 'canceled'
     - 'active' → 'active' (but does NOT auto-open)

5. **customer.subscription.deleted**
   - Sets `subscriptions.status = 'canceled'`
   - Sets `business_cards.card_status = 'canceled'`
   - Sets `business_cards.is_published = FALSE`

## OPEN Flag Control

**Rule:** `is_published` (OPEN) can only be `TRUE` if:
- `subscription_status` is 'active' (for subscription users), OR
- `payment_status` is 'CR' or 'BANK_PAID' (for one-time payment users)

**Implementation:**
- The `enforceOpenPaymentStatusRule()` function (already exists) ensures OPEN is forced to FALSE if payment/subscription status doesn't allow it
- Webhook handler automatically sets `is_published = FALSE` when subscription becomes inactive
- When subscription becomes active again, card_status is set to 'active' but OPEN remains FALSE until user/admin manually enables it

## Usage Notes

### Frontend Integration

1. **Autosave on My Page:**
   ```javascript
   // Call autosave endpoint during editing
   fetch('/backend/api/mypage/autosave.php', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json' },
     body: JSON.stringify(formData)
   });
   ```

2. **Cancel Subscription Button:**
   ```javascript
   // Add cancel button to My Page UI
   fetch('/backend/api/mypage/cancel.php', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json' },
     body: JSON.stringify({ cancel_immediately: false })
   });
   ```

### Security

- All endpoints require authentication (`requireAuth()`)
- Webhook endpoint verifies Stripe signature using `STRIPE_WEBHOOK_SECRET`
- Idempotency prevents duplicate webhook processing
- CSRF protection should be added for form submissions (not included in this implementation)

### Error Handling

- All errors are logged to error_log
- Webhook errors are stored in `webhook_event_log.error_message`
- User-friendly error messages in API responses

## Database Migration Instructions

1. Run the migration file:
   ```bash
   mysql -u username -p database_name < backend/database/migrations/add_subscription_support.sql
   ```

2. Verify the changes:
   ```sql
   DESCRIBE users;  -- Should include stripe_customer_id
   DESCRIBE business_cards;  -- Should include card_status
   DESCRIBE subscriptions;  -- Should have updated status enum
   SHOW TABLES LIKE 'webhook_event_log';  -- Should exist
   ```

## Testing Checklist

- [ ] Autosave endpoint saves draft data correctly
- [ ] Draft can be loaded and resumed editing
- [ ] Subscription creation with initial fee works
- [ ] Webhook handles invoice.payment_succeeded correctly
- [ ] Webhook suspends card on invoice.payment_failed
- [ ] Cancel subscription updates database correctly
- [ ] OPEN flag is automatically set to FALSE on suspension
- [ ] Webhook idempotency prevents duplicate processing
- [ ] Error handling and logging works correctly

