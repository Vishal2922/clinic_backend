<?php
/**
 * Extended Seeder — Fills real AES-encrypted values into placeholder user rows
 * =============================================================================
 * Run ORDER:
 *   1. mysql -u root -p < clinic_db_setup.sql   ← creates schema + placeholder users
 *   2. php seed_extended.php                     ← THIS file: encrypts PII properly
 *
 * clinic_db_setup.sql inserts placeholder user rows (id 1-8) with
 * encrypted_email = 'PLACEHOLDER'. This script UPDATES those rows with real
 * AES-256 encrypted values and Argon2ID password hashes so login works.
 *
 * Credentials after running:
 *   CLINIC001  admin          Admin@123
 *   CLINIC001  dr_smith       Doctor@123
 *   CLINIC001  nurse_jen      Nurse@123
 *   CLINIC001  receptionist1  Staff@123
 *   CLINIC001  pharmacist1    Pharma@123
 *   CLINIC001  patient1       Patient@123
 *   CLINIC002  admin          Admin@123
 *   CLINIC002  dr_patel       Doctor@123
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

echo "╔══════════════════════════════════════════════╗\n";
echo "║     CLINIC EXTENDED SEEDER                   ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";
echo "Strategy: UPDATE existing placeholder rows from clinic_db_setup.sql\n\n";

// Fixed IDs match exactly what clinic_db_setup.sql inserted
$users = [
    // TENANT 1 (CLINIC001)
    ['id'=>1,'tenant_id'=>1,'tenant_code'=>'CLINIC001','role_id'=>1,
     'username'=>'admin',        'password'=>'Admin@123',
     'email'=>'admin@cityclinic.com',     'full_name'=>'City Clinic Admin',     'phone'=>'+1111111111'],

    ['id'=>2,'tenant_id'=>1,'tenant_code'=>'CLINIC001','role_id'=>2,
     'username'=>'dr_smith',     'password'=>'Doctor@123',
     'email'=>'drsmith@cityclinic.com',   'full_name'=>'Dr. John Smith',        'phone'=>'+1111111112'],

    ['id'=>3,'tenant_id'=>1,'tenant_code'=>'CLINIC001','role_id'=>3,
     'username'=>'nurse_jen',    'password'=>'Nurse@123',
     'email'=>'jen.nurse@cityclinic.com', 'full_name'=>'Jennifer Adams',        'phone'=>'+1111111113'],

    ['id'=>4,'tenant_id'=>1,'tenant_code'=>'CLINIC001','role_id'=>4,
     'username'=>'receptionist1','password'=>'Staff@123',
     'email'=>'reception@cityclinic.com', 'full_name'=>'Maya Patel',            'phone'=>'+1111111114'],

    ['id'=>5,'tenant_id'=>1,'tenant_code'=>'CLINIC001','role_id'=>5,
     'username'=>'pharmacist1',  'password'=>'Pharma@123',
     'email'=>'pharmacy@cityclinic.com',  'full_name'=>'Raj Kumar',             'phone'=>'+1111111115'],

    ['id'=>6,'tenant_id'=>1,'tenant_code'=>'CLINIC001','role_id'=>6,
     'username'=>'patient1',     'password'=>'Patient@123',
     'email'=>'alice@email.com',          'full_name'=>'Alice Johnson',         'phone'=>'+919876543210'],

    // TENANT 2 (CLINIC002)
    ['id'=>7,'tenant_id'=>2,'tenant_code'=>'CLINIC002','role_id'=>7,
     'username'=>'admin',        'password'=>'Admin@123',
     'email'=>'admin@sunrisemed.com',     'full_name'=>'Sunrise Medical Admin', 'phone'=>'+2222222221'],

    ['id'=>8,'tenant_id'=>2,'tenant_code'=>'CLINIC002','role_id'=>8,
     'username'=>'dr_patel',     'password'=>'Doctor@123',
     'email'=>'drpatel@sunrisemed.com',   'full_name'=>'Dr. Priya Patel',       'phone'=>'+2222222222'],
];

foreach ($users as $u) {
    echo "─── [{$u['tenant_code']}] {$u['username']} (id={$u['id']}) ───\n";

    $argon = password_hash($u['password'], PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 3,
    ]);

    $existing = $db->fetch('SELECT id FROM users WHERE id = :id', ['id' => $u['id']]);

    if ($existing) {
        // UPDATE the placeholder row — IDs stay the same, FKs from appointments/invoices remain valid
        $db->execute(
            'UPDATE users SET
                encrypted_email     = :enc_email,
                email_hash          = :email_hash,
                password_hash       = :pass_hash,
                encrypted_full_name = :enc_name,
                encrypted_phone     = :enc_phone,
                status              = :status
             WHERE id = :id',
            [
                'enc_email'  => $crypto->encrypt($u['email']),
                'email_hash' => $crypto->hash($u['email']),
                'pass_hash'  => $argon,
                'enc_name'   => $crypto->encrypt($u['full_name']),
                'enc_phone'  => $crypto->encrypt($u['phone']),
                'status'     => 'active',
                'id'         => $u['id'],
            ]
        );
        echo "  ✓ Updated (placeholder -> real encrypted values)\n";
    } else {
        // Fallback: SQL not run first — insert fresh
        $newId = $db->insert(
            'INSERT INTO users (tenant_id, role_id, username, encrypted_email, email_hash,
             password_hash, encrypted_full_name, encrypted_phone, status)
             VALUES (:tid, :rid, :username, :enc_email, :email_hash,
             :pass_hash, :enc_name, :enc_phone, :status)',
            [
                'tid'        => $u['tenant_id'],
                'rid'        => $u['role_id'],
                'username'   => $u['username'],
                'enc_email'  => $crypto->encrypt($u['email']),
                'email_hash' => $crypto->hash($u['email']),
                'pass_hash'  => $argon,
                'enc_name'   => $crypto->encrypt($u['full_name']),
                'enc_phone'  => $crypto->encrypt($u['phone']),
                'status'     => 'active',
            ]
        );
        echo "  WARNING: Placeholder missing - inserted fresh (ID: {$newId})\n";
        echo "           Run clinic_db_setup.sql first for predictable IDs.\n";
    }

    echo "    Username : {$u['username']}\n";
    echo "    Password : {$u['password']}\n";
    echo "    Email    : {$u['email']}\n\n";
}

echo "╔══════════════════════════════════════════════╗\n";
echo "║     SEEDER COMPLETE                          ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

$counts = $db->fetchAll(
    'SELECT t.tenant_code, t.name,
     (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS users,
     (SELECT COUNT(*) FROM patients p WHERE p.tenant_id = t.id) AS patients,
     (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = t.id) AS appointments,
     (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id) AS invoices
     FROM tenants t'
);

echo "Database Summary:\n";
foreach ($counts as $row) {
    printf(
        "  [%s] %s\n    Users: %d  |  Patients: %d  |  Appointments: %d  |  Invoices: %d\n",
        $row['tenant_code'], $row['name'],
        $row['users'], $row['patients'], $row['appointments'], $row['invoices']
    );
}

echo "\nAll users ready. Login with the credentials listed above.\n";