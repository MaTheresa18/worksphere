<?php

namespace App\Models;

use App\Enums\EmailSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class EmailAccount extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    public const TYPE_FULL = 'full';

    public const TYPE_SMTP = 'smtp';

    public function getRouteKeyName()
    {
        return 'public_id';
    }

    protected $fillable = [
        'public_id',
        'user_id',
        'team_id',
        'name',
        'email',
        'provider',
        'auth_type',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'username',
        'password',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_active',
        'is_verified',
        'is_default',
        'is_system',
        'system_usage',
        'last_used_at',
        'last_error',
        'sync_status',
        'initial_sync_completed_at',
        'last_sync_at',
        'last_synced_uid',
        'sync_cursor',
        'sync_error',
        'needs_reauth',
        'consecutive_failures',
        'storage_used',
        'storage_limit',
        'storage_updated_at',
        'account_type',
        // Dual crawler fields
        'forward_uid_cursor',
        'last_forward_sync_at',
        'backfill_uid_cursor',
        'backfill_complete',
        'last_backfill_at',
        'sync_started_at',
        // Folder sync settings
        'disabled_folders',
    ];

    protected $casts = [
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'token_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'initial_sync_completed_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_synced_uid' => 'integer',
        'storage_used' => 'integer',
        'storage_limit' => 'integer',
        'storage_updated_at' => 'datetime',
        'sync_cursor' => 'array',
        'sync_status' => EmailSyncStatus::class,
        'needs_reauth' => 'boolean',
        'consecutive_failures' => 'integer',
        // Dual crawler casts
        'forward_uid_cursor' => 'integer',
        'last_forward_sync_at' => 'datetime',
        'backfill_uid_cursor' => 'integer',
        'backfill_complete' => 'boolean',
        'last_backfill_at' => 'datetime',
        'sync_started_at' => 'datetime',
        // Folder sync settings
        'disabled_folders' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    protected $hidden = [
        'password',
        'access_token',
        'refresh_token',
    ];

    /**
     * Provider configurations.
     */
    public const PROVIDERS = [
        'gmail' => [
            'name' => 'Gmail',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'supports_oauth' => true,
        ],
        'outlook' => [
            'name' => 'Outlook',
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'supports_oauth' => true,
        ],
        'custom' => [
            'name' => 'Custom IMAP/SMTP',
            'supports_oauth' => false,
        ],
        'yahoo' => [
            'name' => 'Yahoo Mail',
            'imap_host' => 'imap.mail.yahoo.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.mail.yahoo.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'supports_oauth' => true,
        ],
        'zoho' => [
            'name' => 'Zoho Mail',
            'imap_host' => 'imap.zoho.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.zoho.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'supports_oauth' => true,
        ],
        'fastmail' => [
            'name' => 'Fastmail',
            'imap_host' => 'imap.fastmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.fastmail.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'supports_oauth' => false,
        ],
        'yandex' => [
            'name' => 'Yandex Mail',
            'imap_host' => 'imap.yandex.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.yandex.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'supports_oauth' => true,
        ],
        'gmx' => [
            'name' => 'GMX',
            'imap_host' => 'imap.gmx.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'mail.gmx.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'supports_oauth' => false,
        ],
        'webde' => [
            'name' => 'Web.de',
            'imap_host' => 'imap.web.de',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.web.de',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'supports_oauth' => false,
        ],
    ];

    // ==================
    // Encrypted Accessors
    // ==================

    public function getPasswordAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getRefreshTokenAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setRefreshTokenAttribute($value): void
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    // ==================
    // Relationships
    // ==================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(EmailSyncLog::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(EmailFolder::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(EmailSignature::class);
    }

    // ==================
    // Scopes
    // ==================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopePersonal($query)
    {
        return $query->whereNotNull('user_id')->whereNull('team_id');
    }

    public function scopeShared($query)
    {
        return $query->whereNotNull('team_id');
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeForUsage($query, string $usage)
    {
        return $query->where('is_system', true)->where('system_usage', $usage);
    }

    public function scopeUserAccounts($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeNeedsSync($query)
    {
        return $query->whereIn('sync_status', [
            EmailSyncStatus::Pending,
            EmailSyncStatus::Seeding,
            EmailSyncStatus::Syncing,
        ]);
    }

    public function scopeSyncCompleted($query)
    {
        return $query->where('sync_status', EmailSyncStatus::Completed);
    }

    // ==================
    // Helpers
    // ==================

    public function isOAuth(): bool
    {
        return $this->auth_type === 'oauth';
    }

    public function isSmtpOnly(): bool
    {
        return $this->account_type === self::TYPE_SMTP;
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return true;
        }

        return $this->token_expires_at->isPast();
    }

    public function needsTokenRefresh(): bool
    {
        if (! $this->isOAuth()) {
            return false;
        }

        // Refresh if token expires in less than 5 minutes
        return ! $this->token_expires_at || $this->token_expires_at->subMinutes(5)->isPast();
    }

    public function markAsVerified(): void
    {
        $this->update([
            'is_verified' => true,
            'last_error' => null,
        ]);
    }

    public function markAsError(string $error): void
    {
        $this->update([
            'is_verified' => false,
            'last_error' => $error,
        ]);
    }

    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Get the provider configuration.
     */
    public function getProviderConfig(): array
    {
        return self::PROVIDERS[$this->provider] ?? self::PROVIDERS['custom'];
    }

    /**
     * Check if this is a personal account (belongs to user).
     */
    public function isPersonal(): bool
    {
        return $this->user_id !== null && $this->team_id === null;
    }

    /**
     * Check if this is a shared account (belongs to team).
     */
    public function isShared(): bool
    {
        return $this->team_id !== null;
    }

    // ==================
    // Dual Crawler Helpers
    // ==================

    /**
     * Check if the forward crawler can run.
     * Allowed during seeding, syncing, or completed status.
     */
    public function canRunForwardCrawler(): bool
    {
        return $this->is_active && $this->is_verified && in_array($this->sync_status, [
            EmailSyncStatus::Seeding,
            EmailSyncStatus::Syncing,
            EmailSyncStatus::Completed,
        ]);
    }

    /**
     * Check if the backfill crawler can run.
     */
    public function canRunBackfillCrawler(): bool
    {
        return $this->is_active && $this->is_verified && ! $this->backfill_complete;
    }

    /**
     * Check if user can see emails (forward cursor has been set).
     */
    public function hasEmailsReady(): bool
    {
        return $this->forward_uid_cursor !== null && $this->forward_uid_cursor > 0;
    }

    /**
     * Get sync progress percentage (based on cursor positions).
     */
    public function getSyncProgressPercent(): int
    {
        if ($this->backfill_complete) {
            return 100;
        }

        $forward = $this->forward_uid_cursor ?? 0;
        $backfill = $this->backfill_uid_cursor ?? 0;

        if ($forward === 0) {
            return 0;
        }

        // Estimate: backfill progress relative to forward cursor
        if ($forward > 0 && $backfill > 0) {
            $filled = $forward - $backfill;

            return min(100, (int) (($filled / $forward) * 100));
        }

        // If only forward set, we have at least some emails
        return 10;
    }
}
