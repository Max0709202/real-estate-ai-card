<?php
/**
 * Utility script to fix subscriptions table schema
 * Makes stripe_subscription_id nullable to fix "Column 'stripe_subscription_id' cannot be null" errors
 * 
 * Usage: php backend/api/utils/fix-subscription-schema.php
 * Or access via browser: http://your-domain/php/backend/api/utils/fix-subscription-schema.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Fixing subscriptions table schema ===\n\n";
    
    // Step 1: Check current column definition
    echo "Step 1: Checking current column definition...\n";
    $stmt = $db->query("
        SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'subscriptions'
          AND COLUMN_NAME = 'stripe_subscription_id'
    ");
    $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo) {
        echo "Current definition: IS_NULLABLE = {$columnInfo['IS_NULLABLE']}, TYPE = {$columnInfo['COLUMN_TYPE']}\n";
        
        if ($columnInfo['IS_NULLABLE'] === 'NO') {
            echo "Column is currently NOT NULL. Modifying to allow NULL...\n\n";
            
            // Step 2: Drop unique index if it exists (may be created as constraint)
            echo "Step 2: Dropping unique index if exists...\n";
            try {
                $db->exec("ALTER TABLE subscriptions DROP INDEX stripe_subscription_id");
                echo "✓ Unique index dropped\n";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Unknown key') === false && 
                    strpos($e->getMessage(), 'check that it exists') === false) {
                    echo "Note: " . $e->getMessage() . "\n";
                } else {
                    echo "Index does not exist or already dropped\n";
                }
            }
            
            // Step 3: Modify column to allow NULL
            echo "\nStep 3: Modifying column to allow NULL...\n";
            $db->exec("ALTER TABLE subscriptions MODIFY COLUMN stripe_subscription_id VARCHAR(255) NULL");
            echo "✓ Column modified to allow NULL\n";
            
            // Step 4: Re-add unique index
            echo "\nStep 4: Re-adding unique index...\n";
            try {
                $db->exec("ALTER TABLE subscriptions ADD UNIQUE INDEX idx_stripe_subscription_id (stripe_subscription_id)");
                echo "✓ Unique index re-added (NULL values are ignored by UNIQUE constraint)\n";
            } catch (PDOException $e) {
                echo "Warning: Could not re-add unique index: " . $e->getMessage() . "\n";
            }
            
            // Step 5: Verify the change
            echo "\nStep 5: Verifying change...\n";
            $stmt = $db->query("
                SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'subscriptions'
                  AND COLUMN_NAME = 'stripe_subscription_id'
            ");
            $newColumnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($newColumnInfo && $newColumnInfo['IS_NULLABLE'] === 'YES') {
                echo "✓ SUCCESS: Column now allows NULL values\n";
                echo "\n=== Schema fix completed successfully ===\n";
            } else {
                echo "✗ ERROR: Column modification may have failed. IS_NULLABLE = {$newColumnInfo['IS_NULLABLE']}\n";
            }
        } else {
            echo "✓ Column already allows NULL values. No changes needed.\n";
        }
    } else {
        echo "✗ ERROR: Column 'stripe_subscription_id' not found in subscriptions table\n";
    }
    
} catch (Exception $e) {
    echo "✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
