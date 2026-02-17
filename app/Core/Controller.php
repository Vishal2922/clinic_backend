<?php

namespace App\Core;

class Controller {
    
    // Constructor: This runs automatically when any controller is called
    public function __construct() {
        // You can add shared logic here later (e.g., checking if user is logged in)
    }

    // Optional Helper: If you want to use $this->json() instead of Response::json()
    protected function json($data, $code = 200) {
        // Assuming you have the Response class in App\Core\Response
        Response::json($data, $code);
    }
}