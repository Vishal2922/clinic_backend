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

//-----------------------------------------------------------// 

class Request {
    public $params;
    public $user;       // User details (id, role_name, etc.)
    public $tenant_id;  // Multi-tenancy isolation id

    public function __construct() {
        $this->bootstrapUser();
    }

    /**
     * Role and User Data-vai Payload/JWT-la irunthu extract panra logic
     */
    private function bootstrapUser() {
        $headers = getallheaders();
        
        // Authorization header check
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
                $parts = explode('.', $token);

                if (count($parts) === 3) {
                    // Payload-ah decode panroam (2nd part of JWT)
                    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                    
                    // User information-ah request object-la set panroam
                    $this->user = [
                        'id' => $payload['user_id'] ?? null,
                        'role_name' => $payload['role'] ?? 'Guest', // Inga thaan role access logic start aaguthu
                        'full_name' => $payload['full_name'] ?? 'Unknown'
                    ];
                    $this->tenant_id = $payload['tenant_id'] ?? 1;
                    return;
                }
            }
        }

        // Token illaina, default Guest role (Testing-kaaga neenga manual-ah inga mathikalam)
        $this->user = [
            'id' => null,
            'role_name' => 'Guest',
            'full_name' => 'Guest User'
        ];
        $this->tenant_id = 1;
    }

    /**
     * HTTP Method-ah get panna (GET, POST, PUT, DELETE)
     */
    public function getMethod() {
        return strtolower($_SERVER['REQUEST_METHOD']);
    }

    /**
     * URL Path-ah clean-ah get panna
     */
    public function getPath() {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = str_replace('/index.php', '', $path);
        return '/' . ltrim($path, '/');
    }

    /**
     * Request Body (JSON) or Query Params-ah get panna
     */
    public function getBody() {
        if (in_array($this->getMethod(), ['post', 'put', 'patch'])) {
            $input = json_decode(file_get_contents('php://input'), true);
            return is_array($input) ? $input : [];
        }
        return $_GET;
    }
}