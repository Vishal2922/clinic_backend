<?php
// C:\wamp64\www\clinic_backend\check_db.php
require_once __DIR__ . '/vendor/autoload.php';

// Mocking basic env for the script if needed, or just hardcode for speed
$DB_HOST = '127.0.0.1';
$DB_PORT = '3308';
$DB_USER = 'root';
$DB_PASS = '';
$TENANT_DB = 'clinic_tenant_apollo_db'; // Assuming apollo tenant based on screenshots

try {
    $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$TENANT_DB};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    echo "--- Appointments for Patient 5 ---\n";
    $stmt = $pdo->query("SELECT id, patient_id, appointment_time, status FROM appointments WHERE patient_id = 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);

    echo "\n--- Prescriptions for Patient 5 ---\n";
    $stmt = $pdo->query("SELECT id, patient_id, appointment_id, created_at FROM prescriptions WHERE patient_id = 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
