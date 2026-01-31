<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\SuspiciousActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torann\GeoIP\Facades\GeoIP;

class SecurityDashboardController extends Controller
{
    /**
     * Get security statistics.
     */
    public function stats()
    {
        $this->authorize('viewAny', BlockedIp::class); // Ensure user has permission (e.g., admin)

        $blockedIpsCount = BlockedIp::whereNull('expires_at')
            ->orWhere('expires_at', '>', now())
            ->count();

        $bannedUsersCount = User::where('status', 'banned')->count();
        $suspendedUsersCount = User::where('status', 'suspended')->count();

        // Count security incidents today
        $incidentsToday = AuditLog::whereDate('created_at', today())
            ->whereIn('action', [
                AuditAction::LoginFailed,
                AuditAction::RateLimitExceeded,
                AuditAction::AccountSuspended,
                AuditAction::AccountBanned,
                AuditAction::ForceDeleted,
            ])
            ->count();

        return response()->json([
            'blocked_ips' => $blockedIpsCount,
            'banned_users' => $bannedUsersCount,
            'suspended_users' => $suspendedUsersCount,
            'incidents_today' => $incidentsToday,
        ]);
    }

    /**
     * Get recent security activity.
     */
    public function activity(Request $request)
    {
        $this->authorize('viewAny', BlockedIp::class);

        $limit = $request->integer('limit', 20);

        $logs = AuditLog::with(['user'])
            ->whereIn('category', [AuditCategory::Security, AuditCategory::Authentication])
            ->orWhereIn('action', [
                AuditAction::LoginFailed,
                AuditAction::RateLimitExceeded,
                AuditAction::AccountSuspended,
                AuditAction::AccountBanned,
            ])
            ->latest()
            ->paginate($limit);

        return response()->json($logs);
    }

    /**
     * Get blocked IPs list.
     */
    public function blockedIps(Request $request)
    {
        $this->authorize('viewAny', BlockedIp::class);

        $ips = BlockedIp::with('blockedBy')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($ips);
    }

    /**
     * Block an IP.
     */
    public function blockIp(Request $request)
    {
        $this->authorize('create', BlockedIp::class);

        $validated = $request->validate([
            'ip_address' => 'required|ip|unique:blocked_ips,ip_address',
            'reason' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $blockedIp = BlockedIp::create([
            'ip_address' => $validated['ip_address'],
            'reason' => $validated['reason'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'blocked_by_user_id' => $request->user()->id,
        ]);

        return response()->json($blockedIp, 201);
    }

    /**
     * Unblock an IP.
     */
    public function unblockIp(BlockedIp $blockedIp)
    {
        $this->authorize('delete', $blockedIp);

        $blockedIp->delete();

        return response()->json(['message' => 'IP unblocked successfully']);
    }

    /**
     * Get banned users list.
     */
    public function bannedUsers(Request $request)
    {
        // Assuming 'users.view' or similar permission check happens in Policy or Middleware
         $this->authorize('viewAny', User::class);
        
        $users = User::whereIn('status', ['banned', 'suspended'])
            ->latest('updated_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($users);
    }

    /**
     * Get security chart data.
     */
    public function charts()
    {
        $this->authorize('viewAny', BlockedIp::class);

        // Security Incidents Trend (Last 14 days)
        $days = 14;
        $trendData = AuditLog::where('created_at', '>=', now()->subDays($days))
            ->whereIn('action', [
                AuditAction::LoginFailed,
                AuditAction::RateLimitExceeded,
                AuditAction::AccountSuspended,
                AuditAction::AccountBanned,
            ])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill missing days with 0
        $trend = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $trend[] = [
                'date' => $date,
                'label' => now()->subDays($i)->format('M d'),
                'count' => $trendData[$date] ?? 0,
            ];
        }

        // Incident Distribution (By Action)
        $distributionData = AuditLog::whereIn('action', [
            AuditAction::LoginFailed,
            AuditAction::RateLimitExceeded,
            AuditAction::AccountSuspended,
            AuditAction::AccountBanned,
        ])
        ->select('action', DB::raw('count(*) as count'))
        ->groupBy('action')
        ->get();

        $distribution = $distributionData->map(function ($item) {
            return [
                'label' => $item->action->label(),
                'count' => $item->count,
            ];
        });

        return response()->json([
            'trend' => $trend,
            'distribution' => $distribution,
        ]);
    }

    /**
     * Get suspicious activity map data.
     */
    public function mapData()
    {
        $this->authorize('viewAny', BlockedIp::class);

        $activities = SuspiciousActivity::whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('latitude', 'longitude', 'ip_address', 'country_name', 'city', 'count', 'type')
            ->get()
            ->map(function ($item) {
                return [
                    'lat' => (float) $item->latitude,
                    'lng' => (float) $item->longitude,
                    'ip' => $item->ip_address,
                    'location' => "{$item->city}, {$item->country_name}",
                    'intensity' => min(1, $item->count / 10), // Normalized intensity for heatmap
                    'count' => $item->count,
                    'type' => $item->type,
                ];
            });

        return response()->json($activities);
    }
}
