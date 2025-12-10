<?php
/**
 * Change Admin Password Script
 * Changes the admin password to a more secure one
 * 
 * Usage: php backend/scripts/change_admin_password.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Admin email
    $adminEmail = 'admin@rchukai.jp';
    
    // New secure password (you can change this)
    // Strong password: at least 12 characters, mix of uppercase, lowercase, numbers, and symbols
    $newPassword = 'Admin@2024!Secure';
    
    // Hash the new password
    $hashedPassword = hashPassword($newPassword);
    
    echo "Changing password for admin: {$adminEmail}\n";
    echo "New password: {$newPassword}\n";
    echo "Password hash: {$hashedPassword}\n\n";
    
    // Check if admin exists
    $stmt = $db->prepare("SELECT id, email FROM admins WHERE email = ?");
    $stmt->execute([$adminEmail]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        echo "ERROR: Admin account not found!\n";
        exit(1);
    }
    
    // Update password
    $stmt = $db->prepare("UPDATE admins SET password_hash = ?, last_password_change = NOW() WHERE email = ?");
    $stmt->execute([$hashedPassword, $adminEmail]);
    echo "Password updated successfully!\n\n";
    
    // Verify the update
    $stmt = $db->prepare("SELECT email, password_hash FROM admins WHERE email = ?");
    $stmt->execute([$adminEmail]);
    $updatedAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($updatedAdmin) {
        echo "Verification:\n";
        echo "Email: {$updatedAdmin['email']}\n";
        echo "Password hash stored: " . substr($updatedAdmin['password_hash'], 0, 20) . "...\n";
        
        // Test password verification
        if (verifyPassword($newPassword, $updatedAdmin['password_hash'])) {
            echo "✓ Password verification test: SUCCESS\n";
            echo "\n========================================\n";
            echo "NEW ADMIN CREDENTIALS:\n";
            echo "========================================\n";
            echo "Email: {$adminEmail}\n";
            echo "Password: {$newPassword}\n";
            echo "========================================\n";
            echo "\n⚠️  IMPORTANT: Save these credentials securely!\n";
            echo "This password is more secure and should not trigger breach warnings.\n";
        } else {
            echo "✗ Password verification test: FAILED\n";
            echo "WARNING: There may be an issue with password hashing!\n";
            exit(1);
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

