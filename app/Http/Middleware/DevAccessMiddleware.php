<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevAccessMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check Environment
        if (! app()->environment('local', 'testing')) {
            abort(403, 'Development tools are disabled in this environment.');
        }

        // 2. Optional: Check for specific header or key if configured
        // This adds an extra layer even in local/testing if needed,
        // but primarily we care about blocking production access.

        return $next($request);
    }
}
