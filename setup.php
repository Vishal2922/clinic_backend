<?php
/**
 * setup.php — CENTRALIZED PROJECT SEED SCRIPT
 * =====================================================
 * This script consolidates all database resetting and seeding logic.
 *
 * Usage:
 *   Full Reset & Seed:        php setup.php
 *   Reset Super Admin Pass:   php setup.php --reset-superadmin-password="NewPassword@123"
 */

ini_set('memory_limit', '512M');

// ==============================================================
// CONFIGURATION (Edit these to match your local setup)
// ==============================================================
$DB_HOST = '127.0.0.1';
$DB_PORT = '3306'; // Make sure this matches your MySQL port
$DB_USER = 'root';
$DB_PASS = '';
$MASTER_DB = 'clinic_master_db';
$ENCRYPTION_KEY = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6'; // Must match .env ENCRYPTION_KEY

$ARGON_OPTIONS = [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 3,
];

// Paths to centralized SQL template files
$SCHEMA_FILE = __DIR__ . '/database/tenant_template/schema.sql';
$SEEDS_FILE  = __DIR__ . '/database/tenant_template/seeds.sql';

// CLI Arguments
$options = getopt('', ['reset-superadmin-password::', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php setup.php                                   -> Full project reset and seed\n";
    echo "  php setup.php --reset-superadmin-password=PASS  -> Reset super admin password only\n";
    exit(0);
}

// Inline encryption helpers
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
function parseSqlFile(string $filePath): array {
    if (!file_exists($filePath)) {
        echo "  [ERROR] SQL file not found: {$filePath}\n";
        exit(1);
    }
    $sql  = file_get_contents($filePath);
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    $result = [];
    foreach ($stmts as $s) {
        $stripped = trim(preg_replace('/--.*$/m', '', $s));
        if (!empty($stripped)) $result[] = $s;
    }
    return $result;
}

// Connect to Database Server
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "\n  [FAILED] Connection error: " . $e->getMessage() . "\n\n";
    echo "  Steps to fix:\n";
    echo "  1. Make sure MySQL is running.\n";
    echo "  2. Update \$DB_PORT if using a different port.\n";
    exit(1);
}

// ==============================================================
// MODE: RESET SUPER ADMIN PASSWORD ONLY
// ==============================================================
if (isset($options['reset-superadmin-password'])) {
    $newPassword = $options['reset-superadmin-password'];
    if (empty($newPassword)) {
        die("❌ Please provide a password: php setup.php --reset-superadmin-password=\"Pass@123\"\n");
    }
    $email = 'superadmin@clinic.io';
    echo "\n==============================\n  Super Admin Password Reset\n==============================\n";

    $pdo->exec("USE {$MASTER_DB}");
    $stmt = $pdo->prepare('SELECT id, email FROM super_admins WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        die("❌ No super admin found with email: {$email}\n");
    }
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $update = $pdo->prepare('UPDATE super_admins SET password_hash = :hash, failed_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE email = :email');
    $update->execute(['hash' => $newHash, 'email' => $email]);

    echo "✅ Password reset successfully for: {$email}\n";
    echo "   New password: {$newPassword}\n\n";
    exit(0);
}

// ==============================================================
// MODE: FULL PROJECT RESET & SEED
// ==============================================================
echo "\n============================================\n";
echo "  PROJECT FULL RESET & SEED\n";
echo "============================================\n\n";

if (!file_exists($SCHEMA_FILE)) { echo "  [FAIL] Missing: {$SCHEMA_FILE}\n"; exit(1); }
if (!file_exists($SEEDS_FILE))  { echo "  [FAIL] Missing: {$SEEDS_FILE}\n";  exit(1); }

// STEP 1 - Connect master DB & Seed Super Admin
echo "--- Connecting to {$MASTER_DB} ---\n";
try {
    $pdo->exec("USE {$MASTER_DB}");
    echo "  [OK] Master DB selected.\n";
} catch (PDOException $e) {
    echo "  [FAILED] Master DB not found.\n  Fix: Run the master DB CREATE TABLE SQL first.\n\n";
    exit(1);
}

$superAdmin = [
    'name'     => 'Platform Super Admin',
    'email'    => 'superadmin@clinic.io',
    'password' => 'SuperAdmin@2026', 
    'role'     => 'superadmin',
];

$existingSuper = $pdo->prepare('SELECT id, email FROM super_admins WHERE email = :email');
$existingSuper->execute(['email' => $superAdmin['email']]);
if (!$existingSuper->fetch()) {
    $passwordHash = password_hash($superAdmin['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare('INSERT INTO super_admins (name, email, password_hash, role, is_active, created_at, updated_at) VALUES (:name, :email, :password_hash, :role, 1, NOW(), NOW())');
    $stmt->execute([
        'name'          => $superAdmin['name'],
        'email'         => $superAdmin['email'],
        'password_hash' => $passwordHash,
        'role'          => $superAdmin['role'],
    ]);
    echo "  [OK] Super Admin seeded.\n";
} else {
    echo "  [OK] Super Admin already exists.\n";
}
echo "\n";

// STEP 2 - Clear Existing Tenants
echo "--- Clearing Existing Tenants ---\n";
$existingTenants = $pdo->query("SELECT tenant_code, db_name, db_username FROM tenants")->fetchAll(PDO::FETCH_ASSOC);
if (empty($existingTenants)) {
    echo "  No existing tenants - fresh install.\n";
} else {
    foreach ($existingTenants as $t) {
        try {
            $pdo->exec("DROP DATABASE IF EXISTS {$t['db_name']}");
            $pdo->exec("DROP USER IF EXISTS '{$t['db_username']}'@'%'");
            echo "  Dropped: {$t['db_name']}\n";
        } catch (Exception $e) {}
    }
}
$pdo->exec("USE {$MASTER_DB}");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE tenants");
$pdo->exec("TRUNCATE TABLE master_audit_logs");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "  [OK] Master DB cleared.\n\n";

$schemaStmts = parseSqlFile($SCHEMA_FILE);
$seedStmts   = parseSqlFile($SEEDS_FILE);

// STEP 3 - Provision 5 Clinics
echo "--- Provisioning tenants, staff, and patients ---\n";

$clinics = [
    ['code' => 'apollo', 'name' => 'Apollo Hospitals',     'email' => 'admin@apollo.com', 'plan' => 'enterprise',   'pass' => 'Apollo@1234'],
    ['code' => 'fortis', 'name' => 'Fortis Healthcare',    'email' => 'admin@fortis.com', 'plan' => 'standard',     'pass' => 'Fortis@1234'],
    ['code' => 'max',    'name' => 'Max Super Speciality', 'email' => 'admin@max.com',    'plan' => 'professional', 'pass' => 'Max@1234'],
    ['code' => 'care',   'name' => 'Care Hospitals',       'email' => 'admin@care.com',   'plan' => 'basic',        'pass' => 'Care@1234'],
    ['code' => 'kims',   'name' => 'KIMS Hospitals',       'email' => 'admin@kims.com',   'plan' => 'basic',        'pass' => 'Kims@1234'],
];

$staffTemplate = [
    ['role_id' => 1, 'username' => 'admin',      'suffix' => 'Admin',        'role' => 'Admin'],
    ['role_id' => 2, 'username' => 'doctor',     'suffix' => 'Dr. Sharma',   'role' => 'Provider'],
    ['role_id' => 3, 'username' => 'nurse',      'suffix' => 'Nurse Priya',  'role' => 'Nurse'],
    ['role_id' => 4, 'username' => 'reception',  'suffix' => 'Anita (Reception)', 'role' => 'Receptionist'],
    ['role_id' => 5, 'username' => 'pharmacist', 'suffix' => 'Pharmacist Raj', 'role' => 'Pharmacist'],
];

$rolePermissions = [
    'Admin'        => ['all'],
    'Provider'     => ['appointments.view', 'appointments.create', 'patients.view', 'patients.create', 'prescriptions.view', 'prescriptions.create', 'billing.view'],
    'Nurse'        => ['appointments.view', 'patients.view', 'vitals.record'],
    'Receptionist' => ['appointments.view', 'appointments.create', 'patients.view', 'billing.view', 'billing.create'],
    'Pharmacist'   => ['prescriptions.view', 'prescriptions.dispense'],
    'Patient'      => ['appointments.view', 'appointments.create', 'prescriptions.view', 'billing.view'],
];

$patientData = [
    ['name' => 'Rajesh Kumar',   'email' => 'patient1@%s.com', 'gender' => 'Male',   'dob' => '1985-06-15', 'history'=>'Diabetes'],
    ['name' => 'Priya Sharma',   'email' => 'patient2@%s.com', 'gender' => 'Female', 'dob' => '1990-07-22', 'history'=>'Asthma'],
    ['name' => 'Arun Patel',     'email' => 'patient3@%s.com', 'gender' => 'Male',   'dob' => '1978-11-08', 'history'=>'Hypertension'],
    ['name' => 'Deepika Rajan',  'email' => 'patient4@%s.com', 'gender' => 'Female', 'dob' => '1995-01-30', 'history'=>'Migraine'],
    ['name' => 'Suresh Iyer',    'email' => 'patient5@%s.com', 'gender' => 'Male',   'dob' => '1965-05-12', 'history'=>'COPD'],
];

$provisioned = [];

foreach ($clinics as $c) {
    $dbName  = "clinic_tenant_{$c['code']}_db";
    $dbUser  = "clinic_{$c['code']}";
    $dbPass  = bin2hex(random_bytes(16));
    $expires = date('Y-m-d H:i:s', strtotime('+365 days'));

    echo "  Processing {$c['code']}...\n";

    try {
        // Create DB and user
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("DROP USER IF EXISTS '{$dbUser}'@'%'");
        $pdo->exec("CREATE USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON {$dbName}.* TO '{$dbUser}'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");

        // Use tenant DB
        $pdo->exec("USE {$dbName}");
        foreach ($schemaStmts as $sql) $pdo->exec($sql);
        foreach ($seedStmts as $sql) $pdo->exec($sql);

        // Register tenant in master DB
        $pdo->exec("USE {$MASTER_DB}");
        $stmt2 = $pdo->prepare("INSERT INTO tenants (tenant_code, name, slug, email, plan, status, subscription_starts_at, subscription_expires_at, db_host, db_port, db_name, db_username, db_password, max_users, max_patients, max_doctors, created_by_super_admin_id, created_at, updated_at) VALUES (:code, :name, :slug, :email, :plan, 'active', NOW(), :expires, :host, :port, :dbname, :dbuser, :dbpass, 100, 500, 20, 1, NOW(), NOW())");
        $stmt2->execute(['code'=>$c['code'], 'name'=>$c['name'], 'slug'=>$c['code'], 'email'=>$c['email'], 'plan'=>$c['plan'], 'expires'=>$expires, 'host'=>$DB_HOST, 'port'=>$DB_PORT, 'dbname'=>$dbName, 'dbuser'=>$dbUser, 'dbpass'=>$dbPass]);
        $tenantId = $pdo->lastInsertId();

        $pdo->exec("USE {$dbName}");

        $pdo->exec("USE {$dbName}");
        $createdUsers = [];

        // Seed Staff
        foreach ($staffTemplate as $staff) {
            $username = "{$c['code']}_{$staff['username']}";
            $email    = "{$staff['username']}@{$c['code']}.com";
            $fullName = "{$staff['suffix']} - " . ucfirst($c['code']);
            $hashed   = password_hash($c['pass'], PASSWORD_ARGON2ID, $ARGON_OPTIONS);

            $stmt = $pdo->prepare("INSERT INTO users (role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, status, created_at, updated_at) VALUES (:rid, :u, :e, :eh, :p, :n, 'active', NOW(), NOW())");
            $stmt->execute(['rid'=>$staff['role_id'], 'u'=>$username, 'e'=>aes_encrypt($email, $ENCRYPTION_KEY), 'eh'=>sha_hash($email), 'p'=>$hashed, 'n'=>aes_encrypt($fullName, $ENCRYPTION_KEY)]);
            $uid = $pdo->lastInsertId();

            $pdo->exec("INSERT INTO staff (user_id, tenant_id, status, created_at) VALUES ({$uid}, {$tenantId}, 'active', NOW())");
            $createdUsers[] = ['username' => $username, 'role' => $staff['role']];
        }

        // Seed 5 Patients
        $pIds = [];
        foreach ($patientData as $p) {
            $email = sprintf($p['email'], $c['code']);
            $uName = "{$c['code']}_patient_" . (count($pIds) + 1);
            $hashed = password_hash($c['pass'], PASSWORD_ARGON2ID, $ARGON_OPTIONS);

            // Patient User
            $stmt = $pdo->prepare("INSERT INTO users (role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, status, created_at, updated_at) VALUES (6, :u, :e, :eh, :p, :n, 'active', NOW(), NOW())");
            $stmt->execute(['u'=>$uName, 'e'=>aes_encrypt($email, $ENCRYPTION_KEY), 'eh'=>sha_hash($email), 'p'=>$hashed, 'n'=>aes_encrypt($p['name'], $ENCRYPTION_KEY)]);
            $uid = $pdo->lastInsertId();

            // Patient Record
            $stmtP = $pdo->prepare("INSERT INTO patients (tenant_id, encrypted_name, name_hash, encrypted_email, email_hash, encrypted_gender, encrypted_medical_history, encrypted_date_of_birth, status, created_at) VALUES (:tid, :n, :nh, :e, :eh, :g, :h, :d, 'active', NOW())");
            $stmtP->execute([
                'tid' => $tenantId,
                'n'   => aes_encrypt($p['name'], $ENCRYPTION_KEY),
                'nh'  => sha_hash($p['name']),
                'e'   => aes_encrypt($email, $ENCRYPTION_KEY),
                'eh'  => sha_hash($email),
                'g'   => aes_encrypt($p['gender'], $ENCRYPTION_KEY),
                'h'   => aes_encrypt($p['history'] ?? 'None', $ENCRYPTION_KEY),
                'd'   => aes_encrypt($p['dob'], $ENCRYPTION_KEY),
            ]);
            $pid = $pdo->lastInsertId();
            $pIds[] = $pid;

            // Link user to patient
            $pdo->prepare("UPDATE users SET patient_id = :pid WHERE id = :uid")->execute(['pid'=>$pid, 'uid'=>$uid]);
            $createdUsers[] = ['username' => $uName, 'role' => 'Patient'];
            
            // Also register patient as staff (as requested: "all patients are must be create in staff with role patient")
            $pdo->prepare("INSERT INTO staff (user_id, tenant_id, encrypted_department, status, created_at, updated_at) VALUES (:uid, :tid, :dept, 'active', NOW(), NOW())")
                ->execute(['uid'=>$uid, 'tid'=>$tenantId, 'dept'=>aes_encrypt('Patient Services', $ENCRYPTION_KEY)]);

            // Seed Clinical Data for each patient (2 instances each)
            for ($i = 1; $i <= 2; $i++) {
                // Appointment
                $stmtA = $pdo->prepare("INSERT INTO appointments (tenant_id, patient_id, doctor_id, appointment_time, encrypted_reason, status, created_at) VALUES (:tid, :pid, 2, DATE_ADD(NOW(), INTERVAL :days DAY), :reason, 'completed', NOW())");
                $stmtA->execute(['tid'=>$tenantId, 'pid'=>$pid, 'days' => ($i * 5), 'reason' => aes_encrypt("General Followup $i", $ENCRYPTION_KEY)]);
                $aid = $pdo->lastInsertId();

                // Prescription
                $stmtPr = $pdo->prepare("INSERT INTO prescriptions (tenant_id, appointment_id, patient_id, provider_id, encrypted_medicine_name, encrypted_dosage, duration_days, status, created_at) VALUES (:tid, :aid, :pid, 2, :m, :d, 7, 'dispensed', NOW())");
                $stmtPr->execute(['tid'=>$tenantId, 'aid'=>$aid, 'pid'=>$pid, 'm'=>aes_encrypt("Medicine $i", $ENCRYPTION_KEY), 'd'=>aes_encrypt("1x daily", $ENCRYPTION_KEY)]);

                // Billing
                $invNum = strtoupper($c['code']) . "-INV-" . $pid . "-" . $i;
                $stmtI = $pdo->prepare("INSERT INTO invoices (tenant_id, patient_id, appointment_id, provider_id, invoice_number, amount, total_amount, paid_amount, status, created_at) VALUES (:tid, :pid, :aid, 2, :num, 500.00, 500.00, 500.00, 'paid', NOW())");
                $stmtI->execute(['tid'=>$tenantId, 'pid'=>$pid, 'aid'=>$aid, 'num'=>$invNum]);
            }
        }

        $provisioned[] = ['code'=>$c['code'], 'name'=>$c['name'], 'pass'=>$c['pass'], 'users'=>$createdUsers];
        echo "    [OK] {$c['code']} provisioned.\n";

    } catch (Exception $e) {
        echo "  [FAILED] {$c['code']}: " . $e->getMessage() . "\n";
    }
}

// Final Summary
echo "\n============================================\n";
echo "  SETUP COMPLETE\n";
echo "============================================\n";
foreach ($provisioned as $t) {
    echo "\nTenant: {$t['code']} (Password: {$t['pass']})\n";
    foreach ($t['users'] as $u) {
        echo "  - " . str_pad($u['role'], 12) . ": {$u['username']}\n";
    }
}
echo "\nAll redundant seed files can now be safely removed.\n";
