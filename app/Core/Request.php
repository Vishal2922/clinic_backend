<?php
namespace App\Core;

class Request {
    public $params;
    public $user;       
    public $tenant_id =1;  

    public function __construct() {
        // 💡 TESTING-KAGA: Inga roles-ah maathi test pannunga
        $this->user = [
            'id' => 1,
            'role_name' => 'Pharmacist', // 👈 'Provider' or 'Pharmacist' nu mathi test pannunga
            'full_name' => 'Test User'
        ];
    }

    public function getMethod() {
        return strtolower($_SERVER['REQUEST_METHOD']);
    }

    public function getPath() {
        // 1. Get URI (e.g., /prescriptions)
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // 2. Remove query string (?id=1)
        $path = parse_url($uri, PHP_URL_PATH);

        // 3. Clean the path for built-in server
        $path = str_replace('/index.php', '', $path);

        // 4. Standardize format: eppovume '/' starting-la irukanum
        return '/' . ltrim($path, '/');
    }

    public function getBody() {
        if (in_array($this->getMethod(), ['post', 'put', 'patch'])) {
            $input = json_decode(file_get_contents('php://input'), true);
            return is_array($input) ? $input : [];
        }
        return $_GET;
    }
}