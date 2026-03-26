<?php
/**
 * setup.php — CENTRALIZED PROJECT SEED SCRIPT
 * =====================================================
 * This script consolidates: reset_and_seed_tenants.php, 
 * seed_all_staff.php, seedsuper.php, and seedsuperreset.php
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
$DB_PORT = '3308'; // Make sure this matches your MySQL port
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
$existing = $pdo->query("SELECT tenant_code, db_name, db_username FROM tenants")->fetchAll(PDO::FETCH_ASSOC);
if (empty($existing)) {
    echo "  No existing tenants - fresh install.\n";
} else {
    foreach ($existing as $t) {
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

// Tenants Definition
$clinics = [
    ['code' => 'apollo', 'name' => 'Apollo Hospitals',     'email' => 'admin@apollo.com', 'plan' => 'enterprise',   'pass' => 'Apollo@1234'],
    ['code' => 'fortis', 'name' => 'Fortis Healthcare',    'email' => 'admin@fortis.com', 'plan' => 'standard',     'pass' => 'Fortis@1234'],
    ['code' => 'max',    'name' => 'Max Super Speciality', 'email' => 'admin@max.com',    'plan' => 'professional', 'pass' => 'Max@1234'],
    ['code' => 'care',   'name' => 'Care Hospitals',       'email' => 'admin@care.com',   'plan' => 'basic',        'pass' => 'Care@1234'],
    ['code' => 'kims',   'name' => 'KIMS Hospitals',       'email' => 'admin@kims.com',   'plan' => 'basic',        'pass' => 'Kims@1234'],
];
$planDays  = ['basic' => 30, 'standard' => 90, 'professional' => 180, 'enterprise' => 365];
$planUsers = ['basic' => 10, 'standard' => 25, 'professional' => 50,  'enterprise' => 200];

// Staff Template definition (combining standard admin + extra details for others)
$STAFF_TEMPLATES = [
    'Admin' => [
        'role_id'     => 1,
        'name_suffix' => 'Admin',
        'username'    => 'admin',
        'email'       => 'admin',
        'department'  => 'Administration',
        'specialization' => '',
        'license'     => '',
    ],
    'Provider' => [
        'role_id'     => 2,
        'name_suffix' => 'Dr. Sharma',
        'username'    => 'doctor',
        'email'       => 'doctor',
        'department'  => 'General Medicine',
        'specialization' => 'Internal Medicine',
        'license'     => 'MCI-2024-00123',
    ],
    'Nurse' => [
        'role_id'     => 3,
        'name_suffix' => 'Nurse Priya',
        'username'    => 'nurse',
        'email'       => 'nurse',
        'department'  => 'Nursing',
        'specialization' => 'Critical Care',
        'license'     => 'NMC-2024-04567',
    ],
    'Receptionist' => [
        'role_id'     => 4,
        'name_suffix' => 'Anita (Reception)',
        'username'    => 'reception',
        'email'       => 'reception',
        'department'  => 'Front Desk',
        'specialization' => 'Patient Coordination',
        'license'     => '',
    ],
    'Pharmacist' => [
        'role_id'     => 5,
        'name_suffix' => 'Pharmacist Raj',
        'username'    => 'pharmacist',
        'email'       => 'pharmacist',
        'department'  => 'Pharmacy',
        'specialization' => 'Clinical Pharmacy',
        'license'     => 'PCI-2024-07890',
    ],
];

// Seed Role Permissions definitions
$ROLE_PERMISSIONS = [
    'Provider'     => ['patients.view', 'patients.create', 'patients.edit', 'appointments.view', 'appointments.create', 'appointments.manage', 'prescriptions.view', 'prescriptions.create', 'billing.view', 'billing.create', 'reports.view'],
    'Nurse'        => ['patients.view', 'patients.create', 'patients.edit', 'appointments.view', 'appointments.create', 'appointments.manage'],
    'Receptionist' => ['appointments.view', 'appointments.create'],
    'Pharmacist'   => ['prescriptions.view', 'prescriptions.dispense'],
];

$provisioned = [];

echo "--- Provisioning Tenants & Staff ---\n";
foreach ($clinics as $c) {
    $dbName   = "clinic_tenant_{$c['code']}_db";
    $dbUser   = "clinic_{$c['code']}";
    $dbPass   = bin2hex(random_bytes(16));
    $expires  = date('Y-m-d H:i:s', strtotime('+' . ($planDays[$c['plan']] ?? 30) . ' days'));

    echo "  Provisioning {$c['code']}...\n";

    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("DROP USER IF EXISTS '{$dbUser}'@'%'");
        $pdo->exec("CREATE USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON {$dbName}.* TO '{$dbUser}'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");
        
        $pdo->exec("USE {$dbName}");
        foreach ($schemaStmts as $sql) $pdo->exec($sql);
        foreach ($seedStmts as $sql) $pdo->exec($sql);

        // Seed Role Permissions
        foreach ($ROLE_PERMISSIONS as $roleName => $permKeys) {
            $roleId = $STAFF_TEMPLATES[$roleName]['role_id'];
            $existingPerms = $pdo->query("SELECT COUNT(*) AS cnt FROM role_permissions WHERE role_id = {$roleId}")->fetch(PDO::FETCH_ASSOC);
            if ((int)$existingPerms['cnt'] == 0) {
                $inList = implode(',', array_map(fn($k) => "'{$k}'", $permKeys));
                $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT {$roleId}, id, NOW() FROM permissions WHERE permission_key IN ({$inList})");
            }
        }

        // Register tenant in master DB first to get tenantId
        $pdo->exec("USE {$MASTER_DB}");
        $maxUsers = $planUsers[$c['plan']] ?? 10;
        $stmt2 = $pdo->prepare("INSERT INTO tenants (tenant_code, name, slug, email, plan, status, subscription_starts_at, subscription_expires_at, db_host, db_port, db_name, db_username, db_password, max_users, max_patients, max_doctors, created_by_super_admin_id, created_at, updated_at) VALUES (:code, :name, :slug, :email, :plan, 'active', NOW(), :expires, :host, :port, :dbname, :dbuser, :dbpass, :maxu, 500, 10, 1, NOW(), NOW())");
        $stmt2->execute(['code'=>$c['code'], 'name'=>$c['name'], 'slug'=>$c['code'], 'email'=>$c['email'], 'plan'=>$c['plan'], 'expires'=>$expires, 'host'=>$DB_HOST, 'port'=>$DB_PORT, 'dbname'=>$dbName, 'dbuser'=>$dbUser, 'dbpass'=>$dbPass, 'maxu'=>$maxUsers]);
        $tenantId = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO master_audit_logs (super_admin_id, action, resource_type, resource_id, details, ip_address, created_at) VALUES (1, 'tenant_created', 'tenant', {$tenantId}, '{\"tenant_code\":\"{$c['code']}\"}', '127.0.0.1', NOW())");

        // Seed Users
        $pdo->exec("USE {$dbName}");
        $createdUsers = [];
        foreach ($STAFF_TEMPLATES as $roleName => $tpl) {
            $username  = "{$c['code']}_{$tpl['username']}";
            $password  = $c['pass'];
            $email     = "{$tpl['email']}@{$c['code']}.com";
            $fullName  = "{$tpl['name_suffix']} - " . ucfirst($c['code']);
            
            $hashedPass = password_hash($password, PASSWORD_ARGON2ID, $ARGON_OPTIONS);
            $encEmail   = aes_encrypt($email, $ENCRYPTION_KEY);
            $emailHash  = sha_hash($email);
            $encName    = aes_encrypt($fullName, $ENCRYPTION_KEY);

            $stmt = $pdo->prepare("INSERT INTO users (role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, status, created_at, updated_at) VALUES (:role_id, :username, :enc_email, :email_hash, :password, :enc_name, 'active', NOW(), NOW())");
            $stmt->execute(['role_id'=>$tpl['role_id'], 'username'=>$username, 'enc_email'=>$encEmail, 'email_hash'=>$emailHash, 'password'=>$hashedPass, 'enc_name'=>$encName]);
            $userId = $pdo->lastInsertId();

            $encDept   = !empty($tpl['department'])     ? aes_encrypt($tpl['department'], $ENCRYPTION_KEY)     : null;
            $encSpec   = !empty($tpl['specialization']) ? aes_encrypt($tpl['specialization'], $ENCRYPTION_KEY) : null;
            $encLic    = !empty($tpl['license'])        ? aes_encrypt($tpl['license'], $ENCRYPTION_KEY)        : null;

            $staffStmt = $pdo->prepare("INSERT INTO staff (user_id, tenant_id, encrypted_department, encrypted_specialization, encrypted_license_number, hire_date, status, created_at, updated_at) VALUES (:uid, :tid, :dept, :spec, :lic, CURDATE(), 'active', NOW(), NOW())");
            $staffStmt->execute(['uid'=>$userId, 'tid'=>$tenantId, 'dept'=>$encDept, 'spec'=>$encSpec, 'lic'=>$encLic]);

            $createdUsers[] = ['username' => $username, 'role' => $roleName, 'password' => $password];
        }

        $provisioned[] = ['code'=>$c['code'], 'name'=>$c['name'], 'dbName'=>$dbName, 'tenantId'=>$tenantId, 'pass'=>$c['pass'], 'users'=>$createdUsers];
        echo "    [OK] DB {$dbName} prepared (Schema, roles, " . count($createdUsers) . " staff users)\n";

    } catch (Exception $e) {
        echo "  [FAILED] {$c['code']}: " . $e->getMessage() . "\n";
    }
}

// STEP 3 - Comprehensive Sample Data
echo "\n--- Seeding Comprehensive Sample Data ---\n";

$patientData = [
    ['name'=>'Rajesh Kumar',       'phone'=>'+919876543210','email'=>'rajesh.kumar@email.com',    'gender'=>'Male',  'dob'=>'1985-03-15','blood'=>'A+', 'history'=>'Type 2 Diabetes. On Metformin 500mg BD. HbA1c: 7.2%.',                     'address'=>'12, MG Road, Chennai 600028',                'emergency'=>'Meena Kumar: +919876543200'],
    ['name'=>'Priya Sharma',       'phone'=>'+919876543211','email'=>'priya.sharma@email.com',    'gender'=>'Female','dob'=>'1990-07-22','blood'=>'B+', 'history'=>'Bronchial Asthma since childhood. Uses Salbutamol inhaler PRN.',           'address'=>'45, Anna Nagar, Chennai 600040',             'emergency'=>'Vikram Sharma: +919876543201'],
    ['name'=>'Arun Patel',         'phone'=>'+919876543212','email'=>'arun.patel@email.com',      'gender'=>'Male',  'dob'=>'1978-11-08','blood'=>'O+', 'history'=>'Hypertension Stage 2. On Amlodipine 5mg + Losartan 50mg.',                'address'=>'78, T Nagar, Chennai 600017',                'emergency'=>'Sunita Patel: +919876543202'],
    ['name'=>'Deepika Rajan',      'phone'=>'+919876543213','email'=>'deepika.rajan@email.com',   'gender'=>'Female','dob'=>'1995-01-30','blood'=>'AB+','history'=>'Migraine with aura. On Sumatriptan 50mg PRN. Frequency: 2-3/month.',       'address'=>'23, Adyar, Chennai 600020',                  'emergency'=>'Rajan M: +919876543203'],
    ['name'=>'Suresh Iyer',        'phone'=>'+919876543214','email'=>'suresh.iyer@email.com',     'gender'=>'Male',  'dob'=>'1965-05-12','blood'=>'A-', 'history'=>'COPD Gold Stage II. On Tiotropium + Budesonide/Formoterol.',               'address'=>'56, Mylapore, Chennai 600004',               'emergency'=>'Lakshmi Iyer: +919876543204'],
    ['name'=>'Anita Deshmukh',     'phone'=>'+919876543215','email'=>'anita.deshmukh@email.com',  'gender'=>'Female','dob'=>'1988-09-18','blood'=>'B-', 'history'=>'Hypothyroidism. On Levothyroxine 75mcg OD. TSH last: 3.8.',               'address'=>'89, Velachery, Chennai 600042',              'emergency'=>'Rakesh Deshmukh: +919876543205'],
    ['name'=>'Vikram Singh',       'phone'=>'+919876543216','email'=>'vikram.singh@email.com',    'gender'=>'Male',  'dob'=>'1972-12-25','blood'=>'O-', 'history'=>'Chronic Kidney Disease Stage 3. eGFR: 42. On Enalapril.',                 'address'=>'34, Porur, Chennai 600116',                  'emergency'=>'Kavita Singh: +919876543206'],
    ['name'=>'Kavitha Nair',       'phone'=>'+919876543217','email'=>'kavitha.nair@email.com',    'gender'=>'Female','dob'=>'1992-04-05','blood'=>'A+', 'history'=>'Iron Deficiency Anemia. Hb: 9.2. On Ferrous Sulphate 200mg BD.',          'address'=>'67, Guindy, Chennai 600032',                 'emergency'=>'Mohan Nair: +919876543207'],
    ['name'=>'Mohammed Farhan',    'phone'=>'+919876543218','email'=>'farhan.m@email.com',        'gender'=>'Male',  'dob'=>'1980-08-14','blood'=>'B+', 'history'=>'Gastroesophageal Reflux Disease. On Pantoprazole 40mg OD.',                'address'=>'12, Triplicane, Chennai 600005',             'emergency'=>'Ayesha Farhan: +919876543208'],
    ['name'=>'Lakshmi Venkatesh',  'phone'=>'+919876543219','email'=>'lakshmi.v@email.com',       'gender'=>'Female','dob'=>'1960-02-28','blood'=>'AB-','history'=>'Osteoarthritis bilateral knees. On Celecoxib 200mg OD + Physiotherapy.',   'address'=>'90, Tambaram, Chennai 600045',               'emergency'=>'Venkatesh R: +919876543209'],
    ['name'=>'Karthik Subramani',  'phone'=>'+919876543220','email'=>'karthik.s@email.com',       'gender'=>'Male',  'dob'=>'1998-06-10','blood'=>'O+', 'history'=>'Allergic Rhinitis. On Cetirizine 10mg OD + Fluticasone nasal spray.',      'address'=>'15, Chromepet, Chennai 600044',              'emergency'=>'Subramani K: +919876543210'],
    ['name'=>'Revathi Krishnan',   'phone'=>'+919876543221','email'=>'revathi.k@email.com',       'gender'=>'Female','dob'=>'1983-10-20','blood'=>'A+', 'history'=>'Polycystic Ovary Syndrome. On OCP. BMI: 28.5.',                           'address'=>'28, Nungambakkam, Chennai 600034',           'emergency'=>'Krishnan P: +919876543211'],
    ['name'=>'Ganesh Prasad',      'phone'=>'+919876543222','email'=>'ganesh.p@email.com',        'gender'=>'Male',  'dob'=>'1975-07-04','blood'=>'B-', 'history'=>'Type 1 Diabetes since age 12. On Insulin Glargine + Lispro. Pump user.',  'address'=>'41, Kodambakkam, Chennai 600024',            'emergency'=>'Sita Prasad: +919876543212'],
    ['name'=>'Sneha Reddy',        'phone'=>'+919876543223','email'=>'sneha.r@email.com',         'gender'=>'Female','dob'=>'2000-03-08','blood'=>'O+', 'history'=>'Generalized Anxiety Disorder. On Escitalopram 10mg OD.',                  'address'=>'53, Kilpauk, Chennai 600010',                'emergency'=>'Reddy V: +919876543213'],
    ['name'=>'Harish Babu',        'phone'=>'+919876543224','email'=>'harish.b@email.com',        'gender'=>'Male',  'dob'=>'1970-11-15','blood'=>'AB+','history'=>'Coronary Artery Disease. Post-CABG (2022). On Aspirin + Atorvastatin.',    'address'=>'76, Egmore, Chennai 600008',                 'emergency'=>'Padma Babu: +919876543214'],
];

$appointmentReasons = ['Routine monthly diabetes checkup', 'Persistent dry cough', 'Blood pressure monitoring', 'Follow-up after surgery', 'Annual health screening', 'Chest tightness', 'Skin rash itching', 'Chronic lower back pain', 'Fever and sore throat', 'Joint pain and morning stiffness', 'Routine eye examination', 'Anxiety symptoms', 'Vaccination consultation', 'Pre-operative assessment', 'Headache and dizziness'];
$appointmentStatuses = ['scheduled','scheduled','completed','completed','cancelled','no_show','completed','completed','scheduled','completed','cancelled','scheduled','completed','scheduled','completed'];

$medicines = [
    ['name'=>'Metformin 500mg',         'dosage'=>'1 tablet twice daily after meals',       'days'=>90, 'notes'=>'Monitor blood glucose.'],
    ['name'=>'Amlodipine 5mg',          'dosage'=>'1 tablet once daily in the morning',     'days'=>30, 'notes'=>'Monitor BP weekly.'],
    ['name'=>'Atorvastatin 20mg',       'dosage'=>'1 tablet at bedtime',                    'days'=>30, 'notes'=>'Lipid panel after 3 months.'],
    ['name'=>'Pantoprazole 40mg',       'dosage'=>'1 tablet before breakfast',               'days'=>14, 'notes'=>'Take 30 min before meal.'],
    ['name'=>'Cetirizine 10mg',         'dosage'=>'1 tablet at bedtime',                    'days'=>10, 'notes'=>'May cause drowsiness.'],
];
$prescriptionStatuses = ['pending','pending','dispensed','dispensed','dispensed','pending','dispensed','dispensed','pending','dispensed'];

$invoiceAmounts = [500,1200,800,3500,1500,2000,750,4500,600,2500,1800,950,3200,700,5000];
$invoiceStatuses = ['paid','pending','paid','paid','overdue','paid','pending','paid','cancelled','paid','paid','pending','paid','overdue','paid'];
$paymentMethods = ['cash','card','upi','card','','cash','','upi','','card','cash','','upi','','cash'];

$notifTemplates = [
    ['type'=>'system',      'title'=>'Welcome to ClinicOS!',                   'msg'=>'Your clinic has been provisioned successfully.'],
    ['type'=>'appointment', 'title'=>'New Appointment Booked',                 'msg'=>'A new appointment has been scheduled for tomorrow.'],
    ['type'=>'appointment', 'title'=>'Appointment Reminder',                   'msg'=>'Reminder: You have 3 appointments scheduled for today.'],
    ['type'=>'billing',     'title'=>'Invoice Overdue',                        'msg'=>'Invoice INV-001 is overdue by 7 days.'],
    ['type'=>'prescription','title'=>'Prescription Ready for Dispensing',       'msg'=>'A new prescription for Metformin 500mg is ready.'],
];

$noteTemplates = [
    ['type'=>'note',      'msg'=>'Patient vitals: BP 130/85, Temp 98.4F, SpO2 98%, HR 72 bpm. Generally stable.'],
    ['type'=>'diagnosis', 'msg'=>'Diagnosis: Acute Pharyngitis. Throat congested, mild tonsillar enlargement bilaterally.'],
    ['type'=>'follow_up', 'msg'=>'Follow-up in 2 weeks. Repeat blood work for HbA1c and fasting glucose before next visit.'],
    ['type'=>'note',      'msg'=>'Patient reports improvement in symptoms.'],
    ['type'=>'diagnosis', 'msg'=>'Diagnosis: Allergic contact dermatitis. Erythematous papular rash on forearms.'],
];

foreach ($provisioned as $t) {
    try {
        $pdo->exec("USE {$t['dbName']}");
        $doctorId = 2; 
        $pharmacistId = 5; 
        $patientIds = [];

        // Seed 15 patients
        foreach ($patientData as $p) {
            $stmt = $pdo->prepare("INSERT INTO patients (tenant_id, encrypted_name, name_hash, encrypted_phone, phone_hash, encrypted_email, email_hash, encrypted_medical_history, encrypted_date_of_birth, encrypted_gender, encrypted_blood_group, encrypted_address, encrypted_emergency_contact, status, created_at) VALUES (:tid, :enc_name, :hash_name, :enc_phone, :hash_phone, :enc_email, :hash_email, :enc_hist, :enc_dob, :enc_gender, :enc_blood, :enc_addr, :enc_emerg, 'active', NOW())");
            $stmt->execute([
                'tid'        => $t['tenantId'],
                'enc_name'   => aes_encrypt($p['name'],      $ENCRYPTION_KEY),
                'hash_name'  => sha_hash($p['name']),
                'enc_phone'  => aes_encrypt($p['phone'],     $ENCRYPTION_KEY),
                'hash_phone' => sha_hash($p['phone']),
                'enc_email'  => aes_encrypt($p['email'],     $ENCRYPTION_KEY),
                'hash_email' => sha_hash($p['email']),
                'enc_hist'   => aes_encrypt($p['history'],   $ENCRYPTION_KEY),
                'enc_dob'    => aes_encrypt($p['dob'],       $ENCRYPTION_KEY),
                'enc_gender' => aes_encrypt($p['gender'],    $ENCRYPTION_KEY),
                'enc_blood'  => aes_encrypt($p['blood'],     $ENCRYPTION_KEY),
                'enc_addr'   => aes_encrypt($p['address'],   $ENCRYPTION_KEY),
                'enc_emerg'  => aes_encrypt($p['emergency'], $ENCRYPTION_KEY),
            ]);
            $patientIds[] = (int) $pdo->lastInsertId();
        }

        // Seed Appointments
        $appointmentIds = [];
        for ($i = 0; $i < 15; $i++) {
            $pid = $patientIds[$i % count($patientIds)];
            $dayOffset = $i - 7;
            $hour = 9 + ($i % 8);
            $appt = $pdo->prepare("INSERT INTO appointments (tenant_id, patient_id, doctor_id, appointment_time, encrypted_reason, status, created_at) VALUES (:tid, :pid, :did, DATE_ADD(NOW(), INTERVAL :day DAY) + INTERVAL :hour HOUR, :enc_reason, :status, NOW())");
            $appt->execute([
                'tid'        => $t['tenantId'],
                'pid'        => $pid,
                'did'        => $doctorId,
                'day'        => $dayOffset,
                'hour'       => $hour,
                'enc_reason' => aes_encrypt($appointmentReasons[$i], $ENCRYPTION_KEY),
                'status'     => $appointmentStatuses[$i],
            ]);
            $appointmentIds[] = (int) $pdo->lastInsertId();
        }

        // Seed Prescriptions
        for ($i = 0; $i < 10; $i++) {
            $pid = $patientIds[$i % count($patientIds)];
            $apptId = $appointmentIds[$i % count($appointmentIds)];
            $med = $medicines[$i % count($medicines)];
            $rxStatus = $prescriptionStatuses[$i];
            $pharmId = ($rxStatus === 'dispensed') ? $pharmacistId : null;
            $rx = $pdo->prepare("INSERT INTO prescriptions (tenant_id, appointment_id, patient_id, provider_id, pharmacist_id, encrypted_medicine_name, encrypted_dosage, encrypted_notes, duration_days, status, created_at) VALUES (:tid, :appt_id, :pid, :prov_id, :pharm_id, :enc_med, :enc_dos, :enc_notes, :dur, :status, NOW())");
            $rx->execute([
                'tid'=>$t['tenantId'], 'appt_id'=>$apptId, 'pid'=>$pid, 'prov_id'=>$doctorId, 'pharm_id'=>$pharmId, 'enc_med'=>aes_encrypt($med['name'], $ENCRYPTION_KEY), 'enc_dos'=>aes_encrypt($med['dosage'], $ENCRYPTION_KEY), 'enc_notes'=>aes_encrypt($med['notes'], $ENCRYPTION_KEY), 'dur'=>$med['days'], 'status'=>$rxStatus,
            ]);
        }

        // Seed Invoices
        for ($i = 0; $i < 15; $i++) {
            $pid = $patientIds[$i % count($patientIds)];
            $apptId = $appointmentIds[$i % count($appointmentIds)];
            $amount = $invoiceAmounts[$i];
            $tax = round($amount * 0.18, 2);
            $total = round($amount + $tax, 2);
            $invStatus = $invoiceStatuses[$i];
            $paidAmt = ($invStatus === 'paid') ? $total : 0;
            $invNumber = strtoupper($t['code']) . '-INV-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            $payMethod = $paymentMethods[$i];
            
            $inv = $pdo->prepare("INSERT INTO invoices (tenant_id, invoice_number, patient_id, provider_id, appointment_id, amount, subtotal, tax, discount, total, total_amount, paid_amount, status, payment_method, paid_at, due_date, encrypted_notes, created_by, created_at) VALUES (:tid, :inv_num, :pid, :prov_id, :appt_id, :amount, :subtotal, :tax, 0, :total, :total_amount, :paid_amount, :status, :pay_method, " . ($invStatus === 'paid' ? "DATE_SUB(NOW(), INTERVAL 1 DAY)" : "NULL") . ", DATE_ADD(NOW(), INTERVAL 15 DAY), :enc_notes, 1, NOW())");
            $inv->execute([
                'tid'=>$t['tenantId'], 'inv_num'=>$invNumber, 'pid'=>$pid, 'prov_id'=>$doctorId, 'appt_id'=>$apptId, 'amount'=>$amount, 'subtotal'=>$amount, 'tax'=>$tax, 'total'=>$total, 'total_amount'=>$total, 'paid_amount'=>$paidAmt, 'status'=>$invStatus, 'pay_method'=>$payMethod?:null, 'enc_notes'=>aes_encrypt("Consultation charges", $ENCRYPTION_KEY)
            ]);
        }

        // Notifications
        foreach ($notifTemplates as $ni => $n) {
            $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, user_id, type, title, message, is_read, created_at) VALUES (:tid, 1, :type, :title, :msg, 0, NOW())");
            $notif->execute(['tid'=>$t['tenantId'], 'type'=>$n['type'], 'title'=>$n['title'], 'msg'=>$n['msg']]);
        }

        // Notes
        foreach ($noteTemplates as $ni => $note) {
            $an = $pdo->prepare("INSERT INTO appointment_notes (tenant_id, appointment_id, author_id, message_encrypted, note_type, visible_to_role, created_at) VALUES (:tid, :appt_id, :auth_id, :enc_msg, :type, 'all', NOW())");
            $an->execute(['tid'=>$t['tenantId'], 'appt_id'=>$appointmentIds[array_rand($appointmentIds)], 'auth_id'=>$doctorId, 'enc_msg'=>aes_encrypt($note['msg'], $ENCRYPTION_KEY), 'type'=>$note['type']]);
        }

        // Audit Logs
        $pdo->exec("INSERT INTO audit_log (user_id, tenant_id, action, entity_type, entity_id, ip_address, created_at) VALUES (1, {$t['tenantId']}, 'patient.created', 'patient', {$patientIds[0]}, '127.0.0.1', NOW())");

        echo "    [OK] {$t['code']} — ALL DATA SEEDED\n";

    } catch (Exception $e) {
        echo "  [FAILED] {$t['code']}: " . $e->getMessage() . "\n";
    }
}

// SUMMARY
echo "\n==========================================================================\n";
echo "  SUPER ADMIN LOGIN CREDENTIALS\n";
echo "==========================================================================\n";
echo "  URL:       http://localhost:3000/superadmin (or similar admin route)\n";
echo "  Email:     superadmin@clinic.io\n";
echo "  Password:  SuperAdmin@2026\n";

echo "\n==========================================================================\n";
echo "  TENANT STAFF LOGIN CREDENTIALS\n";
echo "==========================================================================\n";
echo "  Frontend .env: REACT_APP_TENANT_CODE=[TENANT_CODE]\n\n";

foreach ($provisioned as $t) {
    echo "  ┌─────────────────────────────────────────────────────────────────┐\n";
    $tenantLabel = strtoupper($t['code']);
    echo "  │  TENANT: " . str_pad($tenantLabel, 53) . "│\n";
    echo "  │  URL: http://{$t['code']}.localhost:3000/login" . str_pad('', 53 - strlen("URL: http://{$t['code']}.localhost:3000/login")) . "│\n";
    echo "  ├──────────────┬───────────────────────┬──────────────────────────┤\n";
    echo "  │  Role        │  Username             │  Password                │\n";
    echo "  ├──────────────┼───────────────────────┼──────────────────────────┤\n";
    foreach ($t['users'] as $u) {
        echo "  │  " . str_pad($u['role'], 12) . "│  " . str_pad($u['username'], 21) . "│  " . str_pad($u['password'], 24) . "│\n";
    }
    echo "  └──────────────┴───────────────────────┴──────────────────────────┘\n\n";
}

echo "Example: Login to apollo as Provider -> apollo_doctor / Apollo@1234\n";
echo "All done!\n\n";
