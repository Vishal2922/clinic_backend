<?php

namespace App\Modules\Prescriptions\Models;

use App\Core\Database;
use PDO;

class Prescription {
    private Database $db;

    public function __construct() {
        /**
         * 🔥 FIX: Merged Singleton Logic
         * 'new' keyword use pannama Core Database-oda static instance-ah use panrom.
         * Ithu connection pooling-ah avoid panni performance-ah optimize pannum.
         */
        $this->db = Database::getInstance();
    }

    /**
     * CREATE PRESCRIPTION
     * Controller-la encrypt aana data-vai DB-la save pannum.
     */
    public function create(array $data) {
        /**
         * Namma merge panna Database::insert() method use panrom.
         * Ithu automatic-ah prepare panni, execute panni, Last Insert ID-ah return pannum.
         */
        $sql = "INSERT INTO prescriptions (
                    tenant_id, 
                    appointment_id, 
                    patient_id, 
                    provider_id, 
                    medicine_name, 
                    dosage, 
                    notes, 
                    status
                ) VALUES (
                    :tenant_id, 
                    :appointment_id, 
                    :patient_id, 
                    :provider_id, 
                    :medicine_name, 
                    :dosage, 
                    :notes, 
                    'pending'
                )";
        
        return $this->db->insert($sql, [
            ':tenant_id'      => $data['tenant_id'],
            ':appointment_id' => $data['appointment_id'],
            ':patient_id'     => $data['patient_id'],
            ':provider_id'    => $data['provider_id'],
            ':medicine_name'  => $data['medicine_name'], // Expected to be AES Encrypted
            ':dosage'         => $data['dosage'],        // Expected to be AES Encrypted
            ':notes'          => $data['notes'] ?? null
        ]);
    }

    /**
     * FIND BY ID (Multi-tenancy Secured)
     * Tenant ID check illama endha data-vaiyum fetch panna koodathu.
     */
    public function findById($id, $tenant_id): ?array {
        // Core Database::fetch() method-ah use panrom (Standardized SQL injection protection)
        $sql = "SELECT * FROM prescriptions WHERE id = :id AND tenant_id = :tenant_id";
        return $this->db->fetch($sql, [
            ':id' => $id, 
            ':tenant_id' => $tenant_id
        ]);
    }

    /**
     * ROLE-BASED UPDATE (Provider or Pharmacist)
     * Pharmacist dispense pannumbothu status 'completed' aagum and avaroda ID track aagum.
     */
    public function updateStatus($id, $tenant_id, array $updateData) {
        /**
         * Logic merged: We support updating status, pharmacist_id (auditing), 
         * and even dosage (in case of clinical adjustments).
         */
        $sql = "UPDATE prescriptions SET 
                status = :status, 
                pharmacist_id = :pharmacist_id,
                dosage = :dosage,
                updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id AND tenant_id = :tenant_id";
        
        // Core Database::execute() return pannum affected rows count-ah
        return $this->db->execute($sql, [
            ':status'        => $updateData['status'],
            ':pharmacist_id' => $updateData['pharmacist_id'] ?? null,
            ':dosage'        => $updateData['dosage'] ?? null, 
            ':id'            => $id,
            ':tenant_id'     => $tenant_id
        ]);
    }

    /**
     * FETCH ALL FOR TENANT
     * List view-kaaga tenant specific data fetch panna.
     */
    public function getAllByTenant($tenant_id): array {
        $sql = "SELECT * FROM prescriptions WHERE tenant_id = :tenant_id ORDER BY created_at DESC";
        return $this->db->fetchAll($sql, [':tenant_id' => $tenant_id]);
    }
}