<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDemoMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.is_demo_mode', false)) {
            return $next($request);
        }

        // List of methods to block
        $blockedMethods = ['DELETE'];

        // List of sensitive paths or route names to block even for POST/PUT/PATCH
        $blockedPaths = [
            'api/settings*',
            'api/user/password',
            'api/user/email',
            'api/admin/*',
            'api/maintenance/*',
            'api/audit-logs/*',
            'api/system-logs/*',
            'api/database-health*',
            'api/backups/*',
        ];

        // Specific allowed routes that might use POST but are safe
        $allowedRoutes = [
            'api/login',
            'api/logout',
            'api/analytics/track',
            'api/search',
        ];

        if ($this->shouldBlock($request, $blockedMethods, $blockedPaths, $allowedRoutes)) {
            return response()->json([
                'message' => 'Action disabled in Demo Mode.',
                'is_demo' => true,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Determine if the request should be blocked.
     */
    protected function shouldBlock(Request $request, array $methods, array $paths, array $allowed): bool
    {
        // Check if explicitly allowed
        foreach ($allowed as $path) {
            if ($request->is($path)) {
                return false;
            }
        }

        // Block entire methods
        if (in_array($request->method(), $methods)) {
            return true;
        }

        // Block specific paths for mutating methods
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            foreach ($paths as $path) {
                if ($request->is($path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
