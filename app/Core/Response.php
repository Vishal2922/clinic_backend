<?php
namespace App\Core;

class Response {
    public static function json($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}


//-----------------------------------------------------------// 

class Response {
    /**
     * Standard JSON Response Method
     * * @param mixed $data - Anuppa vendiya data (Array or Object)
     * @param int $code - HTTP Status Code (Default 200)
     */
    public static function json($data, $code = 200) {
        // 1. HTTP Status Code-ah set panroam (e.g., 200, 401, 403, 404, 500)
        http_response_code($code);

        // 2. Browser/Client-ku idhu oru JSON response-nu solroam
        header('Content-Type: application/json');

        // 3. CORS Settings (Optional: Frontend connect panna use aagum)
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        // 4. Data structure-ah standardize panroam
        $response = [];

        if ($code >= 200 && $code < 300) {
            $response['status'] = 'success';
        } else {
            $response['status'] = 'error';
        }

        // 5. Raw data-vai response array kooda merge panroam
        $response = array_merge($response, (array)$data);

        // 6. JSON-ah encode panni print panroam
        echo json_encode($response);
        
        // 7. Execution-ah stop panroam
        exit;
    }

    /**
     * Simple Success Message Helper
     */
    public static function success($message, $data = [], $code = 200) {
        self::json([
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * Simple Error Message Helper
     */
    public static function error($message, $code = 400, $errors = []) {
        self::json([
            'message' => $message,
            'errors' => $errors
        ], $code);
    }
}