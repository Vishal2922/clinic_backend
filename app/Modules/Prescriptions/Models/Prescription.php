<?php
namespace App\Modules\Prescriptions\Models;

use App\Core\Database;
use PDO;

class Prescription {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function create($data) {
        // Teammate structure padi tenant_id and provider_id (user_id) store aagum
        $sql = "INSERT INTO prescriptions (tenant_id, appointment_id, patient_id, provider_id, medicines, notes, status) 
                VALUES (:tenant_id, :appointment_id, :patient_id, :provider_id, :medicines, :notes, 'pending')";
        
        $stmt = $this->db->prepare($sql);
        
        $stmt->execute([
            ':tenant_id'      => $data['tenant_id'],
            ':appointment_id' => $data['appointment_id'],
            ':patient_id'     => $data['patient_id'],
            ':provider_id'    => $data['provider_id'],
            ':medicines'      => $data['medicines'], // Encrypted String
            ':notes'          => $data['notes'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }

    public function findById($id, $tenant_id) {
        // Multi-tenancy check: User-oda tenant_id match aaganaum
        $stmt = $this->db->prepare("SELECT * FROM prescriptions WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute([':id' => $id, ':tenant_id' => $tenant_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Role-based update logic: Pharmacist dispense pannumbothu avaroda ID-yum save aagum
    public function updateStatus($id, $tenant_id, $status, $pharmacist_id = null) {
        $sql = "UPDATE prescriptions SET 
                status = :status, 
                pharmacist_id = :pharmacist_id,
                updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id AND tenant_id = :tenant_id";
        
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([
            ':status'        => $status,
            ':pharmacist_id' => $pharmacist_id, // Who dispensed it
            ':id'            => $id,
            ':tenant_id'     => $tenant_id
        ]);
    }
}