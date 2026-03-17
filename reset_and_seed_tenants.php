<?php

/**
 * reset_and_seed_tenants.php
 * ===========================
 * 1. Connects to the master database.
 * 2. Fetches all existing tenants.
 * 3. Drops their databases and users.
 * 4. Clears the tenants table.
 * 5. Uses TenantProvisioningService to create 5 new clinics (Apollo, Fortis, Max, Care, KIMS).
 * 6. Seeds each new tenant with sample patients and appointments using CryptoService encoding.
 */

define('BASE_PATH', __DIR__);

// Auto-loader for App namespace
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($file)) require $file;
    }
});

require_once BASE_PATH . '/app/Helpers/functions.php';
\App\Helpers\EnvLoader::load(BASE_PATH . '/.env');

$dbConfig = require BASE_PATH . '/config/database.php';

// Instantiate necessary services
$masterDb = \App\Core\MasterDatabase::getInstance();
$provisioningService = new \App\Modules\SuperAdmin\Services\TenantProvisioningService();
$crypto = new \App\Core\Security\CryptoService();

echo "============================================\n";
echo "  TENANT RESET & SEED SCRIPT\n";
echo "============================================\n\n";

// ─────────────────────────────────────────────
// 1. CLEAR EXISTING TENANTS
// ─────────────────────────────────────────────
echo "--- Clearing Existing Tenants ---\n";

try {
    $existingTenants = $masterDb->fetchAll('SELECT tenant_code, db_name, db_username FROM tenants');
    
    // Connect as Root to drop databases and users
    $rootHost = env('DB_HOST', '127.0.0.1');
    $rootPort = env('DB_PORT', '3306');
    $rootUser = env('DB_USERNAME', 'root');
    $rootPass = env('DB_PASSWORD', '');
    $rootPdo = new \PDO("mysql:host={$rootHost};port={$rootPort};charset=utf8mb4", $rootUser, $rootPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

    foreach ($existingTenants as $tenant) {
        $dbName = $tenant['db_name'];
        $dbUser = $tenant['db_username'];
        
        echo "  Dropping {$dbName} & user {$dbUser}...\n";
        $rootPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        $rootPdo->exec("DROP USER IF EXISTS '{$dbUser}'@'%'");
    }

    echo "  Clearing tenants table & audit logs in master DB...\n";
    $masterDb->execute('SET FOREIGN_KEY_CHECKS = 0');
    $masterDb->execute('TRUNCATE TABLE tenants');
    $masterDb->execute('TRUNCATE TABLE master_audit_logs');
    $masterDb->execute('SET FOREIGN_KEY_CHECKS = 1');

} catch (\Exception $e) {
    die("Failed to clear existing tenants: " . $e->getMessage() . "\n");
}

echo "  [OK] Clear complete.\n\n";

// ─────────────────────────────────────────────
// 2. PROVISION NEW TENANTS
// ─────────────────────────────────────────────
echo "--- Provisioning New Tenants ---\n";

// Need a mock Super Admin ID for the audit logs (Assuming ID 1 exists from seedsuper.php)
$superAdminContext = ['super_admin_id' => 1, 'ip' => '127.0.0.1'];

$newClinics = [
    ['tenant_code' => 'apollo', 'name' => 'Apollo Hospitals', 'email' => 'admin@apollo.com', 'plan' => 'enterprise', 'admin_password' => 'Apollo@1234'],
    ['tenant_code' => 'fortis', 'name' => 'Fortis Healthcare', 'email' => 'admin@fortis.com', 'plan' => 'standard',   'admin_password' => 'Fortis@1234'],
    ['tenant_code' => 'max',    'name' => 'Max Super Speciality', 'email' => 'admin@max.com', 'plan' => 'professional', 'admin_password' => 'Max@1234'],
    ['tenant_code' => 'care',   'name' => 'Care Hospitals', 'email' => 'admin@care.com', 'plan' => 'basic',          'admin_password' => 'Care@1234'],
    ['tenant_code' => 'kims',   'name' => 'KIMS Hospitals', 'email' => 'admin@kims.com', 'plan' => 'basic',          'admin_password' => 'Kims@1234'],
];

$provisionedData = [];

foreach ($newClinics as $clinicDetails) {
    echo "  Provisioning {$clinicDetails['tenant_code']}...\n";
    try {
        $result = $provisioningService->provision($clinicDetails, $superAdminContext);
        $provisionedData[] = $result;
        echo "  [OK] Provisioned {$clinicDetails['tenant_code']} (DB: {$result['database']})\n";
    } catch (\Exception $e) {
        echo "  [FAILED] Could not provision {$clinicDetails['tenant_code']}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────
// 3. SEED MOCK DATA
// ─────────────────────────────────────────────
echo "\n--- Seeding Mock Data ---\n";

$patientsSeed = [
    ['name' => 'John Doe', 'phone' => '+919876543210', 'email' => 'john@email.com', 'medical_history' => 'Type 2 Diabetes. On Metformin.'],
    ['name' => 'Jane Smith', 'phone' => '+919876543211', 'email' => 'jane@email.com', 'medical_history' => 'Asthma. Uses Salbutamol inhaler PRN.'],
    ['name' => 'Michael Johnson', 'phone' => '+919876543212', 'email' => 'michael@email.com', 'medical_history' => 'Hypertension. taking Amlodipine 5mg.'],
];

$appointmentsSeed = [
    ['reason' => 'Routine monthly diabetes checkup.'],
    ['reason' => 'Persistent cough for 2 weeks.'],
    ['reason' => 'Blood pressure monitoring.'],
];

foreach ($provisionedData as $tenant) {
    echo "  Seeding {$tenant['tenant_code']}...\n";
    $tenantDbName = $tenant['database'];
    $rootPdo->exec("USE `{$tenantDbName}`");

    // Insert Patients
    foreach ($patientsSeed as $p) {
        $stmt = $rootPdo->prepare(
            'INSERT INTO patients
                (tenant_id, encrypted_name, name_hash, encrypted_phone, phone_hash, encrypted_email, email_hash, encrypted_medical_history, status, created_at)
             VALUES
                (:t_id, :enc_name, :hash_name, :enc_phone, :hash_phone, :enc_email, :hash_email, :enc_hist, "active", NOW())'
        );
        $stmt->execute([
            't_id'       => $tenant['tenant_id'],
            'enc_name'   => $crypto->encrypt($p['name']),
            'hash_name'  => $crypto->hash($p['name']),
            'enc_phone'  => $crypto->encrypt($p['phone']),
            'hash_phone' => $crypto->hash($p['phone']),
            'enc_email'  => $crypto->encrypt($p['email']),
            'hash_email' => $crypto->hash($p['email']),
            'enc_hist'   => $crypto->encrypt($p['medical_history']),
        ]);
        $patientId = $rootPdo->lastInsertId();

        // Assign a random appointment for the patient
        $reason = $appointmentsSeed[array_rand($appointmentsSeed)]['reason'];
        
        $apptStmt = $rootPdo->prepare(
            'INSERT INTO appointments
                (tenant_id, patient_id, doctor_id, appointment_time, encrypted_reason, status, created_at)
             VALUES
                (:t_id, :p_id, 1, DATE_ADD(NOW(), INTERVAL 1 DAY), :enc_reason, "scheduled", NOW())'
        );
        
        $apptStmt->execute([
             't_id'       => $tenant['tenant_id'],
             'p_id'       => $patientId,
             'enc_reason' => $crypto->encrypt($reason),
        ]);
    }
    
    echo "  [OK] Seeded 3 patients and 3 appointments for {$tenant['tenant_code']}.\n";
}

// ─────────────────────────────────────────────
// SUMMARY OUTPUT
// ─────────────────────────────────────────────
echo "\n============================================\n";
echo "  COMPLETED SUCCESSFULLY\n";
echo "============================================\n\n";

echo "Login Credentials for New Tenants:\n";
foreach ($provisionedData as $tenant) {
    echo "  Tenant: {$tenant['tenant_code']}\n";
    echo "    Admin Login : {$tenant['admin_email']} OR {$tenant['tenant_code']}_admin\n";
    echo "    Password    : {$tenant['admin_password_plain']}\n";
    echo "    Database    : {$tenant['database']}\n";
    echo "    Plan        : {$tenant['plan']}\n\n";
}
echo "Done!\n";
