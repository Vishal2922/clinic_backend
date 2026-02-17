<?php
namespace App\Core;

use PDO;
use PDOException;

class Database {
    private $host = "127.0.0.1";
    private $port = "3308";          
    private $db_name = "clinic_db";
    private $username = "root";
    private $password = "";         

    public $conn;

       public function getConnection() {
        $this->conn = null;

        try {
            // DSN creation
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name;
            
            // Connection
            $this->conn = new PDO($dsn, $this->username, $this->password);
            
           
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
          
            $this->conn->exec("set names utf8");

        } catch(PDOException $exception) {
           
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Database Connection Failed: " . $exception->getMessage()
            ]);
            exit(); 
        }

        return $this->conn;
    }
}