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

//-----------------------------------------------------------// 

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // 1. User login pannirukangala nu check panrom
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated. Login pannunga vro!'], 401);
        }

        // 2. User-oda role namma allow panna roles-la irukka nu check panrom
        // Example: Controller-la 'role:admin,staff' nu kudutha, 
        // intha array-la 'admin', 'staff' rendu perukum access kidaikkum.
        if (!in_array($request->user()->role, $roles)) {
            return response()->json([
                'message' => 'Access Denied. Ungalukku intha action panna permission illa.',
                'your_role' => $request->user()->role
            ], 403);
        }

        return $next($request);
    }
}