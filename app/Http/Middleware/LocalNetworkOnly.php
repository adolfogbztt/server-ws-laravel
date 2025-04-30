<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalNetworkOnly
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = [
            '127.0.0.1',
            '::1',
            '192.168.1.0/24'
        ];

        $clientIp = $request->ip();
        
        foreach ($allowedIps as $allowedIp) {
            if ($this->ipMatches($clientIp, $allowedIp)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'This resource is not available.',
            'data' => null
        ], 403);
    }

    /**
     * @param mixed $ip
     * @param mixed $pattern
     * 
     * @return bool
     */
    private function ipMatches($ip, $pattern): bool
    {
        if (str_contains($pattern, '/')) {
            [$subnet, $mask] = explode('/', $pattern);
            return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($subnet);
        }

        return $ip === $pattern;
    }
}
