<?php
namespace App\Modules\Prescriptions\Models;

use App\Core\Database;
use PDO;

class Prescription {
    private $db;

    public function __construct() {
        // 🔥 FIX: 'new' keyword badhula 'getInstance()' use pannanum
        $this->db = Database::getInstance();
    }

    public function create($data) {
        // Namma merge panna Database::insert() method use pannalaam
        $sql = "INSERT INTO prescriptions (tenant_id, appointment_id, patient_id, provider_id, medicine_name, dosage, notes, status) 
                VALUES (:tenant_id, :appointment_id, :patient_id, :provider_id, :medicine_name, :dosage, :notes, 'pending')";
        
        // Service/Controller-la irundhu vara column names-ku match panniyirukkaen
        return $this->db->insert($sql, [
            ':tenant_id'      => $data['tenant_id'],
            ':appointment_id' => $data['appointment_id'],
            ':patient_id'     => $data['patient_id'],
            ':provider_id'    => $data['provider_id'],
            ':medicine_name'  => $data['medicine_name'], // Encrypted in Controller
            ':dosage'         => $data['dosage'],        // Encrypted in Controller
            ':notes'          => $data['notes'] ?? null
        ]);
    }

    public function findById($id, $tenant_id) {
        // Namma merge panna Database::fetch() method
        $sql = "SELECT * FROM prescriptions WHERE id = :id AND tenant_id = :tenant_id";
        return $this->db->fetch($sql, [':id' => $id, ':tenant_id' => $tenant_id]);
    }

    /**
     * Role-based update logic
     */
    public function updateStatus($id, $tenant_id, $updateData) {
        // Service layer-la irundhu array-vaa data varum
        $sql = "UPDATE prescriptions SET 
                status = :status, 
                pharmacist_id = :pharmacist_id,
                dosage = :dosage,
                updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id AND tenant_id = :tenant_id";
        
        // Namma merge panna Database::execute() method
        return $this->db->execute($sql, [
            ':status'        => $updateData['status'],
            ':pharmacist_id' => $updateData['pharmacist_id'] ?? null,
            ':dosage'        => $updateData['dosage'] ?? null, // Encrypted if updated
            ':id'            => $id,
            ':tenant_id'     => $tenant_id
        ]);
    }
}