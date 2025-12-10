<?php
/**
 * Update Admin Password Script
 * Hashes the admin password and updates it in the database
 * 
 * Usage: php backend/scripts/update_admin_password.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Admin email
    $adminEmail = 'admin@rchukai.jp';
    $adminPassword = 'admin123';
    
    // Hash the password
    $hashedPassword = hashPassword($adminPassword);
    
    echo "Hashing password for admin: {$adminEmail}\n";
    echo "Password hash: {$hashedPassword}\n\n";
    
    // Check if admin exists
    $stmt = $db->prepare("SELECT id, email, password_hash FROM admins WHERE email = ?");
    $stmt->execute([$adminEmail]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        echo "Admin not found. Creating new admin account...\n";
        // Create admin if doesn't exist
        $stmt = $db->prepare("INSERT INTO admins (email, password_hash, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$adminEmail, $hashedPassword]);
        echo "Admin account created successfully!\n";
    } else {
        echo "Admin found. Updating password hash...\n";
        // Update existing admin password
        $stmt = $db->prepare("UPDATE admins SET password_hash = ?, last_password_change = NOW() WHERE email = ?");
        $stmt->execute([$hashedPassword, $adminEmail]);
        echo "Password updated successfully!\n";
    }
    
    // Verify the update
    $stmt = $db->prepare("SELECT email, password_hash FROM admins WHERE email = ?");
    $stmt->execute([$adminEmail]);
    $updatedAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($updatedAdmin) {
        echo "\nVerification:\n";
        echo "Email: {$updatedAdmin['email']}\n";
        echo "Password hash stored: " . substr($updatedAdmin['password_hash'], 0, 20) . "...\n";
        
        // Test password verification
        if (verifyPassword($adminPassword, $updatedAdmin['password_hash'])) {
            echo "✓ Password verification test: SUCCESS\n";
            echo "\nYou can now log in with:\n";
            echo "Email: {$adminEmail}\n";
            echo "Password: {$adminPassword}\n";
        } else {
            echo "✗ Password verification test: FAILED\n";
            echo "WARNING: There may be an issue with password hashing!\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

