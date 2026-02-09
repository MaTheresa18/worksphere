<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetUserTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            $timezone = $user->preferences['timezone'] ?? null;

            // We don't change config('app.timezone') here to avoid persistent database writes
            // in local timezones. Timezone conversion is handled at the presentation layer.
            // if ($timezone) {
            //     config(['app.timezone' => $timezone]);
            //     date_default_timezone_set($timezone);
            // }
        }

        return $next($request);
    }
}
