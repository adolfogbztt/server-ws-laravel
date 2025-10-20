<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNaiadeTokenIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken !== env('NAIADE_API_TOKEN')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized, invalid token or missing.',
                'data' => null,
            ], 401);
        }

        return $next($request);
    }
}
