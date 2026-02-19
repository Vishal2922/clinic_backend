<?php
/**
 * seed_encrypted.php
 * ==================
 * Run AFTER clinic_db_setup.sql
 * Encrypts ALL placeholder values with AES-256-CBC
 * 
 * Usage:
 *   1. mysql -u root -p < clinic_db_setup.sql
 *   2. php seed_encrypted.php
 */

define('BASE_PATH', __DIR__);

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});

require_once BASE_PATH . '/app/Helpers/functions.php';
\App\Helpers\EnvLoader::load(BASE_PATH . '/.env');

$dbConfig = require BASE_PATH . '/config/database.php';
$db = \App\Core\Database::getInstance($dbConfig);
$crypto = new \App\Core\Security\CryptoService();

echo "============================================\n";
echo "  HIPAA-COMPLIANT ENCRYPTION SEEDER\n";
echo "  All data encrypted with AES-256-CBC\n";
echo "============================================\n\n";

// ─────────────────────────────────────────────
// 1. ENCRYPT USER DATA
// ─────────────────────────────────────────────
echo "--- Encrypting Users ---\n";

$users = [
    ['id'=>1, 'email'=>'admin@cityclinic.com',        'full_name'=>'City Clinic Admin',      'phone'=>'+1111111111', 'password'=>'Admin@123'],
    ['id'=>2, 'email'=>'drsmith@cityclinic.com',      'full_name'=>'Dr. John Smith',          'phone'=>'+1111111112', 'password'=>'Doctor@123'],
    ['id'=>3, 'email'=>'jen.nurse@cityclinic.com',    'full_name'=>'Jennifer Adams',          'phone'=>'+1111111113', 'password'=>'Nurse@123'],
    ['id'=>4, 'email'=>'reception@cityclinic.com',    'full_name'=>'Maya Patel',              'phone'=>'+1111111114', 'password'=>'Staff@123'],
    ['id'=>5, 'email'=>'pharmacy@cityclinic.com',     'full_name'=>'Raj Kumar',               'phone'=>'+1111111115', 'password'=>'Pharma@123'],
    ['id'=>6, 'email'=>'alice@email.com',             'full_name'=>'Alice Johnson',           'phone'=>'+919876543210','password'=>'Patient@123'],
    ['id'=>7, 'email'=>'admin@sunrisemed.com',        'full_name'=>'Sunrise Medical Admin',   'phone'=>'+2222222221', 'password'=>'Admin@123'],
    ['id'=>8, 'email'=>'drpatel@sunrisemed.com',      'full_name'=>'Dr. Priya Patel',         'phone'=>'+2222222222', 'password'=>'Doctor@123'],
];

foreach ($users as $u) {
    $argon = password_hash($u['password'], PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 3,
    ]);

    $db->execute(
        'UPDATE users SET
            encrypted_email    = :enc_email,
            email_hash         = :email_hash,
            password_hash      = :pass_hash,
            encrypted_full_name = :enc_name,
            encrypted_phone    = :enc_phone,
            status             = :status
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
    echo "  [OK] User #{$u['id']} encrypted\n";
}

// ─────────────────────────────────────────────
// 2. ENCRYPT PATIENT DATA
// ─────────────────────────────────────────────
echo "\n--- Encrypting Patients ---\n";

$patients = [
    ['id'=>1, 'name'=>'Alice Johnson',  'phone'=>'+919876543210', 'email'=>'alice@email.com',    'medical_history'=>'Type 2 Diabetes, Hypertension. HbA1c: 7.2%. On Metformin 500mg BD.'],
    ['id'=>2, 'name'=>'Bob Williams',   'phone'=>'+919876543211', 'email'=>'bob@email.com',      'medical_history'=>'Asthma since childhood. Uses Salbutamol inhaler PRN. No recent attacks.'],
    ['id'=>3, 'name'=>'Carol Davis',    'phone'=>'+919876543212', 'email'=>'carol@email.com',    'medical_history'=>'Post-surgical follow-up. Appendectomy 2 weeks ago. Healing well.'],
    ['id'=>4, 'name'=>'David Chen',     'phone'=>'+919876543213', 'email'=>'david@email.com',    'medical_history'=>'Seasonal allergies. Takes Cetirizine during spring. No other conditions.'],
];

foreach ($patients as $p) {
    $db->execute(
        'UPDATE patients SET
            encrypted_name            = :enc_name,
            name_hash                 = :name_hash,
            encrypted_phone           = :enc_phone,
            phone_hash                = :phone_hash,
            encrypted_email           = :enc_email,
            email_hash                = :email_hash,
            encrypted_medical_history = :enc_history
        WHERE id = :id',
        [
            'enc_name'    => $crypto->encrypt($p['name']),
            'name_hash'   => $crypto->hash($p['name']),
            'enc_phone'   => $crypto->encrypt($p['phone']),
            'phone_hash'  => $crypto->hash($p['phone']),
            'enc_email'   => $crypto->encrypt($p['email']),
            'email_hash'  => $crypto->hash($p['email']),
            'enc_history' => $crypto->encrypt($p['medical_history']),
            'id'          => $p['id'],
        ]
    );
    echo "  [OK] Patient #{$p['id']} '{$p['name']}' encrypted\n";
}

// ─────────────────────────────────────────────
// 3. ENCRYPT APPOINTMENT REASONS
// ─────────────────────────────────────────────
echo "\n--- Encrypting Appointment Reasons ---\n";

$appointments = [
    ['id'=>1, 'reason'=>'Annual checkup and blood work review'],
    ['id'=>2, 'reason'=>'Persistent cough for 2 weeks, difficulty breathing at night'],
    ['id'=>3, 'reason'=>'Post-surgery follow-up, wound check and stitch removal'],
    ['id'=>4, 'reason'=>'Diabetes management review, medication adjustment'],
    ['id'=>5, 'reason'=>'New patient consultation, general health assessment'],
];

foreach ($appointments as $a) {
    $db->execute(
        'UPDATE appointments SET encrypted_reason = :enc_reason WHERE id = :id',
        [
            'enc_reason' => $crypto->encrypt($a['reason']),
            'id'         => $a['id'],
        ]
    );
    echo "  [OK] Appointment #{$a['id']} reason encrypted\n";
}

// ─────────────────────────────────────────────
// 4. ENCRYPT PRESCRIPTIONS
// ─────────────────────────────────────────────
echo "\n--- Encrypting Prescriptions ---\n";

$prescriptions = [
    ['id'=>1, 'medicine'=>'Amoxicillin 500mg', 'dosage'=>'1 capsule 3 times daily after meals', 'notes'=>'Complete the full 7-day course. Take with food to reduce stomach upset.'],
    ['id'=>2, 'medicine'=>'Ibuprofen 400mg',   'dosage'=>'1 tablet twice daily as needed for pain', 'notes'=>'Take with food. Do not exceed 3 tablets per day. Avoid if stomach issues.'],
];

foreach ($prescriptions as $rx) {
    $db->execute(
        'UPDATE prescriptions SET
            encrypted_medicine_name = :enc_med,
            encrypted_dosage        = :enc_dose,
            encrypted_notes         = :enc_notes
        WHERE id = :id',
        [
            'enc_med'   => $crypto->encrypt($rx['medicine']),
            'enc_dose'  => $crypto->encrypt($rx['dosage']),
            'enc_notes' => $crypto->encrypt($rx['notes']),
            'id'        => $rx['id'],
        ]
    );
    echo "  [OK] Prescription #{$rx['id']} encrypted\n";
}

// ─────────────────────────────────────────────
// 5. ENCRYPT INVOICE NOTES
// ─────────────────────────────────────────────
echo "\n--- Encrypting Invoice Notes ---\n";

$invoices = [
    ['id'=>1, 'notes'=>'Consultation fee for annual checkup. Insurance claim pending.'],
    ['id'=>2, 'notes'=>'Post-surgery follow-up charges. Paid via insurance.'],
    ['id'=>3, 'notes'=>'New patient registration and initial consultation.'],
];

foreach ($invoices as $inv) {
    $db->execute(
        'UPDATE invoices SET encrypted_notes = :enc_notes WHERE id = :id',
        ['enc_notes' => $crypto->encrypt($inv['notes']), 'id' => $inv['id']]
    );
    echo "  [OK] Invoice #{$inv['id']} notes encrypted\n";
}

// ─────────────────────────────────────────────
// 6. ENCRYPT STAFF DATA
// ─────────────────────────────────────────────
echo "\n--- Encrypting Staff Data ---\n";

$staffData = [
    ['id'=>1, 'department'=>'General Medicine',  'specialization'=>'Internal Medicine',    'license'=>'MED-2023-001'],
    ['id'=>2, 'department'=>'Nursing',            'specialization'=>'Emergency Care',       'license'=>'NUR-2023-001'],
    ['id'=>3, 'department'=>'Front Desk',         'specialization'=>'Patient Relations',    'license'=>null],
    ['id'=>4, 'department'=>'Pharmacy',           'specialization'=>'Clinical Pharmacy',    'license'=>'PHR-2023-001'],
    ['id'=>5, 'department'=>'Cardiology',         'specialization'=>'Cardiology',           'license'=>'MED-2023-002'],
];

foreach ($staffData as $s) {
    $params = [
        'enc_dept'  => $crypto->encrypt($s['department']),
        'enc_spec'  => $crypto->encrypt($s['specialization']),
        'id'        => $s['id'],
    ];

    $licSql = '';
    if ($s['license']) {
        $licSql = ', encrypted_license_number = :enc_lic';
        $params['enc_lic'] = $crypto->encrypt($s['license']);
    }

    $db->execute(
        "UPDATE staff SET
            encrypted_department      = :enc_dept,
            encrypted_specialization  = :enc_spec
            {$licSql}
        WHERE id = :id",
        $params
    );
    echo "  [OK] Staff #{$s['id']} encrypted\n";
}

// ─────────────────────────────────────────────
// 7. ENCRYPT APPOINTMENT NOTES
// ─────────────────────────────────────────────
echo "\n--- Encrypting Appointment Notes ---\n";

$notes = [
    ['id'=>1, 'message'=>'Patient presents with post-surgical healing. Wound site clean, no signs of infection. Sutures removed successfully. Advised to continue wound care for 1 week.'],
    ['id'=>2, 'message'=>'PROVIDER NOTE: Monitor for signs of wound infection. Schedule follow-up in 2 weeks. Patient to call if redness or swelling increases.'],
];

foreach ($notes as $n) {
    $db->execute(
        'UPDATE appointment_notes SET message_encrypted = :enc_msg WHERE id = :id',
        ['enc_msg' => $crypto->encrypt($n['message']), 'id' => $n['id']]
    );
    echo "  [OK] Note #{$n['id']} encrypted\n";
}

// ─────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────
echo "\n============================================\n";
echo "  ENCRYPTION COMPLETE\n";
echo "============================================\n\n";

echo "Login Credentials:\n";
echo "  Tenant: CLINIC001\n";
echo "    admin         / Admin@123\n";
echo "    dr_smith      / Doctor@123\n";
echo "    nurse_jen     / Nurse@123\n";
echo "    receptionist1 / Staff@123\n";
echo "    pharmacist1   / Pharma@123\n";
echo "    patient1      / Patient@123\n";
echo "  Tenant: CLINIC002\n";
echo "    admin         / Admin@123\n";
echo "    dr_patel      / Doctor@123\n\n";

$counts = $db->fetchAll(
    'SELECT t.tenant_code, t.name,
        (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS users,
        (SELECT COUNT(*) FROM patients p WHERE p.tenant_id = t.id AND p.deleted_at IS NULL) AS patients,
        (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = t.id AND a.deleted_at IS NULL) AS appointments,
        (SELECT COUNT(*) FROM prescriptions pr WHERE pr.tenant_id = t.id) AS prescriptions,
        (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id AND i.deleted_at IS NULL) AS invoices
    FROM tenants t'
);

echo "Database Summary:\n";
foreach ($counts as $row) {
    printf(
        "  [%s] %s\n    Users:%d  Patients:%d  Appointments:%d  Prescriptions:%d  Invoices:%d\n",
        $row['tenant_code'], $row['name'],
        $row['users'], $row['patients'], $row['appointments'], $row['prescriptions'], $row['invoices']
    );
}
echo "\nDone!\n";