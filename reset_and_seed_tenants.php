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
$DB_PORT = '3308';
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
            $userId = $pdo->lastInsertId();

            $stmtStaff = $pdo->prepare("INSERT INTO staff (user_id, tenant_id, status, created_at, updated_at) VALUES (:uid, :tid, 'active', NOW(), NOW())");
            $stmtStaff->execute([
                'uid' => $userId,
                'tid' => $tenantId,
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

// STEP 4 - Seed COMPREHENSIVE sample data (30+ records per module)
echo "\n--- Seeding Comprehensive Sample Data ---\n";

// ── 35 PATIENTS with realistic Indian medical data ──
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
    ['name'=>'Meena Sundaram',     'phone'=>'+919876543225','email'=>'meena.s@email.com',         'gender'=>'Female','dob'=>'1987-05-22','blood'=>'B+', 'history'=>'Systemic Lupus Erythematosus. On Hydroxychloroquine + Prednisolone.',      'address'=>'19, Saidapet, Chennai 600015',               'emergency'=>'Sundaram R: +919876543215'],
    ['name'=>'Ravi Shankar',       'phone'=>'+919876543226','email'=>'ravi.shankar@email.com',    'gender'=>'Male',  'dob'=>'1993-09-02','blood'=>'A-', 'history'=>'Lumbar Disc Herniation L4-L5. Conservative management. Physiotherapy.',   'address'=>'62, Perambur, Chennai 600011',               'emergency'=>'Shankar M: +919876543216'],
    ['name'=>'Divya Mohan',        'phone'=>'+919876543227','email'=>'divya.m@email.com',         'gender'=>'Female','dob'=>'1996-12-11','blood'=>'O-', 'history'=>'Irritable Bowel Syndrome. Diet modification + Mebeverine 135mg TDS.',     'address'=>'35, Chetpet, Chennai 600031',                'emergency'=>'Mohan S: +919876543217'],
    ['name'=>'Sathish Kumar',      'phone'=>'+919876543228','email'=>'sathish.k@email.com',       'gender'=>'Male',  'dob'=>'1968-04-19','blood'=>'B+', 'history'=>'Benign Prostatic Hyperplasia. On Tamsulosin 0.4mg OD.',                   'address'=>'88, Ashok Nagar, Chennai 600083',            'emergency'=>'Kumar R: +919876543218'],
    ['name'=>'Pooja Venkataraman', 'phone'=>'+919876543229','email'=>'pooja.v@email.com',         'gender'=>'Female','dob'=>'1991-08-07','blood'=>'AB+','history'=>'Rheumatoid Arthritis. On Methotrexate 15mg weekly + Folic acid.',          'address'=>'44, Vadapalani, Chennai 600026',             'emergency'=>'Venkataraman G: +919876543219'],
    ['name'=>'Balaji Narayanan',   'phone'=>'+919876543230','email'=>'balaji.n@email.com',        'gender'=>'Male',  'dob'=>'1982-01-25','blood'=>'O+', 'history'=>'Chronic Hepatitis B. On Entecavir 0.5mg OD. HBV DNA undetectable.',       'address'=>'71, Thiruvanmiyur, Chennai 600041',          'emergency'=>'Narayanan S: +919876543220'],
    ['name'=>'Swathi Ramesh',      'phone'=>'+919876543231','email'=>'swathi.r@email.com',        'gender'=>'Female','dob'=>'1994-06-14','blood'=>'A+', 'history'=>'Primary Hypothyroidism. TSH: 6.2. Newly started on Levothyroxine 50mcg.', 'address'=>'29, Besant Nagar, Chennai 600090',           'emergency'=>'Ramesh K: +919876543221'],
    ['name'=>'Murugan Pillai',     'phone'=>'+919876543232','email'=>'murugan.p@email.com',       'gender'=>'Male',  'dob'=>'1958-10-30','blood'=>'B-', 'history'=>'Parkinsons Disease Hoehn & Yahr Stage 2. On Levodopa/Carbidopa.',          'address'=>'16, Royapettah, Chennai 600014',             'emergency'=>'Pillai V: +919876543222'],
    ['name'=>'Nandini Gopalan',    'phone'=>'+919876543233','email'=>'nandini.g@email.com',       'gender'=>'Female','dob'=>'1989-02-18','blood'=>'O+', 'history'=>'Gestational Diabetes (previous pregnancy). Annual OGTT monitoring.',      'address'=>'57, KK Nagar, Chennai 600078',               'emergency'=>'Gopalan M: +919876543223'],
    ['name'=>'Senthil Murugan',    'phone'=>'+919876543234','email'=>'senthil.m@email.com',       'gender'=>'Male',  'dob'=>'1977-07-09','blood'=>'AB-','history'=>'Chronic Sinusitis. Post-FESS (2023). On Mometasone nasal spray.',          'address'=>'83, Ambattur, Chennai 600053',               'emergency'=>'Murugan K: +919876543224'],
    ['name'=>'Jayanthi Raman',     'phone'=>'+919876543235','email'=>'jayanthi.r@email.com',      'gender'=>'Female','dob'=>'1963-11-03','blood'=>'A+', 'history'=>'Atrial Fibrillation. On Apixaban 5mg BD + Rate control with Metoprolol.', 'address'=>'38, West Mambalam, Chennai 600033',          'emergency'=>'Raman S: +919876543225'],
    ['name'=>'Prasanna Venkatesan','phone'=>'+919876543236','email'=>'prasanna.v@email.com',      'gender'=>'Male',  'dob'=>'1986-04-27','blood'=>'B+', 'history'=>'Major Depressive Disorder. On Sertraline 100mg OD. In remission.',        'address'=>'65, Ashok Nagar, Chennai 600083',            'emergency'=>'Venkatesan P: +919876543226'],
    ['name'=>'Uma Maheshwari',     'phone'=>'+919876543237','email'=>'uma.m@email.com',           'gender'=>'Female','dob'=>'1971-09-16','blood'=>'O-', 'history'=>'Fibromyalgia. On Pregabalin 75mg BD + Duloxetine 30mg OD.',               'address'=>'22, Teynampet, Chennai 600018',              'emergency'=>'Maheshwari R: +919876543227'],
    ['name'=>'Dinesh Chakraborty', 'phone'=>'+919876543238','email'=>'dinesh.c@email.com',        'gender'=>'Male',  'dob'=>'1999-12-05','blood'=>'A-', 'history'=>'Seasonal Allergies + Eczema. On Fexofenadine + Moisturizers.',             'address'=>'49, Pallavaram, Chennai 600043',             'emergency'=>'Chakraborty N: +919876543228'],
    ['name'=>'Sangeetha Balan',    'phone'=>'+919876543239','email'=>'sangeetha.b@email.com',     'gender'=>'Female','dob'=>'1984-03-21','blood'=>'B+', 'history'=>'Endometriosis. Post-laparoscopy (2024). On Dienogest 2mg.',               'address'=>'74, Sholinganallur, Chennai 600119',         'emergency'=>'Balan K: +919876543229'],
    ['name'=>'Venkat Raman',       'phone'=>'+919876543240','email'=>'venkat.r@email.com',        'gender'=>'Male',  'dob'=>'1955-08-13','blood'=>'O+', 'history'=>'COPD + Cor Pulmonale. On home oxygen 2L/min. Tiotropium + Formoterol.',   'address'=>'31, Avadi, Chennai 600054',                  'emergency'=>'Raman V: +919876543230'],
    ['name'=>'Aadhira Selvam',     'phone'=>'+919876543241','email'=>'aadhira.s@email.com',       'gender'=>'Female','dob'=>'2002-05-29','blood'=>'AB+','history'=>'Iron deficiency anemia. Hb: 10.1. On oral iron supplements.',              'address'=>'18, Medavakkam, Chennai 600100',             'emergency'=>'Selvam T: +919876543231'],
    ['name'=>'Manoj Kumar',        'phone'=>'+919876543242','email'=>'manoj.k@email.com',         'gender'=>'Male',  'dob'=>'1974-01-17','blood'=>'B-', 'history'=>'Gout. Recurrent flares. On Febuxostat 40mg OD. Uric acid: 6.8.',          'address'=>'96, Mogappair, Chennai 600037',              'emergency'=>'Kumar S: +919876543232'],
    ['name'=>'Radha Krishnan',     'phone'=>'+919876543243','email'=>'radha.k@email.com',         'gender'=>'Female','dob'=>'1997-10-08','blood'=>'A+', 'history'=>'Vitamin D Deficiency (Level: 12ng/ml). On Cholecalciferol 60K weekly.',    'address'=>'52, Kolathur, Chennai 600099',               'emergency'=>'Krishnan R: +919876543233'],
    ['name'=>'Aravind Swamy',      'phone'=>'+919876543244','email'=>'aravind.s@email.com',       'gender'=>'Male',  'dob'=>'1981-06-20','blood'=>'O+', 'history'=>'Peptic Ulcer Disease. H. pylori eradicated. On PPI maintenance.',         'address'=>'40, Anna Salai, Chennai 600002',             'emergency'=>'Swamy R: +919876543234'],
];

// ── Appointment reasons (varied) ──
$appointmentReasons = [
    'Routine monthly diabetes checkup and HbA1c review.',
    'Persistent dry cough for 2 weeks, worse at night.',
    'Blood pressure monitoring and medication adjustment.',
    'Follow-up after recent surgery, wound assessment.',
    'Annual health screening and blood work.',
    'Chest tightness and shortness of breath on exertion.',
    'Skin rash and itching on forearms for 5 days.',
    'Chronic lower back pain, physiotherapy review.',
    'Fever and sore throat for 3 days.',
    'Joint pain and morning stiffness in hands.',
    'Routine eye examination for diabetic retinopathy screening.',
    'Anxiety symptoms and sleep disturbance.',
    'Vaccination consultation for international travel.',
    'Pre-operative assessment for elective procedure.',
    'Headache and dizziness, needs evaluation.',
    'Weight management consultation and diet review.',
    'Thyroid function test results discussion.',
    'Knee pain worse on climbing stairs.',
    'Persistent fatigue and low energy levels.',
    'Post-COVID follow-up, lingering cough.',
    'Ear pain and reduced hearing on right side.',
    'Abdominal pain and bloating after meals.',
    'Urinary frequency and burning sensation.',
    'Medication refill and blood sugar log review.',
    'Pregnancy confirmation and first trimester care.',
    'Sports injury — right ankle sprain follow-up.',
    'Chronic migraine frequency increasing.',
    'Insomnia and difficulty concentrating.',
    'Child immunization schedule review.',
    'Dental pain referred for evaluation.',
];

$appointmentStatuses = ['scheduled','scheduled','scheduled','completed','completed','completed','completed','completed','completed','completed','cancelled','cancelled','no_show','scheduled','completed','completed','scheduled','completed','completed','completed','completed','cancelled','scheduled','completed','completed','scheduled','completed','completed','scheduled','completed'];

// ── Medicines for prescriptions ──
$medicines = [
    ['name'=>'Metformin 500mg',         'dosage'=>'1 tablet twice daily after meals',       'days'=>90, 'notes'=>'Monitor blood glucose weekly. Avoid alcohol.'],
    ['name'=>'Amlodipine 5mg',          'dosage'=>'1 tablet once daily in the morning',     'days'=>30, 'notes'=>'Monitor BP weekly. Report dizziness or ankle swelling.'],
    ['name'=>'Atorvastatin 20mg',       'dosage'=>'1 tablet at bedtime',                    'days'=>30, 'notes'=>'Lipid panel after 3 months. Avoid grapefruit juice.'],
    ['name'=>'Pantoprazole 40mg',       'dosage'=>'1 tablet before breakfast',               'days'=>14, 'notes'=>'Take 30 min before first meal. Avoid spicy food.'],
    ['name'=>'Cetirizine 10mg',         'dosage'=>'1 tablet at bedtime',                    'days'=>10, 'notes'=>'May cause drowsiness. Avoid driving if affected.'],
    ['name'=>'Azithromycin 500mg',      'dosage'=>'1 tablet daily for 3 days',               'days'=>3,  'notes'=>'Complete full course. Take on empty stomach.'],
    ['name'=>'Amoxicillin 500mg',       'dosage'=>'1 capsule thrice daily after meals',      'days'=>7,  'notes'=>'Complete the course. May cause diarrhea.'],
    ['name'=>'Paracetamol 650mg',       'dosage'=>'1 tablet every 6 hours as needed',        'days'=>5,  'notes'=>'Do not exceed 4 tablets in 24 hours. Avoid alcohol.'],
    ['name'=>'Ibuprofen 400mg',         'dosage'=>'1 tablet twice daily after meals',        'days'=>5,  'notes'=>'Take with food. Avoid if kidney disease present.'],
    ['name'=>'Losartan 50mg',           'dosage'=>'1 tablet once daily',                     'days'=>30, 'notes'=>'Monitor potassium levels. Avoid salt substitutes.'],
    ['name'=>'Levothyroxine 75mcg',     'dosage'=>'1 tablet on empty stomach in morning',    'days'=>30, 'notes'=>'Take 30 min before food. Recheck TSH in 6 weeks.'],
    ['name'=>'Escitalopram 10mg',       'dosage'=>'1 tablet in the morning',                 'days'=>30, 'notes'=>'Takes 2-4 weeks for full effect. Do not stop abruptly.'],
    ['name'=>'Salbutamol Inhaler',      'dosage'=>'2 puffs as needed, max 8 puffs/day',      'days'=>30, 'notes'=>'Shake well before use. Rinse mouth after use.'],
    ['name'=>'Insulin Glargine 100U/ml','dosage'=>'18 units subcutaneous at bedtime',         'days'=>30, 'notes'=>'Rotate injection sites. Store in refrigerator.'],
    ['name'=>'Pregabalin 75mg',         'dosage'=>'1 capsule twice daily',                   'days'=>14, 'notes'=>'May cause dizziness. Do not drive until stable.'],
    ['name'=>'Montelukast 10mg',        'dosage'=>'1 tablet at bedtime',                     'days'=>30, 'notes'=>'For asthma prevention. Report mood changes.'],
    ['name'=>'Doxycycline 100mg',       'dosage'=>'1 capsule twice daily after meals',       'days'=>10, 'notes'=>'Avoid sun exposure. Do not take with dairy.'],
    ['name'=>'Omeprazole 20mg',         'dosage'=>'1 capsule before breakfast',               'days'=>14, 'notes'=>'Swallow whole, do not crush. Avoid long-term use.'],
    ['name'=>'Metoprolol 50mg',         'dosage'=>'1 tablet twice daily',                    'days'=>30, 'notes'=>'Do not stop suddenly. Monitor heart rate.'],
    ['name'=>'Hydroxychloroquine 200mg','dosage'=>'1 tablet twice daily with meals',          'days'=>30, 'notes'=>'Annual eye check required. Take with food.'],
    ['name'=>'Rosuvastatin 10mg',       'dosage'=>'1 tablet at night',                       'days'=>30, 'notes'=>'Monitor liver function. Report muscle pain.'],
    ['name'=>'Telmisartan 40mg',        'dosage'=>'1 tablet in the morning',                 'days'=>30, 'notes'=>'Adequate hydration. Monitor renal function.'],
    ['name'=>'Gabapentin 300mg',        'dosage'=>'1 capsule thrice daily',                  'days'=>14, 'notes'=>'Dose titration required. Report visual changes.'],
    ['name'=>'Fluticasone Nasal Spray', 'dosage'=>'2 sprays each nostril once daily',         'days'=>30, 'notes'=>'Prime before first use. Avoid blowing nose after.'],
    ['name'=>'Clopidogrel 75mg',        'dosage'=>'1 tablet once daily',                     'days'=>30, 'notes'=>'Do not stop without consulting. Report unusual bleeding.'],
    ['name'=>'Ranitidine 150mg',        'dosage'=>'1 tablet twice daily before meals',        'days'=>14, 'notes'=>'Avoid excessive caffeine. Follow-up in 2 weeks.'],
    ['name'=>'Diclofenac Gel 1%',       'dosage'=>'Apply thin layer to affected area 3x/day', 'days'=>14, 'notes'=>'For external use only. Wash hands after applying.'],
    ['name'=>'Ferrous Sulphate 200mg',  'dosage'=>'1 tablet twice daily with vitamin C',      'days'=>30, 'notes'=>'May cause dark stools. Take with orange juice.'],
    ['name'=>'Multivitamin Tablet',     'dosage'=>'1 tablet daily after lunch',               'days'=>30, 'notes'=>'General supplementation. Take with food.'],
    ['name'=>'Tamsulosin 0.4mg',        'dosage'=>'1 capsule after dinner',                   'days'=>30, 'notes'=>'May cause dizziness on standing. Take with food.'],
];

$prescriptionStatuses = ['pending','pending','dispensed','dispensed','dispensed','pending','dispensed','dispensed','pending','dispensed','dispensed','pending','dispensed','pending','dispensed','dispensed','pending','dispensed','dispensed','pending','pending','dispensed','dispensed','pending','dispensed','dispensed','pending','dispensed','pending','dispensed'];

// ── Invoice amounts and statuses ──
$invoiceAmounts = [500,1200,800,3500,1500,2000,750,4500,600,2500,1800,950,3200,700,5000,1100,1650,9000,850,2200,1400,6500,550,3800,1950,7500,1300,4200,680,11000];
$invoiceStatuses = ['paid','pending','paid','paid','overdue','paid','pending','paid','cancelled','paid','paid','pending','paid','overdue','paid','paid','pending','paid','paid','overdue','paid','pending','paid','paid','cancelled','paid','pending','paid','paid','paid'];
$paymentMethods = ['cash','card','upi','card','','cash','','upi','','card','cash','','upi','','cash','card','','upi','cash','','card','','cash','upi','','card','','cash','upi','card'];

// ── Notification templates ──
$notifTemplates = [
    ['type'=>'system',      'title'=>'Welcome to ClinicOS!',                   'msg'=>'Your clinic has been provisioned successfully. Start by reviewing your dashboard.'],
    ['type'=>'appointment', 'title'=>'New Appointment Booked',                 'msg'=>'A new appointment has been scheduled for tomorrow at 10:00 AM.'],
    ['type'=>'appointment', 'title'=>'Appointment Reminder',                   'msg'=>'Reminder: You have 3 appointments scheduled for today.'],
    ['type'=>'billing',     'title'=>'Invoice Overdue',                        'msg'=>'Invoice INV-001 is overdue by 7 days. Please follow up with the patient.'],
    ['type'=>'prescription','title'=>'Prescription Ready for Dispensing',       'msg'=>'A new prescription for Metformin 500mg is ready to be dispensed.'],
    ['type'=>'system',      'title'=>'System Maintenance Scheduled',           'msg'=>'Scheduled maintenance on Sunday 2:00 AM - 4:00 AM IST. Expect brief downtime.'],
    ['type'=>'appointment', 'title'=>'Appointment Cancelled',                  'msg'=>'Patient Rajesh Kumar has cancelled their appointment for March 28.'],
    ['type'=>'billing',     'title'=>'Payment Received',                       'msg'=>'Payment of Rs. 3,500 received for Invoice INV-004 via UPI.'],
    ['type'=>'prescription','title'=>'Prescription Dispensed',                  'msg'=>'Prescription #5 has been dispensed by the pharmacist.'],
    ['type'=>'system',      'title'=>'New Staff Member Added',                 'msg'=>'A new nurse has been added to the clinic roster. Review in Staff Management.'],
    ['type'=>'appointment', 'title'=>'Patient No-Show',                        'msg'=>'Patient Ganesh Prasad did not show up for their scheduled appointment.'],
    ['type'=>'billing',     'title'=>'Monthly Revenue Report Ready',           'msg'=>'Your monthly billing summary for February is ready for review in Reports.'],
    ['type'=>'system',      'title'=>'Security Alert',                         'msg'=>'Multiple failed login attempts detected. Review audit logs for details.'],
    ['type'=>'appointment', 'title'=>'Walk-in Patient Registered',             'msg'=>'A new walk-in patient has been registered and needs appointment assignment.'],
    ['type'=>'billing',     'title'=>'Insurance Claim Pending',                'msg'=>'Insurance claim for patient Priya Sharma is pending verification.'],
    ['type'=>'prescription','title'=>'Medication Refill Request',              'msg'=>'Patient Suresh Iyer has requested a refill for Tiotropium inhaler.'],
    ['type'=>'system',      'title'=>'Backup Completed Successfully',          'msg'=>'Daily database backup completed at 3:00 AM. All data is secure.'],
    ['type'=>'appointment', 'title'=>'Appointment Rescheduled',                'msg'=>'Patient Deepika Rajan rescheduled their appointment to next Monday.'],
    ['type'=>'billing',     'title'=>'Discount Applied',                       'msg'=>'A 10% senior citizen discount has been applied to Invoice INV-010.'],
    ['type'=>'system',      'title'=>'New Feature Available',                  'msg'=>'PDF prescription export is now available. Try it from the Prescriptions page.'],
];

// ── Appointment note templates ──
$noteTemplates = [
    ['type'=>'note',      'msg'=>'Patient vitals: BP 130/85, Temp 98.4F, SpO2 98%, HR 72 bpm. Generally stable.'],
    ['type'=>'diagnosis', 'msg'=>'Diagnosis: Acute Pharyngitis. Throat congested, mild tonsillar enlargement bilaterally.'],
    ['type'=>'follow_up', 'msg'=>'Follow-up in 2 weeks. Repeat blood work for HbA1c and fasting glucose before next visit.'],
    ['type'=>'note',      'msg'=>'Patient reports improvement in symptoms. Reduced frequency of headaches from 4/week to 1/week.'],
    ['type'=>'diagnosis', 'msg'=>'Diagnosis: Allergic contact dermatitis. Erythematous papular rash on forearms. Patch test recommended.'],
    ['type'=>'follow_up', 'msg'=>'Refer to cardiology for stress test. Continue current medications. Follow-up after cardio report.'],
    ['type'=>'note',      'msg'=>'Patient vitals: BP 145/92, needs medication adjustment. Added Losartan 50mg to regimen.'],
    ['type'=>'diagnosis', 'msg'=>'Diagnosis: Lumbar spondylosis L4-L5. MRI shows mild disc bulge. Conservative management advised.'],
    ['type'=>'follow_up', 'msg'=>'Review thyroid panel results in 6 weeks. Continue Levothyroxine 75mcg. Monitor for symptoms.'],
    ['type'=>'note',      'msg'=>'Labs reviewed: CBC normal, LFT normal, RFT - borderline creatinine 1.3. Adequate hydration advised.'],
    ['type'=>'diagnosis', 'msg'=>'Diagnosis: Viral Upper Respiratory Infection. Symptomatic treatment. No antibiotics needed.'],
    ['type'=>'follow_up', 'msg'=>'Physiotherapy 3x/week for 4 weeks. Re-evaluate range of motion and pain score at follow-up.'],
    ['type'=>'note',      'msg'=>'Patient anxious about test results. Reassured. Explained treatment plan in detail. Questions answered.'],
    ['type'=>'diagnosis', 'msg'=>'Diagnosis: Acute Gastroenteritis. Likely food-borne. ORS + bland diet. Stool culture if persistent.'],
    ['type'=>'follow_up', 'msg'=>'Scheduled for minor procedure next week. Pre-op labs ordered. NPO after midnight day of procedure.'],
];

foreach ($provisioned as $t) {
    echo "  Seeding {$t['code']}...\n";
    try {
        $pdo->exec("USE {$t['dbName']}");

        $doctorId     = 2; // Provider user
        $pharmacistId = 5; // Pharmacist user
        $patientIds   = [];

        // ── PATIENTS (35) ──
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
        echo "    [OK] 35 patients seeded.\n";

        // ── APPOINTMENTS (30) ──
        $appointmentIds = [];
        for ($i = 0; $i < 30; $i++) {
            $pid = $patientIds[$i % count($patientIds)];
            $dayOffset = $i - 15; // spread from -15 days to +14 days
            $hour = 9 + ($i % 8); // 9 AM to 4 PM
            $status = $appointmentStatuses[$i];

            $appt = $pdo->prepare("INSERT INTO appointments (tenant_id, patient_id, doctor_id, appointment_time, encrypted_reason, status, created_at) VALUES (:tid, :pid, :did, DATE_ADD(NOW(), INTERVAL :day DAY) + INTERVAL :hour HOUR, :enc_reason, :status, DATE_ADD(NOW(), INTERVAL :create_day DAY))");
            $appt->execute([
                'tid'        => $t['tenantId'],
                'pid'        => $pid,
                'did'        => $doctorId,
                'day'        => $dayOffset,
                'hour'       => $hour,
                'enc_reason' => aes_encrypt($appointmentReasons[$i], $ENCRYPTION_KEY),
                'status'     => $status,
                'create_day' => $dayOffset - 3, // created 3 days before appointment
            ]);
            $appointmentIds[] = (int) $pdo->lastInsertId();
        }
        echo "    [OK] 30 appointments seeded.\n";

        // ── PRESCRIPTIONS (30) ──
        for ($i = 0; $i < 30; $i++) {
            $pid = $patientIds[$i % count($patientIds)];
            $apptId = $appointmentIds[$i % count($appointmentIds)];
            $med = $medicines[$i];
            $rxStatus = $prescriptionStatuses[$i];
            $pharmId = ($rxStatus === 'dispensed') ? $pharmacistId : null;

            $rx = $pdo->prepare("INSERT INTO prescriptions (tenant_id, appointment_id, patient_id, provider_id, pharmacist_id, encrypted_medicine_name, encrypted_dosage, encrypted_notes, duration_days, status, created_at) VALUES (:tid, :appt_id, :pid, :prov_id, :pharm_id, :enc_med, :enc_dos, :enc_notes, :dur, :status, DATE_SUB(NOW(), INTERVAL :days_ago DAY))");
            $rx->execute([
                'tid'       => $t['tenantId'],
                'appt_id'   => $apptId,
                'pid'       => $pid,
                'prov_id'   => $doctorId,
                'pharm_id'  => $pharmId,
                'enc_med'   => aes_encrypt($med['name'],   $ENCRYPTION_KEY),
                'enc_dos'   => aes_encrypt($med['dosage'], $ENCRYPTION_KEY),
                'enc_notes' => aes_encrypt($med['notes'],  $ENCRYPTION_KEY),
                'dur'       => $med['days'],
                'status'    => $rxStatus,
                'days_ago'  => max(0, 15 - $i), // stagger creation dates
            ]);
        }
        echo "    [OK] 30 prescriptions seeded.\n";

        // ── INVOICES (30) ──
        for ($i = 0; $i < 30; $i++) {
            $pid = $patientIds[$i % count($patientIds)];
            $apptId = $appointmentIds[$i % count($appointmentIds)];
            $amount = $invoiceAmounts[$i];
            $tax = round($amount * 0.18, 2);  // 18% GST
            $total = round($amount + $tax, 2);
            $invStatus = $invoiceStatuses[$i];
            $paidAmt = ($invStatus === 'paid') ? $total : (($invStatus === 'overdue') ? 0 : (($invStatus === 'cancelled') ? 0 : 0));
            $invNumber = strtoupper($t['code']) . '-INV-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            $paidAt = ($invStatus === 'paid') ? "DATE_SUB(NOW(), INTERVAL " . max(0, 10 - $i) . " DAY)" : 'NULL';
            $dueDate = "DATE_ADD(NOW(), INTERVAL " . ($i - 10) . " DAY)";
            $payMethod = $paymentMethods[$i];
            $noteText = "Consultation and treatment charges for visit #" . ($i + 1);

            $inv = $pdo->prepare("INSERT INTO invoices (tenant_id, invoice_number, patient_id, provider_id, appointment_id, amount, subtotal, tax, discount, total, total_amount, paid_amount, status, payment_method, paid_at, due_date, encrypted_notes, created_by, created_at) VALUES (:tid, :inv_num, :pid, :prov_id, :appt_id, :amount, :subtotal, :tax, 0, :total, :total_amount, :paid_amount, :status, :pay_method, " . ($invStatus === 'paid' ? "DATE_SUB(NOW(), INTERVAL " . max(0, 10 - $i) . " DAY)" : "NULL") . ", DATE_ADD(NOW(), INTERVAL " . ($i - 10) . " DAY), :enc_notes, :created_by, DATE_SUB(NOW(), INTERVAL " . max(0, 15 - $i) . " DAY))");
            $inv->execute([
                'tid'          => $t['tenantId'],
                'inv_num'      => $invNumber,
                'pid'          => $pid,
                'prov_id'      => $doctorId,
                'appt_id'      => $apptId,
                'amount'       => $amount,
                'subtotal'     => $amount,
                'tax'          => $tax,
                'total'        => $total,
                'total_amount' => $total,
                'paid_amount'  => $paidAmt,
                'status'       => $invStatus,
                'pay_method'   => $payMethod ?: null,
                'enc_notes'    => aes_encrypt($noteText, $ENCRYPTION_KEY),
                'created_by'   => 1, // admin
            ]);
        }
        echo "    [OK] 30 invoices seeded.\n";

        // ── NOTIFICATIONS (20) ──
        $userIds = [1, 2, 3, 4, 5]; // admin, doctor, nurse, receptionist, pharmacist
        foreach ($notifTemplates as $ni => $n) {
            $uid = $userIds[$ni % count($userIds)];
            $isRead = ($ni < 10) ? 1 : 0;
            $readAt = $isRead ? "DATE_SUB(NOW(), INTERVAL " . (20 - $ni) . " DAY)" : 'NULL';

            $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, user_id, type, title, message, is_read, read_at, created_at) VALUES (:tid, :uid, :type, :title, :msg, :is_read, " . ($isRead ? "DATE_SUB(NOW(), INTERVAL " . (20 - $ni) . " DAY)" : "NULL") . ", DATE_SUB(NOW(), INTERVAL :days_ago DAY))");
            $notif->execute([
                'tid'      => $t['tenantId'],
                'uid'      => $uid,
                'type'     => $n['type'],
                'title'    => $n['title'],
                'msg'      => $n['msg'],
                'is_read'  => $isRead,
                'days_ago' => 20 - $ni,
            ]);
        }
        echo "    [OK] 20 notifications seeded.\n";

        // ── APPOINTMENT NOTES (15) ──
        foreach ($noteTemplates as $ni => $note) {
            $apptId = $appointmentIds[$ni % count($appointmentIds)];
            $authorId = ($ni % 2 === 0) ? $doctorId : 3; // alternate doctor/nurse

            $an = $pdo->prepare("INSERT INTO appointment_notes (tenant_id, appointment_id, author_id, message_encrypted, note_type, visible_to_role, created_at) VALUES (:tid, :appt_id, :auth_id, :enc_msg, :type, 'all', DATE_SUB(NOW(), INTERVAL :days_ago DAY))");
            $an->execute([
                'tid'      => $t['tenantId'],
                'appt_id'  => $apptId,
                'auth_id'  => $authorId,
                'enc_msg'  => aes_encrypt($note['msg'], $ENCRYPTION_KEY),
                'type'     => $note['type'],
                'days_ago' => 15 - $ni,
            ]);
        }
        echo "    [OK] 15 appointment notes seeded.\n";

        // ── AUDIT LOG (10 entries) ──
        $auditActions = [
            ['action'=>'patient.created',     'entity'=>'patient',      'eid'=>$patientIds[0]],
            ['action'=>'appointment.created', 'entity'=>'appointment', 'eid'=>$appointmentIds[0]],
            ['action'=>'prescription.created','entity'=>'prescription','eid'=>1],
            ['action'=>'invoice.created',     'entity'=>'invoice',     'eid'=>1],
            ['action'=>'patient.updated',     'entity'=>'patient',      'eid'=>$patientIds[1]],
            ['action'=>'appointment.updated', 'entity'=>'appointment', 'eid'=>$appointmentIds[1]],
            ['action'=>'user.login',          'entity'=>'user',        'eid'=>1],
            ['action'=>'user.login',          'entity'=>'user',        'eid'=>2],
            ['action'=>'settings.updated',    'entity'=>'settings',    'eid'=>1],
            ['action'=>'invoice.paid',        'entity'=>'invoice',     'eid'=>2],
        ];
        foreach ($auditActions as $ai => $a) {
            $pdo->exec("INSERT INTO audit_log (user_id, tenant_id, action, entity_type, entity_id, ip_address, created_at) VALUES (" . (($ai % 5) + 1) . ", {$t['tenantId']}, '{$a['action']}', '{$a['entity']}', {$a['eid']}, '127.0.0.1', DATE_SUB(NOW(), INTERVAL " . (10 - $ai) . " DAY))");
        }
        echo "    [OK] 10 audit log entries seeded.\n";

        echo "  [OK] {$t['code']} — ALL DATA SEEDED (35 patients, 30 appointments, 30 prescriptions, 30 invoices, 20 notifications, 15 notes, 10 audit logs)\n\n";

    } catch (Exception $e) {
        echo "  [FAILED] {$t['code']}: " . $e->getMessage() . "\n\n";
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