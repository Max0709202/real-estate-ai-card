<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

echo "Testing Stripe...\n";

if (empty(STRIPE_SECRET_KEY)) {
    echo "ERROR: Stripe key not loaded!\n";
    exit(1);
}

echo "Stripe key loaded: " . substr(STRIPE_SECRET_KEY, 0, 15) . "...\n";

try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $customer = \Stripe\Customer::create(['email' => 'test@test.com']);
    echo "SUCCESS: Stripe working! Customer: " . $customer->id . "\n";
    $customer->delete();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}




