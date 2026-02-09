<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBlockedIp
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        // Priority 1: Check Whitelist
        if (\App\Models\WhitelistedIp::where('ip_address', $ip)->exists()) {
            return $next($request);
        }

        // Priority 2: Check Blocklist
        $blockedIp = \App\Models\BlockedIp::where('ip_address', $ip)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($blockedIp) {
            abort(403, 'Your IP address has been blocked. Reason: '.($blockedIp->reason ?? 'No reason provided'));
        }

        return $next($request);
    }
}
