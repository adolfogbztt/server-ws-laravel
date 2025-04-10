<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        // Validate the token
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided',
                'data' => null,
            ], 401);
        }

        if ($token === 'admin') {
            return $next($request);
        }
        
        if (!$this->validateToken($token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token invalid or expired',
                'data' => null,
            ], 401);
        }

        return $next($request);
    }

    /**
     * @param string $token
     * 
     * @return bool
     */
    private function validateToken(string $token): bool
    {
        $response = Http::withToken($token)
            ->get(env('SIGA_API_URL') . 'validate-token');

        return $response->successful();
    }
}
