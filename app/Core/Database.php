<?php
namespace App\Core;

use PDO;
use PDOException;

class Database {

    // 👇 இங்கே உங்கள் கம்ப்யூட்டர் செட்டிங்ஸ் படி மாற்றிக்கொள்ளுங்கள்
    private $host = "127.0.0.1";
    private $port = "3308";          // XAMPP: 3306, WAMP/MAMP: 8889 or 3308
    private $db_name = "clinic_db"; // நம்ம ப்ராஜெக்ட் Database பெயர்
    private $username = "root";
    private $password = "";          // Password இருந்தால் இங்கே போடவும்

    public $conn;

    // நீங்க கேட்ட மாதிரியே getConnection function
    public function getConnection() {
        $this->conn = null;

        try {
            // DSN creation
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name;
            
            // Connection
            $this->conn = new PDO($dsn, $this->username, $this->password);
            
            // Error வந்தால் Exception Throw பண்ணும்
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Special characters support (Tamil/Emojis)
            $this->conn->exec("set names utf8");

        } catch(PDOException $exception) {
            // Connection Fail ஆனால் JSON Error வரும்
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Database Connection Failed: " . $exception->getMessage()
            ]);
            exit(); // Code இங்கே நின்றுவிடும்
        }

        return $this->conn;
    }
}