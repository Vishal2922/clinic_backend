<?php
/**
 * reset_and_seed_tenants.php - FULLY STANDALONE VERSION
 * No app classes needed. Pure PHP + PDO only.
 * Run from terminal: php reset_and_seed_tenants.php
 */

// ==============================================================
// EDIT THESE 4 LINES TO MATCH YOUR MYSQL
// ==============================================================
$DB_HOST = 'localhost';   // if fails, try: 127.0.0.1
$DB_PORT = '3306';        // WAMP: check tray icon -> MySQL -> port
$DB_USER = 'root';
$DB_PASS = '';            // blank by default on WAMP/XAMPP
// ==============================================================

$ENCRYPTION_KEY = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6'; // must match .env ENCRYPTION_KEY

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

// ── Tenant schema tables ──────────────────────────────────────
function getTenantSchema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS roles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            description VARCHAR(255),
            is_system_role TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255),
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS role_permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role_id INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_role_perm (role_id, permission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role_id INT UNSIGNED NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            encrypted_email TEXT,
            email_hash VARCHAR(64),
            password_hash VARCHAR(255) NOT NULL,
            encrypted_full_name TEXT,
            encrypted_phone TEXT,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            deleted_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS refresh_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            family VARCHAR(64),
            expires_at DATETIME NOT NULL,
            revoked TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS patients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED,
            encrypted_name TEXT,
            name_hash VARCHAR(64),
            encrypted_phone TEXT,
            phone_hash VARCHAR(64),
            encrypted_email TEXT,
            email_hash VARCHAR(64),
            encrypted_medical_history TEXT,
            encrypted_date_of_birth TEXT,
            encrypted_gender TEXT,
            encrypted_blood_group TEXT,
            encrypted_address TEXT,
            encrypted_emergency_contact TEXT,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            deleted_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS staff (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED,
            tenant_id INT UNSIGNED,
            encrypted_department TEXT,
            encrypted_specialization TEXT,
            encrypted_license_number TEXT,
            encrypted_notes TEXT,
            hire_date DATE,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            deleted_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS appointments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED,
            patient_id INT UNSIGNED NOT NULL,
            doctor_id INT UNSIGNED NOT NULL,
            appointment_time DATETIME NOT NULL,
            encrypted_reason TEXT,
            status VARCHAR(30) DEFAULT 'scheduled',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            deleted_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS appointment_notes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED,
            appointment_id INT UNSIGNED NOT NULL,
            author_id INT UNSIGNED NOT NULL,
            message_encrypted TEXT,
            note_type VARCHAR(30) DEFAULT 'note',
            visible_to_role VARCHAR(30) DEFAULT 'all',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            deleted_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS prescriptions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED,
            appointment_id INT UNSIGNED,
            patient_id INT UNSIGNED NOT NULL,
            provider_id INT UNSIGNED NOT NULL,
            pharmacist_id INT UNSIGNED,
            encrypted_medicine_name TEXT,
            encrypted_dosage TEXT,
            encrypted_notes TEXT,
            duration_days INT DEFAULT 7,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS invoices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED,
            invoice_number VARCHAR(30) UNIQUE,
            patient_id INT UNSIGNED NOT NULL,
            provider_id INT UNSIGNED,
            appointment_id INT UNSIGNED,
            amount DECIMAL(10,2) DEFAULT 0,
            subtotal DECIMAL(10,2) DEFAULT 0,
            tax DECIMAL(10,2) DEFAULT 0,
            discount DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) DEFAULT 0,
            total_amount DECIMAL(10,2) DEFAULT 0,
            paid_amount DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(30) DEFAULT 'draft',
            payment_method VARCHAR(30),
            paid_at DATETIME,
            due_date DATE,
            encrypted_notes TEXT,
            created_by INT UNSIGNED,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            deleted_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED,
            tenant_id INT UNSIGNED,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(100),
            entity_id INT UNSIGNED,
            old_values JSON,
            new_values JSON,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS user_sessions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            tenant_id INT UNSIGNED,
            session_id VARCHAR(255) NOT NULL UNIQUE,
            ip_address VARCHAR(45),
            user_agent TEXT,
            is_active TINYINT(1) DEFAULT 1,
            last_active DATETIME,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

// ─────────────────────────────────────────────────────────────
echo "\n============================================\n";
echo "  TENANT RESET & SEED SCRIPT (STANDALONE)\n";
echo "============================================\n\n";

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

// STEP 2 - Drop existing tenant DBs (skip if none)
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

$provisioned = [];

foreach ($clinics as $c) {
    $dbName   = "clinic_tenant_{$c['code']}_db";
    $dbUser   = "clinic_{$c['code']}";
    $dbPass   = bin2hex(random_bytes(16));
    $username = $c['code'] . '_admin';
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

        // Run schema
        $pdo->exec("USE {$dbName}");
        foreach (getTenantSchema() as $sql) {
            $pdo->exec($sql);
        }

        // Seed roles
        $roles = [
            ['Admin',        'Full system access',    1],
            ['Provider',     'Doctor / Physician',    1],
            ['Nurse',        'Nursing staff access',  1],
            ['Receptionist', 'Front desk staff',      1],
            ['Pharmacist',   'Pharmacy access',       1],
            ['Patient',      'Patient portal access', 1],
        ];
        foreach ($roles as [$rName, $rDesc, $rSys]) {
            $pdo->exec("INSERT INTO roles (name, description, is_system_role, created_at) VALUES ('{$rName}', '{$rDesc}', {$rSys}, NOW())");
        }

        // Seed permissions
        $perms = [
            ['patients.view','View patients'],['patients.create','Create patients'],
            ['patients.edit','Edit patients'],['patients.delete','Delete patients'],
            ['appointments.view','View appointments'],['appointments.create','Book appointments'],
            ['appointments.manage','Manage appointments'],['prescriptions.view','View prescriptions'],
            ['prescriptions.create','Create prescriptions'],['prescriptions.dispense','Dispense prescriptions'],
            ['billing.view','View billing'],['billing.create','Create invoices'],
            ['billing.manage','Manage payments'],['staff.view','View staff'],
            ['staff.manage','Manage staff'],['reports.view','View reports'],
            ['settings.manage','System settings'],['audit.view','View audit logs'],
        ];
        foreach ($perms as [$pKey, $pDesc]) {
            $pdo->exec("INSERT INTO permissions (permission_key, description, created_at) VALUES ('{$pKey}', '{$pDesc}', NOW())");
        }

        // Admin gets all permissions
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT 1, id, NOW() FROM permissions");

        // Provider permissions
        $provPerms = "'patients.view','patients.create','patients.edit','appointments.view','appointments.create','appointments.manage','prescriptions.view','prescriptions.create','billing.view','billing.create','reports.view'";
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT 2, id, NOW() FROM permissions WHERE permission_key IN ({$provPerms})");

        // Create admin user
        $hashedPass     = password_hash($c['pass'], PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3]);
        $encEmail       = aes_encrypt($c['email'], $ENCRYPTION_KEY);
        $emailHash      = sha_hash($c['email']);
        $encName        = aes_encrypt('Admin - ' . $c['name'], $ENCRYPTION_KEY);

        $stmt = $pdo->prepare("INSERT INTO users (role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, status, created_at, updated_at) VALUES (1, :username, :enc_email, :email_hash, :password, :enc_name, 'active', NOW(), NOW())");
        $stmt->execute([
            'username'   => $username,
            'enc_email'  => $encEmail,
            'email_hash' => $emailHash,
            'password'   => $hashedPass,
            'enc_name'   => $encName,
        ]);

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
            'username' => $username,
            'pass'     => $c['pass'],
        ];

        echo "  [OK] {$c['code']} -> DB: {$dbName}\n";

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

            $appt = $pdo->prepare("INSERT INTO appointments (tenant_id, patient_id, doctor_id, appointment_time, encrypted_reason, status, created_at) VALUES (:tid, :pid, 1, DATE_ADD(NOW(), INTERVAL 1 DAY), :enc_reason, 'scheduled', NOW())");
            $appt->execute([
                'tid'        => $t['tenantId'],
                'pid'        => $pid,
                'enc_reason' => aes_encrypt($reasons[$i], $ENCRYPTION_KEY),
            ]);
        }
        echo "  [OK] 3 patients + 3 appointments seeded.\n";
    } catch (Exception $e) {
        echo "  [FAILED] {$t['code']}: " . $e->getMessage() . "\n";
    }
}

// SUMMARY
echo "\n============================================\n";
echo "  ALL DONE!\n";
echo "============================================\n\n";
echo "Login Credentials:\n";
echo "--------------------------------------------\n";
foreach ($provisioned as $t) {
    echo "  Tenant   : {$t['code']}\n";
    echo "  Username : {$t['username']}\n";
    echo "  Password : {$t['pass']}\n";
    echo "  Database : {$t['dbName']}\n";
    echo "--------------------------------------------\n";
}
echo "\nFrontend .env: REACT_APP_TENANT_CODE=apollo\n\n";