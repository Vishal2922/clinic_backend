<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║        SUPER ADMIN SEEDER — Run this via CLI             ║
 * ║                                                          ║
 * ║  Usage:                                                  ║
 * ║    php database/master/seed_super_admin.php              ║
 * ║                                                          ║
 * ║  This script:                                            ║
 * ║    1. Connects directly to MySQL (no app bootstrap)      ║
 * ║    2. Generates a fresh bcrypt hash at runtime           ║
 * ║    3. Inserts the super admin record safely              ║
 * ║                                                          ║
 * ║  WHY NOT in schema.sql?                                  ║
 * ║    Hardcoded bcrypt hashes in SQL are unreliable —       ║
 * ║    different MySQL/collation versions can corrupt them.  ║
 * ║    PHP's password_hash() always produces the correct     ║
 * ║    hash for your exact runtime environment.              ║
 * ╚══════════════════════════════════════════════════════════╝
 */

// ─── Config — edit these before running ──────────────────────────
$config = [
    'host'     => '127.0.0.1',
    'port'     => '3306',
    'database' => 'clinic_master_db',
    'username' => 'root',
    'password' => '',       // your MySQL root password
];

$superAdmin = [
    'name'     => 'Platform Super Admin',
    'email'    => 'superadmin@clinic.io',
    'password' => 'SuperAdmin@2026',   // plain text — hashed below at runtime
    'role'     => 'superadmin',
];
// ─────────────────────────────────────────────────────────────────

echo "\n==============================\n";
echo "  Super Admin Seeder\n";
echo "==============================\n\n";

// 1. Connect to MySQL
try {
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "✅ Connected to: {$config['database']}\n";
} catch (PDOException $e) {
    die("❌ Connection failed: " . $e->getMessage() . "\n\n"
      . "   Check \$config values at the top of this file.\n\n");
}

// 2. Check if super admin already exists
$existing = $pdo->prepare('SELECT id, email FROM super_admins WHERE email = :email');
$existing->execute(['email' => $superAdmin['email']]);
$found = $existing->fetch();

if ($found) {
    echo "⚠️  Super admin already exists (id: {$found['id']}, email: {$found['email']})\n";
    echo "   To reset password, use: php database/master/reset_super_admin_password.php\n\n";
    exit(0);
}

// 3. Generate bcrypt hash at runtime — CORRECT approach
$passwordHash = password_hash($superAdmin['password'], PASSWORD_BCRYPT, ['cost' => 12]);

// 4. Verify the hash works before inserting
if (!password_verify($superAdmin['password'], $passwordHash)) {
    die("❌ Hash verification failed — something is wrong with your PHP bcrypt setup.\n");
}

echo "✅ Password hash generated and verified.\n";

// 5. Insert super admin
$stmt = $pdo->prepare(
    'INSERT INTO super_admins (name, email, password_hash, role, is_active, created_at, updated_at)
     VALUES (:name, :email, :password_hash, :role, 1, NOW(), NOW())'
);

$stmt->execute([
    'name'          => $superAdmin['name'],
    'email'         => $superAdmin['email'],
    'password_hash' => $passwordHash,
    'role'          => $superAdmin['role'],
]);

$id = $pdo->lastInsertId();

echo "✅ Super admin inserted (id: {$id})\n\n";
echo "──────────────────────────────\n";
echo "  Login Credentials\n";
echo "──────────────────────────────\n";
echo "  Email    : {$superAdmin['email']}\n";
echo "  Password : {$superAdmin['password']}\n";
echo "  Role     : {$superAdmin['role']}\n";
echo "──────────────────────────────\n";
echo "  ⚠️  CHANGE PASSWORD AFTER FIRST LOGIN!\n\n";