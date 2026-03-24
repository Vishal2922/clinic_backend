<?php
/**
 * seed_all_staff.php — Seed ALL role-based users into every tenant DB
 *
 * Creates one user per role (Provider, Nurse, Receptionist, Pharmacist)
 * in every provisioned tenant. Admin already exists from reset_and_seed_tenants.php.
 *
 * Also seeds role_permissions for Nurse, Receptionist, Pharmacist roles.
 * Also creates staff records for each non-Admin user.
 *
 * Run:  php seed_all_staff.php
 */

// ════════════════════════════════════════════════
// CONFIG — must match your .env / WAMP settings
// ════════════════════════════════════════════════
$DB_HOST = '127.0.0.1';
$DB_PORT = '3308';
$DB_USER = 'root';
$DB_PASS = '';
$ENCRYPTION_KEY = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6';

// ════════════════════════════════════════════════
// INLINE HELPERS (same as reset script)
// ════════════════════════════════════════════════
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

// ════════════════════════════════════════════════
// ROLE → ID mapping (matches roles table seeded order)
// ════════════════════════════════════════════════
// 1 = Admin, 2 = Provider, 3 = Nurse, 4 = Receptionist, 5 = Pharmacist, 6 = Patient
$ROLE_IDS = [
    'Admin'        => 1,
    'Provider'     => 2,
    'Nurse'        => 3,
    'Receptionist' => 4,
    'Pharmacist'   => 5,
    'Patient'      => 6,
];

// ════════════════════════════════════════════════
// STAFF USERS TO SEED (per tenant)
// ════════════════════════════════════════════════
// Format: role_name => [display_name_suffix, email_prefix, password]
// Username will be: {tenant_code}_{email_prefix}  (e.g. apollo_doctor)
$STAFF_TEMPLATES = [
    'Provider' => [
        'name_suffix' => 'Dr. Sharma',
        'username'    => 'doctor',
        'email'       => 'doctor',
        'password'    => 'Doctor@1234',
        'department'  => 'General Medicine',
        'specialization' => 'Internal Medicine',
        'license'     => 'MCI-2024-00123',
    ],
    'Nurse' => [
        'name_suffix' => 'Nurse Priya',
        'username'    => 'nurse',
        'email'       => 'nurse',
        'password'    => 'Nurse@1234',
        'department'  => 'Nursing',
        'specialization' => 'Critical Care',
        'license'     => 'NMC-2024-04567',
    ],
    'Receptionist' => [
        'name_suffix' => 'Anita (Reception)',
        'username'    => 'reception',
        'email'       => 'reception',
        'password'    => 'Reception@1234',
        'department'  => 'Front Desk',
        'specialization' => 'Patient Coordination',
        'license'     => '',
    ],
    'Pharmacist' => [
        'name_suffix' => 'Pharmacist Raj',
        'username'    => 'pharmacist',
        'email'       => 'pharmacist',
        'password'    => 'Pharma@1234',
        'department'  => 'Pharmacy',
        'specialization' => 'Clinical Pharmacy',
        'license'     => 'PCI-2024-07890',
    ],
];

// ════════════════════════════════════════════════
// ROLE → PERMISSIONS mapping
// ════════════════════════════════════════════════
$ROLE_PERMISSIONS = [
    // Provider (role_id=2) — already seeded in reset script, but re-check
    'Provider' => [
        'patients.view', 'patients.create', 'patients.edit',
        'appointments.view', 'appointments.create', 'appointments.manage',
        'prescriptions.view', 'prescriptions.create',
        'billing.view', 'billing.create',
        'reports.view',
    ],
    // Nurse (role_id=3)
    'Nurse' => [
        'patients.view', 'patients.create', 'patients.edit',
        'appointments.view', 'appointments.create', 'appointments.manage',
    ],
    // Receptionist (role_id=4)
    'Receptionist' => [
        'appointments.view', 'appointments.create',
    ],
    // Pharmacist (role_id=5)
    'Pharmacist' => [
        'prescriptions.view', 'prescriptions.dispense',
    ],
];

// ════════════════════════════════════════════════
// ARGON2ID OPTIONS (must match AuthService)
// ════════════════════════════════════════════════
$ARGON_OPTIONS = [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 3,
];

// ════════════════════════════════════════════════
// START
// ════════════════════════════════════════════════
echo "\n============================================\n";
echo "  SEED ALL STAFF USERS\n";
echo "============================================\n\n";

// Connect to MySQL
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "[OK] MySQL connected.\n\n";
} catch (PDOException $e) {
    echo "[FAILED] " . $e->getMessage() . "\n";
    exit(1);
}

// Get all tenants from master DB
$pdo->exec("USE clinic_master_db");
$tenants = $pdo->query("SELECT id, tenant_code, db_name FROM tenants WHERE status = 'active'")
               ->fetchAll(PDO::FETCH_ASSOC);

if (empty($tenants)) {
    echo "[ERROR] No active tenants found. Run reset_and_seed_tenants.php first.\n";
    exit(1);
}

echo "Found " . count($tenants) . " active tenant(s).\n\n";

// ── Collect all credentials for final output
$allCredentials = [];

foreach ($tenants as $tenant) {
    $code   = $tenant['tenant_code'];
    $dbName = $tenant['db_name'];
    $tid    = (int) $tenant['id'];

    echo "--- Tenant: {$code} ({$dbName}) ---\n";

    try {
        $pdo->exec("USE {$dbName}");
    } catch (PDOException $e) {
        echo "  [SKIP] Cannot use DB: " . $e->getMessage() . "\n\n";
        continue;
    }

    // ── Step 1: Seed role_permissions for Nurse, Receptionist, Pharmacist ──
    foreach ($ROLE_PERMISSIONS as $roleName => $permKeys) {
        $roleId = $ROLE_IDS[$roleName];

        // Check if permissions already seeded for this role
        $existingPerms = $pdo->query("SELECT COUNT(*) AS cnt FROM role_permissions WHERE role_id = {$roleId}")
                             ->fetch(PDO::FETCH_ASSOC);

        if ((int)$existingPerms['cnt'] > 0) {
            echo "  [SKIP] {$roleName} permissions already seeded ({$existingPerms['cnt']} perms)\n";
            continue;
        }

        $inList = implode(',', array_map(fn($k) => "'{$k}'", $permKeys));
        $sql = "INSERT INTO role_permissions (role_id, permission_id, created_at)
                SELECT {$roleId}, id, NOW() FROM permissions WHERE permission_key IN ({$inList})";
        $pdo->exec($sql);
        echo "  [OK] {$roleName} permissions seeded (" . count($permKeys) . " perms)\n";
    }

    // ── Step 2: Create users for each role ──────────────────────────────────
    foreach ($STAFF_TEMPLATES as $roleName => $tpl) {
        $roleId   = $ROLE_IDS[$roleName];
        $username = "{$code}_{$tpl['username']}";
        $email    = "{$tpl['email']}@{$code}.com";
        $fullName = "{$tpl['name_suffix']} - " . ucfirst($code);
        $password = $tpl['password'];

        // Check if user already exists
        $existingUser = $pdo->prepare("SELECT id FROM users WHERE username = :u");
        $existingUser->execute(['u' => $username]);
        $existing = $existingUser->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo "  [SKIP] {$username} already exists (ID: {$existing['id']})\n";
            $allCredentials[] = [
                'tenant'   => $code,
                'role'     => $roleName,
                'username' => $username,
                'password' => $password,
                'status'   => 'exists',
            ];
            continue;
        }

        // Hash password
        $passHash = password_hash($password, PASSWORD_ARGON2ID, $ARGON_OPTIONS);

        // Encrypt sensitive fields
        $encEmail = aes_encrypt($email, $ENCRYPTION_KEY);
        $emailHash = sha_hash($email);
        $encName  = aes_encrypt($fullName, $ENCRYPTION_KEY);

        // Insert user
        $stmt = $pdo->prepare("
            INSERT INTO users (role_id, username, encrypted_email, email_hash,
                               password_hash, encrypted_full_name, status,
                               created_at, updated_at)
            VALUES (:role_id, :username, :enc_email, :email_hash,
                    :pass_hash, :enc_name, 'active', NOW(), NOW())
        ");
        $stmt->execute([
            'role_id'    => $roleId,
            'username'   => $username,
            'enc_email'  => $encEmail,
            'email_hash' => $emailHash,
            'pass_hash'  => $passHash,
            'enc_name'   => $encName,
        ]);
        $userId = (int) $pdo->lastInsertId();

        echo "  [OK] Created {$roleName}: {$username} (ID: {$userId})\n";

        // ── Step 3: Create staff record ──────────────────────────────────
        $encDept   = !empty($tpl['department'])     ? aes_encrypt($tpl['department'], $ENCRYPTION_KEY)     : null;
        $encSpec   = !empty($tpl['specialization']) ? aes_encrypt($tpl['specialization'], $ENCRYPTION_KEY) : null;
        $encLic    = !empty($tpl['license'])        ? aes_encrypt($tpl['license'], $ENCRYPTION_KEY)        : null;

        $staffStmt = $pdo->prepare("
            INSERT INTO staff (user_id, tenant_id, encrypted_department,
                               encrypted_specialization, encrypted_license_number,
                               hire_date, status, created_at, updated_at)
            VALUES (:uid, :tid, :dept, :spec, :lic, CURDATE(), 'active', NOW(), NOW())
        ");
        $staffStmt->execute([
            'uid'  => $userId,
            'tid'  => $tid,
            'dept' => $encDept,
            'spec' => $encSpec,
            'lic'  => $encLic,
        ]);
        echo "  [OK] Staff record created for {$username}\n";

        $allCredentials[] = [
            'tenant'   => $code,
            'role'     => $roleName,
            'username' => $username,
            'password' => $password,
            'status'   => 'created',
        ];
    }

    echo "\n";
}

// ════════════════════════════════════════════════
// FINAL SUMMARY
// ════════════════════════════════════════════════
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                     ALL STAFF LOGIN CREDENTIALS                        ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════╣\n";
echo "║                                                                        ║\n";
echo "║  Frontend URL:  http://{tenant}.localhost:3000/login                    ║\n";
echo "║  Example:       http://apollo.localhost:3000/login                      ║\n";
echo "║                                                                        ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════╣\n";

// Group by tenant
$byTenant = [];
foreach ($allCredentials as $c) {
    $byTenant[$c['tenant']][] = $c;
}

// Also include Admin credentials
$adminPasswords = [
    'apollo' => 'Apollo@1234',
    'fortis' => 'Fortis@1234',
    'max'    => 'Max@1234',
    'care'   => 'Care@1234',
    'kims'   => 'Kims@1234',
];

foreach ($byTenant as $tenant => $users) {
    echo "║                                                                        ║\n";
    echo "║  ┌─────────────────────────────────────────────────────────────────┐    ║\n";
    $tenantLabel = strtoupper($tenant);
    echo "║  │  TENANT: " . str_pad($tenantLabel, 53) . "│    ║\n";
    echo "║  │  URL: http://{$tenant}.localhost:3000/login" . str_pad('', 53 - strlen("URL: http://{$tenant}.localhost:3000/login")) . "│    ║\n";
    echo "║  ├──────────────┬───────────────────────┬──────────────────────────┤    ║\n";
    echo "║  │  Role        │  Username             │  Password               │    ║\n";
    echo "║  ├──────────────┼───────────────────────┼──────────────────────────┤    ║\n";

    // Admin first
    $adminUser = "{$tenant}_admin";
    $adminPass = $adminPasswords[$tenant] ?? 'Admin@1234';
    echo "║  │  " . str_pad('Admin', 12) . "│  " . str_pad($adminUser, 21) . "│  " . str_pad($adminPass, 24) . "│    ║\n";

    // Other roles
    foreach ($users as $u) {
        echo "║  │  " . str_pad($u['role'], 12) . "│  " . str_pad($u['username'], 21) . "│  " . str_pad($u['password'], 24) . "│    ║\n";
    }

    echo "║  └──────────────┴───────────────────────┴──────────────────────────┘    ║\n";
}

echo "║                                                                        ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════╣\n";
echo "║                                                                        ║\n";
echo "║  RBAC ACCESS MATRIX (Per Architecture)                                 ║\n";
echo "║  ─────────────────────────────────────                                 ║\n";
echo "║  Admin        → Dashboard, Prescriptions, Billing, Staff, Settings     ║\n";
echo "║  Provider     → Dashboard, Patients, Appointments, Prescriptions       ║\n";
echo "║  Nurse        → Patients, Appointments                                 ║\n";
echo "║  Receptionist → Calendar                                               ║\n";
echo "║  Pharmacist   → Dashboard, Prescriptions (dispense only)               ║\n";
echo "║                                                                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

echo "Done! " . count($allCredentials) . " staff user(s) processed across " . count($byTenant) . " tenant(s).\n\n";
