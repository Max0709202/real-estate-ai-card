<?php
/**
 * Create Admin/Client Account Script
 * Usage: php create-admin.php email password role
 * Example: php create-admin.php admin@example.com Password123! admin
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if ($argc < 4) {
    echo "Usage: php create-admin.php <email> <password> <role>\n";
    echo "Role: admin or client\n";
    echo "Example: php create-admin.php admin@example.com Password123! admin\n";
    exit(1);
}

$email = $argv[1];
$password = $argv[2];
$role = $argv[3];

if (!in_array($role, ['admin', 'client'])) {
    echo "Error: Role must be 'admin' or 'client'\n";
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Error: Invalid email address\n";
    exit(1);
}

if (strlen($password) < 8) {
    echo "Error: Password must be at least 8 characters\n";
    exit(1);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo "Error: Email already exists\n";
        exit(1);
    }

    // Hash password
    $passwordHash = hashPassword($password);

    // Generate verification token
    $verificationToken = generateToken(32);
    $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Create admin account
    $stmt = $db->prepare("
        INSERT INTO admins (email, password_hash, role, verification_token, verification_token_expires_at, email_verified)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $email,
        $passwordHash,
        $role,
        $verificationToken,
        $tokenExpiresAt
    ]);

    $adminId = $db->lastInsertId();

    echo "Success: Admin account created!\n";
    echo "ID: {$adminId}\n";
    echo "Email: {$email}\n";
    echo "Role: {$role}\n";
    echo "Email verified: Yes (auto-verified for CLI creation)\n";
    echo "\nYou can now login at: " . BASE_URL . "/frontend/admin/login.php\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}



