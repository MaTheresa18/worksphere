<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SuspiciousActivity;
use Torann\GeoIP\Facades\GeoIP;

class SecurityDashboardService
{
    /**
     * Record suspicious activity from an audit log entry.
     */
    public static function recordSuspiciousActivity(AuditLog $log): void
    {
        $ip = $log->ip_address;
        if (! $ip || $ip === '127.0.0.1') {
            return;
        }

        $type = $log->action->value;

        $location = null;
        try {
            $location = GeoIP::getLocation($ip);
        } catch (\Exception $e) {
            // Fail silently
        }

        SuspiciousActivity::updateOrCreate(
            ['ip_address' => $ip, 'type' => $type],
            [
                'count' => \DB::raw('count + 1'),
                'country_code' => $location?->iso_code,
                'country_name' => $location?->country,
                'city' => $location?->city,
                'latitude' => $location?->lat,
                'longitude' => $location?->lon,
                'last_observed_at' => now(),
                'metadata' => array_merge($log->metadata ?? [], [
                    'last_url' => $log->url,
                    'user_agent' => $log->user_agent,
                ]),
            ]
        );
    }
}
