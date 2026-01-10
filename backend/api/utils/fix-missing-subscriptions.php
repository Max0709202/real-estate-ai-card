<?php
/**
 * Utility script to fix missing subscriptions for users who have completed payment
 * Run this script to automatically create subscriptions for users with completed payment but no subscription
 * 
 * Usage: php backend/api/utils/fix-missing-subscriptions.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // First, ensure stripe_subscription_id column allows NULL
    try {
        $db->exec("ALTER TABLE subscriptions MODIFY COLUMN stripe_subscription_id VARCHAR(255) NULL");
        echo "✓ Modified stripe_subscription_id to allow NULL\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') === false && 
            strpos($e->getMessage(), 'doesn\'t exist') === false) {
            echo "Warning: Could not modify column (may already be nullable): " . $e->getMessage() . "\n";
        }
    }
    
    // Find users with completed payment but no subscription
    $stmt = $db->prepare("
        SELECT DISTINCT bc.user_id, bc.id as business_card_id, bc.payment_status, u.user_type, u.stripe_customer_id
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        LEFT JOIN subscriptions s ON bc.user_id = s.user_id AND bc.id = s.business_card_id
        WHERE bc.payment_status IN ('CR', 'BANK_PAID')
          AND s.id IS NULL
        ORDER BY bc.user_id
    ");
    $stmt->execute();
    $usersNeedingSubscription = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($usersNeedingSubscription) . " users with completed payment but no subscription\n\n";
    
    $created = 0;
    $errors = 0;
    
    foreach ($usersNeedingSubscription as $user) {
        try {
            // Determine monthly amount
            $monthlyAmount = 0;
            if ($user['user_type'] === 'new' || $user['user_type'] === null) {
                $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
            } elseif ($user['user_type'] === 'existing') {
                $monthlyAmount = 0;
            } else {
                $monthlyAmount = defined('PRICING_NEW_USER_MONTHLY') ? PRICING_NEW_USER_MONTHLY : 500;
            }
            
            // Try to find existing Stripe subscription
            $stripeSubscriptionId = null;
            if (!empty($user['stripe_customer_id']) && class_exists('\Stripe\Stripe') && !empty(STRIPE_SECRET_KEY)) {
                try {
                    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                    $stripeSubscriptions = \Stripe\Subscription::all([
                        'customer' => $user['stripe_customer_id'],
                        'limit' => 1,
                        'status' => 'all'
                    ]);
                    
                    if ($stripeSubscriptions && count($stripeSubscriptions->data) > 0) {
                        $stripeSubscriptionId = $stripeSubscriptions->data[0]->id;
                    }
                } catch (Exception $stripeError) {
                    // Continue without Stripe subscription ID
                }
            }
            
            // Create subscription record
            $stmt = $db->prepare("
                INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, stripe_customer_id, status, amount, billing_cycle, next_billing_date)
                VALUES (?, ?, ?, ?, 'active', ?, 'monthly', DATE_ADD(NOW(), INTERVAL 1 MONTH))
            ");
            $stmt->execute([
                $user['user_id'],
                $user['business_card_id'],
                $stripeSubscriptionId,
                $user['stripe_customer_id'] ?? null,
                $monthlyAmount
            ]);
            
            echo "✓ Created subscription for user_id={$user['user_id']}, bc_id={$user['business_card_id']}, monthly_amount={$monthlyAmount}\n";
            $created++;
            
        } catch (Exception $e) {
            echo "✗ Error creating subscription for user_id={$user['user_id']}: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n=== Summary ===\n";
    echo "Created: {$created}\n";
    echo "Errors: {$errors}\n";
    echo "Total processed: " . count($usersNeedingSubscription) . "\n";
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
