<?php
/**
 * reset_and_seed_tenants.php — CENTRALIZED SEED SCRIPT
 * =====================================================
 * Single script to reset + re-provision all dev tenants.
 * Schema  → database/tenant_template/schema.sql
 * Seeds   → database/tenant_template/seeds.sql
 *
 * Run from terminal:  php reset_and_seed_tenants.php
 */

// ==============================================================
// EDIT THESE 4 LINES TO MATCH YOUR MYSQL
// ==============================================================
$DB_HOST = 'localhost';
$DB_PORT = '3306';
$DB_USER = 'root';
$DB_PASS = '';
// ==============================================================

$ENCRYPTION_KEY = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6'; // must match .env ENCRYPTION_KEY

// ── Paths to centralized SQL files ───────────────────────────
$SCHEMA_FILE = __DIR__ . '/database/tenant_template/schema.sql';
$SEEDS_FILE  = __DIR__ . '/database/tenant_template/seeds.sql';

// ── Inline encrypt / hash (replaces CryptoService) ───────────
function aes_encrypt(string $plainText, string $key): string {
    $cipher   = 'aes-256-cbc';
    $ivLength = openssl_cipher_iv_length($cipher);
    $iv       = openssl_random_pseudo_bytes($ivLength);
    $enc      = openssl_encrypt($plainText, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}
function sha_hash(string $data): string {
    return hash('sha256', strtolower(trim($data)));
}

/**
 * Parse a .sql file into individual statements, skipping comments
 */
function parseSqlFile(string $filePath): array {
    if (!file_exists($filePath)) {
        echo "  [ERROR] SQL file not found: {$filePath}\n";
        exit(1);
    }
    $sql  = file_get_contents($filePath);
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    $result = [];
    foreach ($stmts as $s) {
        // Skip pure-comment blocks
        $stripped = trim(preg_replace('/--.*$/m', '', $s));
        if (!empty($stripped)) $result[] = $s;
    }
    return $result;
}

// ─────────────────────────────────────────────────────────────
echo "\n============================================\n";
echo "  TENANT RESET & SEED (CENTRALIZED)\n";
echo "============================================\n\n";

// Verify SQL files exist
echo "--- Verifying SQL template files ---\n";
if (!file_exists($SCHEMA_FILE)) { echo "  [FAIL] Missing: {$SCHEMA_FILE}\n"; exit(1); }
if (!file_exists($SEEDS_FILE))  { echo "  [FAIL] Missing: {$SEEDS_FILE}\n";  exit(1); }
echo "  [OK] schema.sql found\n";
echo "  [OK] seeds.sql found\n\n";

// STEP 0 - Test connection
echo "--- Testing MySQL Connection ---\n";
echo "  Host: $DB_HOST  Port: $DB_PORT  User: $DB_USER\n";

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "  [OK] MySQL connected.\n\n";
} catch (PDOException $e) {
    echo "\n  [FAILED] " . $e->getMessage() . "\n\n";
    echo "  Steps to fix:\n";
    echo "  1. Make sure WAMP is running (green icon in taskbar)\n";
    echo "  2. Left-click WAMP tray -> MySQL -> check the port shown\n";
    echo "  3. Update DB_PORT at the top of this file\n";
    echo "  4. Try changing DB_HOST between 'localhost' and '127.0.0.1'\n\n";
    exit(1);
}

// STEP 1 - Connect master DB
echo "--- Connecting to clinic_master_db ---\n";
try {
    $pdo->exec("USE clinic_master_db");
    echo "  [OK] clinic_master_db selected.\n\n";
} catch (PDOException $e) {
    echo "  [FAILED] clinic_master_db not found.\n";
    echo "  Fix: Run the CREATE TABLE SQL in phpMyAdmin first.\n\n";
    exit(1);
}

// STEP 2 - Drop existing tenant DBs
echo "--- Clearing Existing Tenants ---\n";
$existing = $pdo->query("SELECT tenant_code, db_name, db_username FROM tenants")->fetchAll(PDO::FETCH_ASSOC);

if (empty($existing)) {
    echo "  No existing tenants - fresh install, skipping drop.\n";
} else {
    foreach ($existing as $t) {
        try {
            $pdo->exec("DROP DATABASE IF EXISTS {$t['db_name']}");
            $pdo->exec("DROP USER IF EXISTS '{$t['db_username']}'@'%'");
            echo "  Dropped: {$t['db_name']}\n";
        } catch (Exception $e) {
            echo "  [WARN] Could not drop {$t['db_name']}: " . $e->getMessage() . "\n";
        }
    }
}

$pdo->exec("USE clinic_master_db");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE tenants");
$pdo->exec("TRUNCATE TABLE master_audit_logs");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "  [OK] Master DB cleared.\n\n";

// ── Parse centralized SQL files ──────────────────────────────
$schemaStmts = parseSqlFile($SCHEMA_FILE);
$seedStmts   = parseSqlFile($SEEDS_FILE);
echo "  Schema: " . count($schemaStmts) . " statements\n";
echo "  Seeds:  " . count($seedStmts)  . " statements\n\n";

// STEP 3 - Provision 5 clinics
echo "--- Provisioning Tenants ---\n";

$clinics = [
    ['code' => 'apollo', 'name' => 'Apollo Hospitals',     'email' => 'admin@apollo.com', 'plan' => 'enterprise',   'pass' => 'Apollo@1234'],
    ['code' => 'fortis', 'name' => 'Fortis Healthcare',    'email' => 'admin@fortis.com', 'plan' => 'standard',     'pass' => 'Fortis@1234'],
    ['code' => 'max',    'name' => 'Max Super Speciality', 'email' => 'admin@max.com',    'plan' => 'professional', 'pass' => 'Max@1234'],
    ['code' => 'care',   'name' => 'Care Hospitals',       'email' => 'admin@care.com',   'plan' => 'basic',        'pass' => 'Care@1234'],
    ['code' => 'kims',   'name' => 'KIMS Hospitals',       'email' => 'admin@kims.com',   'plan' => 'basic',        'pass' => 'Kims@1234'],
];

$planDays  = ['basic' => 30, 'standard' => 90, 'professional' => 180, 'enterprise' => 365];
$planUsers = ['basic' => 10, 'standard' => 25, 'professional' => 50,  'enterprise' => 200];

/**
 * Staff users to create per tenant (all roles, testable credentials)
 * role_id: 1=Admin, 2=Provider, 3=Nurse, 4=Receptionist, 5=Pharmacist
 */
$staffTemplate = [
    ['suffix' => 'admin',        'role_id' => 1, 'role' => 'Admin',        'email_tpl' => 'admin@%s.com',        'name_tpl' => 'Admin - %s'],
    ['suffix' => 'doctor',       'role_id' => 2, 'role' => 'Provider',     'email_tpl' => 'doctor@%s.com',       'name_tpl' => 'Dr. %s'],
    ['suffix' => 'nurse',        'role_id' => 3, 'role' => 'Nurse',        'email_tpl' => 'nurse@%s.com',        'name_tpl' => 'Nurse - %s'],
    ['suffix' => 'reception',    'role_id' => 4, 'role' => 'Receptionist', 'email_tpl' => 'reception@%s.com',    'name_tpl' => 'Receptionist - %s'],
    ['suffix' => 'pharmacist',   'role_id' => 5, 'role' => 'Pharmacist',   'email_tpl' => 'pharmacist@%s.com',   'name_tpl' => 'Pharmacist - %s'],
];

$provisioned = [];

foreach ($clinics as $c) {
    $dbName   = "clinic_tenant_{$c['code']}_db";
    $dbUser   = "clinic_{$c['code']}";
    $dbPass   = bin2hex(random_bytes(16));
    $expires  = date('Y-m-d H:i:s', strtotime('+' . ($planDays[$c['plan']] ?? 30) . ' days'));

    echo "  Provisioning {$c['code']}...\n";

    try {
        // Create DB
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Create DB user
        $pdo->exec("DROP USER IF EXISTS '{$dbUser}'@'%'");
        $pdo->exec("CREATE USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON {$dbName}.* TO '{$dbUser}'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");

        // Run centralized schema
        $pdo->exec("USE {$dbName}");
        foreach ($schemaStmts as $sql) {
            $pdo->exec($sql);
        }

        // Run centralized seeds (roles, permissions, role-permissions)
        foreach ($seedStmts as $sql) {
            $pdo->exec($sql);
        }

        // Create all staff users
        $createdUsers = [];
        foreach ($staffTemplate as $staff) {
            $username  = $c['code'] . '_' . $staff['suffix'];
            $password  = $c['pass']; // same password for all users in dev
            $email     = sprintf($staff['email_tpl'], $c['code']);
            $fullName  = sprintf($staff['name_tpl'], $c['name']);

            $hashedPass = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3]);
            $encEmail   = aes_encrypt($email, $ENCRYPTION_KEY);
            $emailHash  = sha_hash($email);
            $encName    = aes_encrypt($fullName, $ENCRYPTION_KEY);

            $stmt = $pdo->prepare("INSERT INTO users (role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, status, created_at, updated_at) VALUES (:role_id, :username, :enc_email, :email_hash, :password, :enc_name, 'active', NOW(), NOW())");
            $stmt->execute([
                'role_id'    => $staff['role_id'],
                'username'   => $username,
                'enc_email'  => $encEmail,
                'email_hash' => $emailHash,
                'password'   => $hashedPass,
                'enc_name'   => $encName,
            ]);

            $createdUsers[] = ['username' => $username, 'role' => $staff['role']];
        }

        // Register tenant in master DB
        $pdo->exec("USE clinic_master_db");
        $maxUsers = $planUsers[$c['plan']] ?? 10;
        $stmt2 = $pdo->prepare("INSERT INTO tenants (tenant_code, name, slug, email, plan, status, subscription_starts_at, subscription_expires_at, db_host, db_port, db_name, db_username, db_password, max_users, max_patients, max_doctors, created_by_super_admin_id, created_at, updated_at) VALUES (:code, :name, :slug, :email, :plan, 'active', NOW(), :expires, :host, :port, :dbname, :dbuser, :dbpass, :maxu, 500, 10, 1, NOW(), NOW())");
        $stmt2->execute([
            'code'    => $c['code'],
            'name'    => $c['name'],
            'slug'    => $c['code'],
            'email'   => $c['email'],
            'plan'    => $c['plan'],
            'expires' => $expires,
            'host'    => $DB_HOST,
            'port'    => $DB_PORT,
            'dbname'  => $dbName,
            'dbuser'  => $dbUser,
            'dbpass'  => $dbPass,
            'maxu'    => $maxUsers,
        ]);
        $tenantId = $pdo->lastInsertId();

        $pdo->exec("INSERT INTO master_audit_logs (super_admin_id, action, resource_type, resource_id, details, ip_address, created_at) VALUES (1, 'tenant_created', 'tenant', {$tenantId}, '{\"tenant_code\":\"{$c['code']}\"}', '127.0.0.1', NOW())");

        $provisioned[] = [
            'code'     => $c['code'],
            'name'     => $c['name'],
            'dbName'   => $dbName,
            'tenantId' => $tenantId,
            'pass'     => $c['pass'],
            'users'    => $createdUsers,
        ];

        echo "  [OK] {$c['code']} -> DB: {$dbName} (" . count($createdUsers) . " users)\n";

    } catch (Exception $e) {
        echo "  [FAILED] {$c['code']}: " . $e->getMessage() . "\n";
    }
}

// STEP 4 - Seed sample data
echo "\n--- Seeding Sample Data ---\n";

$patients = [
    ['name' => 'John Doe',        'phone' => '+919876543210', 'email' => 'john@email.com',    'history' => 'Type 2 Diabetes. On Metformin.'],
    ['name' => 'Jane Smith',      'phone' => '+919876543211', 'email' => 'jane@email.com',    'history' => 'Asthma. Uses Salbutamol inhaler PRN.'],
    ['name' => 'Michael Johnson', 'phone' => '+919876543212', 'email' => 'michael@email.com', 'history' => 'Hypertension. Taking Amlodipine 5mg.'],
];
$reasons = [
    'Routine monthly diabetes checkup.',
    'Persistent cough for 2 weeks.',
    'Blood pressure monitoring.',
];

foreach ($provisioned as $t) {
    echo "  Seeding {$t['code']}...\n";
    try {
        $pdo->exec("USE {$t['dbName']}");

        // Doctor user_id = 2 (the Provider user created above)
        $doctorId = 2;

        foreach ($patients as $i => $p) {
            $stmt = $pdo->prepare("INSERT INTO patients (tenant_id, encrypted_name, name_hash, encrypted_phone, phone_hash, encrypted_email, email_hash, encrypted_medical_history, status, created_at) VALUES (:tid, :enc_name, :hash_name, :enc_phone, :hash_phone, :enc_email, :hash_email, :enc_hist, 'active', NOW())");
            $stmt->execute([
                'tid'        => $t['tenantId'],
                'enc_name'   => aes_encrypt($p['name'],    $ENCRYPTION_KEY),
                'hash_name'  => sha_hash($p['name']),
                'enc_phone'  => aes_encrypt($p['phone'],   $ENCRYPTION_KEY),
                'hash_phone' => sha_hash($p['phone']),
                'enc_email'  => aes_encrypt($p['email'],   $ENCRYPTION_KEY),
                'hash_email' => sha_hash($p['email']),
                'enc_hist'   => aes_encrypt($p['history'], $ENCRYPTION_KEY),
            ]);
            $pid = $pdo->lastInsertId();

            // Appointment (assigned to doctor user)
            $appt = $pdo->prepare("INSERT INTO appointments (tenant_id, patient_id, doctor_id, appointment_time, encrypted_reason, status, created_at) VALUES (:tid, :pid, :did, DATE_ADD(NOW(), INTERVAL :day DAY), :enc_reason, 'scheduled', NOW())");
            $appt->execute([
                'tid'        => $t['tenantId'],
                'pid'        => $pid,
                'did'        => $doctorId,
                'day'        => $i + 1, // stagger: +1, +2, +3 days
                'enc_reason' => aes_encrypt($reasons[$i], $ENCRYPTION_KEY),
            ]);
        }

        // Seed a sample welcome notification for the admin user
        $pdo->exec("INSERT INTO notifications (tenant_id, user_id, type, title, message, created_at) VALUES ({$t['tenantId']}, 1, 'system', 'Welcome to ClinicOS!', 'Your clinic has been provisioned successfully. Start by reviewing your dashboard.', NOW())");

        echo "  [OK] 3 patients + 3 appointments + 1 notification seeded.\n";
    } catch (Exception $e) {
        echo "  [FAILED] {$t['code']}: " . $e->getMessage() . "\n";
    }
}

// SUMMARY
echo "\n============================================\n";
echo "  ALL DONE!\n";
echo "============================================\n\n";
echo "Login Credentials (same password for all roles per tenant):\n";
echo "--------------------------------------------\n";
foreach ($provisioned as $t) {
    echo "  Tenant: {$t['code']}  |  Password: {$t['pass']}\n";
    foreach ($t['users'] as $u) {
        printf("    %-22s  (%s)\n", $u['username'], $u['role']);
    }
    echo "--------------------------------------------\n";
}
echo "\nExample: Login to apollo as Provider  ->  apollo_doctor / Apollo@1234\n";
echo "Example: Login to apollo as Nurse     ->  apollo_nurse  / Apollo@1234\n";
echo "\nFrontend .env: REACT_APP_TENANT_CODE=apollo\n\n";