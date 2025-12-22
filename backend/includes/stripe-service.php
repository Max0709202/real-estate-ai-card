<?php
/**
 * Stripe Service Class
 * Handles Stripe subscription operations
 */
require_once __DIR__ . '/../../vendor/autoload.php';
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Price;
use Stripe\Product;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;

class StripeService {
    private $secretKey;

    public function __construct() {
        $this->secretKey = STRIPE_SECRET_KEY;
        if (empty($this->secretKey)) {
            throw new Exception('Stripe secret key is not configured');
        }
        Stripe::setApiKey($this->secretKey);
    }

    /**
     * Create or retrieve Stripe customer
     */
    public function getOrCreateCustomer($email, $userId, $db) {
        try {
            // Check if customer already exists in database
            $stmt = $db->prepare("SELECT stripe_customer_id FROM users WHERE id = ? AND stripe_customer_id IS NOT NULL");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if ($user && !empty($user['stripe_customer_id'])) {
                try {
                    // Verify customer exists in Stripe
                    $customer = Customer::retrieve($user['stripe_customer_id']);
                    return $customer;
                } catch (ApiErrorException $e) {
                    // Customer doesn't exist in Stripe, create new one
                    error_log("Stripe customer {$user['stripe_customer_id']} not found, creating new one");
                }
            }

            // Create new Stripe customer
            $customer = Customer::create([
                'email' => $email,
                'metadata' => [
                    'user_id' => $userId
                ]
            ]);

            // Save customer ID to database
            $stmt = $db->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ?");
            $stmt->execute([$customer->id, $userId]);

            return $customer;

        } catch (ApiErrorException $e) {
            error_log("Stripe Customer Error: " . $e->getMessage());
            throw new Exception('Failed to create or retrieve Stripe customer: ' . $e->getMessage());
        }
    }

    /**
     * Create subscription for new user (initial fee + monthly subscription)
     */
    public function createSubscriptionWithInitialFee($customerId, $initialAmount, $monthlyAmount, $metadata = []) {
        try {
            // Create or get monthly price
            $monthlyPrice = $this->getOrCreatePrice('monthly_subscription', $monthlyAmount, 'Monthly subscription');

            // If initial amount > 0, create subscription with setup fee
            if ($initialAmount > 0) {
                // Create subscription with setup fee as first invoice item
                $subscription = Subscription::create([
                    'customer' => $customerId,
                    'items' => [[
                        'price' => $monthlyPrice->id,
                    ]],
                    'payment_behavior' => 'default_incomplete',
                    'payment_settings' => [
                        'save_default_payment_method' => 'on_subscription',
                    ],
                    'expand' => ['latest_invoice.payment_intent'],
                    'metadata' => array_merge([
                        'type' => 'new_user_subscription'
                    ], $metadata)
                ]);

                // Add setup fee as invoice item for first invoice
                \Stripe\InvoiceItem::create([
                    'customer' => $customerId,
                    'amount' => $initialAmount,
                    'currency' => 'jpy',
                    'description' => '初期費用',
                    'invoice' => $subscription->latest_invoice->id,
                ]);

                return $subscription;
            } else {
                // No initial fee, just create subscription
                $subscription = Subscription::create([
                    'customer' => $customerId,
                    'items' => [[
                        'price' => $monthlyPrice->id,
                    ]],
                    'metadata' => array_merge([
                        'type' => 'subscription_only'
                    ], $metadata)
                ]);

                return $subscription;
            }

        } catch (ApiErrorException $e) {
            error_log("Stripe Subscription Creation Error: " . $e->getMessage());
            throw new Exception('Failed to create subscription: ' . $e->getMessage());
        }
    }

    /**
     * Cancel subscription (at period end by default)
     */
    public function cancelSubscription($subscriptionId, $cancelImmediately = false) {
        try {
            $subscription = Subscription::retrieve($subscriptionId);

            if ($cancelImmediately) {
                // Cancel immediately
                $subscription->cancel();
            } else {
                // Cancel at period end (default)
                $subscription->cancel_at_period_end = true;
                $subscription->save();
            }

            return $subscription;

        } catch (ApiErrorException $e) {
            error_log("Stripe Subscription Cancellation Error: " . $e->getMessage());
            throw new Exception('Failed to cancel subscription: ' . $e->getMessage());
        }
    }

    /**
     * Get or create Stripe price for monthly subscription
     */
    private function getOrCreatePrice($priceId, $amount, $description) {
        try {
            // Try to retrieve existing price
            try {
                $price = Price::retrieve($priceId);
                return $price;
            } catch (ApiErrorException $e) {
                // Price doesn't exist, create it
                $product = $this->getOrCreateProduct('monthly_subscription_product', 'Monthly Subscription Product');

                $price = Price::create([
                    'id' => $priceId,
                    'unit_amount' => $amount,
                    'currency' => 'jpy',
                    'recurring' => [
                        'interval' => 'month',
                    ],
                    'product' => $product->id,
                    'nickname' => $description,
                ]);

                return $price;
            }
        } catch (ApiErrorException $e) {
            error_log("Stripe Price Creation Error: " . $e->getMessage());
            throw new Exception('Failed to create price: ' . $e->getMessage());
        }
    }

    /**
     * Get or create Stripe product
     */
    private function getOrCreateProduct($productId, $name) {
        try {
            try {
                $product = Product::retrieve($productId);
                return $product;
            } catch (ApiErrorException $e) {
                $product = Product::create([
                    'id' => $productId,
                    'name' => $name,
                ]);
                return $product;
            }
        } catch (ApiErrorException $e) {
            error_log("Stripe Product Creation Error: " . $e->getMessage());
            throw new Exception('Failed to create product: ' . $e->getMessage());
        }
    }

    /**
     * Get subscription by ID
     */
    public function getSubscription($subscriptionId) {
        try {
            return Subscription::retrieve($subscriptionId);
        } catch (ApiErrorException $e) {
            error_log("Stripe Get Subscription Error: " . $e->getMessage());
            throw new Exception('Failed to retrieve subscription: ' . $e->getMessage());
        }
    }
}

