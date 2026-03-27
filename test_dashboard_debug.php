<?php
require_once 'C:/wamp64/www/clinic_backend/vendor/autoload.php';
require_once 'C:/wamp64/www/clinic_backend/app/Core/TenantDatabase.php';
require_once 'C:/wamp64/www/clinic_backend/app/Core/Security/CryptoService.php';
require_once 'C:/wamp64/www/clinic_backend/app/Modules/ReportsDashboard/Models/DashboardStats.php';

// Mock tenant_db()
function tenant_db() {
    $config = [
        'host' => 'localhost',
        'dbname' => 'clinic_tenant_apollo_db',
        'user' => 'root',
        'pass' => ''
    ];
    return new \App\Core\TenantDatabase($config);
}

use App\Modules\ReportsDashboard\Models\DashboardStats;

try {
    $stats = new DashboardStats();
    // Test for Provider role (which failed in the UI)
    // Assuming user_id 6 is apollo_doctor from setup.php
    $data = $stats->getCounts(1, 'Provider', 6);
    echo "SUCCESS\n";
    print_r(array_keys($data));
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
