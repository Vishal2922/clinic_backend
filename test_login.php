<?php
require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap environment
$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Core\MasterDatabase;
use App\Core\TenantDatabase;
use App\Modules\AuthTenant\Services\AuthService;

$masterDb = MasterDatabase::getInstance();
$tenant = $masterDb->fetch('SELECT id, tenant_code, db_name, db_host, db_username, db_password FROM tenants LIMIT 1');

if (!$tenant) {
    echo "No tenant found\n";
    exit;
}

// Connect to tenant DB directly
$dsn = "mysql:host={$tenant['db_host']};dbname={$tenant['db_name']};charset=utf8mb4";
$pdo = new PDO($dsn, $tenant['db_username'], $tenant['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Find a user created recently
$stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "No users found in tenant\n";
    exit;
}

echo "Testing user: {$user['username']}\n";
echo "Password hash length: " . strlen($user['password_hash']) . "\n";
echo "Password hash: " . $user['password_hash'] . "\n";

$testPassword = 'Password@123'; // Typical test password, we will just try to verify
echo "Trying password_verify with '{$testPassword}': ";
var_dump(password_verify($testPassword, $user['password_hash']));

// Try another test
$argonOptions = [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 3,
];
$newHash = password_hash($testPassword, PASSWORD_ARGON2ID, $argonOptions);
echo "\nGenerated new hash for '{$testPassword}': " . $newHash . "\n";
echo "New hash length: " . strlen($newHash) . "\n";
echo "Verifying new hash: ";
var_dump(password_verify($testPassword, $newHash));
