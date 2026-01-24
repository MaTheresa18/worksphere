<?php

namespace App\Services;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\Cache;

class SystemEmailService
{
    /**
     * Get the email account for a specific system usage.
     * Use simple caching to avoid DB hits on every email sent.
     */
    public function getAccountForUsage(string $usage): ?EmailAccount
    {
        // Cache key per usage type
        $cacheKey = "system_email_account_{$usage}";

        return Cache::remember($cacheKey, 600, function () use ($usage) {
            return EmailAccount::query()
                ->where('is_system', true)
                ->where('system_usage', $usage)
                ->where('is_active', true)
                ->first();
        });
    }

    /**
     * Clear cache for a specific usage or all.
     */
    public function clearCache(?string $usage = null): void
    {
        if ($usage) {
            Cache::forget("system_email_account_{$usage}");
        } else {
            foreach (['support', 'notification', 'noreply'] as $type) {
                Cache::forget("system_email_account_{$type}");
            }
        }
    }

    /**
     * Ensure only one account has this usage if we want uniqueness.
     * This can be called before saving an account.
     */
    public function ensureUniqueUsage(string $usage, ?int $excludeId = null): void
    {
        $query = EmailAccount::query()
            ->where('is_system', true)
            ->where('system_usage', $usage)
            ->where('is_active', true);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // If exists, we might want to deactivate others or throw exception
        // For now, let's just deactivate others to enforce "Single Active" rule
        $others = $query->get();
        foreach ($others as $account) {
            $account->update(['is_active' => false]);
            // Maybe notify user?
        }
    }
}
