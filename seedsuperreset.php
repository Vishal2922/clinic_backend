<?php
/**
 * Reset Super Admin Password
 *
 * Usage:
 *   php database/master/reset_super_admin_password.php
 */

$config = [
    'host'     => '127.0.0.1',
    'port'     => '3306',
    'database' => 'clinic_master_db',
    'username' => 'root',
    'password' => '',
];

// ─── Set new password here ───────────────────────────────────────
$email       = 'superadmin@clinic.io';
$newPassword = 'SuperAdmin@2026';   // change this to your desired new password
// ─────────────────────────────────────────────────────────────────

echo "\n==============================\n";
echo "  Super Admin Password Reset\n";
echo "==============================\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ Connected to: {$config['database']}\n";
} catch (PDOException $e) {
    die("❌ Connection failed: " . $e->getMessage() . "\n");
}

// Check admin exists
$stmt = $pdo->prepare('SELECT id, email FROM super_admins WHERE email = :email');
$stmt->execute(['email' => $email]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("❌ No super admin found with email: {$email}\n");
}

// Generate fresh hash
$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

if (!password_verify($newPassword, $newHash)) {
    die("❌ Hash verification failed.\n");
}

// Update
$update = $pdo->prepare(
    'UPDATE super_admins
     SET password_hash = :hash, failed_attempts = 0, locked_until = NULL, updated_at = NOW()
     WHERE email = :email'
);
$update->execute(['hash' => $newHash, 'email' => $email]);

echo "✅ Password reset successfully for: {$email}\n";
echo "   New password: {$newPassword}\n\n";
echo "   ⚠️  Change this after login!\n\n";