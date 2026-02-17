<?php
/**
 * Seeder Script — Creates admin users for all tenants
 * Usage: php seed.php
 */

define('BASE_PATH', __DIR__);

spl_autoload_register(function ($class) {
    $prefix  = 'App\\';
    $baseDir = BASE_PATH . '/app/';
    $len     = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});

require_once BASE_PATH . '/app/Helpers/functions.php';
\App\Helpers\EnvLoader::load(BASE_PATH . '/.env');

$dbConfig = require BASE_PATH . '/config/database.php';
$db       = \App\Core\Database::getInstance($dbConfig);
$crypto   = new \App\Core\Security\CryptoService();

echo "╔══════════════════════════════════════╗\n";
echo "║     CLINIC DB SEEDER                 ║\n";
echo "╚══════════════════════════════════════╝\n\n";

// Define admin users for each tenant
$admins = [
    [
        'tenant_id'   => 1,
        'tenant_code' => 'CLINIC001',
        'role_id'     => 1,     // Admin role for tenant 1
        'username'    => 'admin',
        'password'    => 'Admin@123',
        'email'       => 'admin@cityclinic.com',
        'full_name'   => 'City Clinic Admin',
        'phone'       => '+1111111111',
    ],
    [
        'tenant_id'   => 2,
        'tenant_code' => 'CLINIC002',
        'role_id'     => 7,     // Admin role for tenant 2
        'username'    => 'admin',
        'password'    => 'Admin@123',
        'email'       => 'admin@sunrisemed.com',
        'full_name'   => 'Sunrise Medical Admin',
        'phone'       => '+2222222222',
    ],
];

foreach ($admins as $admin) {
    echo "─── Tenant: {$admin['tenant_code']} ───\n";

    // Check if admin already exists
    $existing = $db->fetch(
        "SELECT id FROM users WHERE username = :username AND tenant_id = :tid",
        ['username' => $admin['username'], 'tid' => $admin['tenant_id']]
    );

    if ($existing) {
        echo "  ⚠ Admin already exists (ID: {$existing['id']}). Skipping.\n\n";
        continue;
    }

    $userId = $db->insert(
        'INSERT INTO users (tenant_id, role_id, username, encrypted_email, email_hash, 
         password_hash, encrypted_full_name, encrypted_phone, status) 
         VALUES (:tid, :rid, :username, :enc_email, :email_hash, 
         :pass_hash, :enc_name, :enc_phone, :status)',
        [
            'tid'        => $admin['tenant_id'],
            'rid'        => $admin['role_id'],
            'username'   => $admin['username'],
            'enc_email'  => $crypto->encrypt($admin['email']),
            'email_hash' => $crypto->hash($admin['email']),
            'pass_hash'  => password_hash($admin['password'], PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 3,
            ]),
            'enc_name'   => $crypto->encrypt($admin['full_name']),
            'enc_phone'  => $crypto->encrypt($admin['phone']),
            'status'     => 'active',
        ]
    );

    echo "  ✓ Admin created!\n";
    echo "    ID       : {$userId}\n";
    echo "    Username : {$admin['username']}\n";
    echo "    Password : {$admin['password']}\n";
    echo "    Email    : {$admin['email']}\n";
    echo "    Tenant   : {$admin['tenant_code']}\n";

    // Verify encryption
    $user = $db->fetch(
        'SELECT encrypted_email, encrypted_full_name FROM users WHERE id = :id',
        ['id' => $userId]
    );
    echo "    Decrypt  : " . $crypto->decrypt($user['encrypted_email']) . " ✓\n\n";
}

echo "╔══════════════════════════════════════╗\n";
echo "║     SEEDER COMPLETE                  ║\n";
echo "╚══════════════════════════════════════╝\n\n";

// Show summary
echo "Database Summary:\n";
$tenants = $db->fetchAll('SELECT * FROM tenants');
foreach ($tenants as $t) {
    $userCount = $db->fetch(
        'SELECT COUNT(*) as c FROM users WHERE tenant_id = :tid AND deleted_at IS NULL',
        ['tid' => $t['id']]
    );
    $roleCount = $db->fetch(
        'SELECT COUNT(*) as c FROM roles WHERE tenant_id = :tid',
        ['tid' => $t['id']]
    );
    echo "  [{$t['tenant_code']}] {$t['name']}\n";
    echo "    Users: {$userCount['c']}, Roles: {$roleCount['c']}\n";
}
echo "\nPermissions: " . $db->fetch('SELECT COUNT(*) as c FROM permissions')['c'] . " total\n";