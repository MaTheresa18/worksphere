<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\AuditSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::created(function (AuditLog $log) {
            if ($log->category === AuditCategory::Security || 
                $log->action === AuditAction::LoginFailed ||
                $log->action === AuditAction::RateLimitExceeded) {
                
                try {
                    \App\Services\SecurityDashboardService::recordSuspiciousActivity($log);
                } catch (\Throwable $e) {
                    // Fail silently to avoid interrupting the main flow
                }
            }
        });
    }

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'user_id',
        'user_name',
        'user_email',
        'team_id',
        'action',
        'category',
        'severity',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'url',
        'method',
        'created_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'category' => AuditCategory::class,
            'severity' => AuditSeverity::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Get the user that performed the action.
     *
     * @return BelongsTo<User, AuditLog>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the team context (if any).
     *
     * @return BelongsTo<Team, AuditLog>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the auditable entity.
     *
     * @return MorphTo<Model, AuditLog>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get a human-readable description of the audit log.
     */
    public function getDescriptionAttribute(): string
    {
        $userName = $this->user_name ?? 'System';

        return "{$userName} " . $this->description_body;
    }

    /**
     * Get the body of the description without the user name.
     */
    public function getDescriptionBodyAttribute(): string
    {
        // Handle generic labels for non-CRUD actions
        if (! in_array($this->action, [AuditAction::Updated, AuditAction::Created, AuditAction::Deleted])) {
            return $this->action->label();
        }

        $modelName = $this->auditable_type ? class_basename($this->auditable_type) : 'record';

        if ($this->action === AuditAction::Created) {
            return "Created {$modelName}";
        }

        if ($this->action === AuditAction::Deleted) {
            return "Deleted {$modelName}";
        }

        if ($this->action === AuditAction::Updated && $this->new_values) {
            $changes = [];
            foreach ($this->new_values as $key => $value) {
                // Skip internal or non-human useful fields
                if (in_array($key, ['updated_at', 'created_at', 'id', 'public_id', 'sort_order'])) {
                    continue;
                }

                $oldValue = $this->old_values[$key] ?? null;

                if ($key === 'status') {
                    $from = $oldValue ? ucwords(str_replace('_', ' ', is_string($oldValue) ? $oldValue : '')) : null;
                    $to = ucwords(str_replace('_', ' ', is_string($value) ? $value : ''));
                    $changes[] = "status from " . ($from ?: 'Open') . " to " . $to;
                } elseif ($key === 'priority') {
                    $changes[] = "priority to " . (is_string($value) ? ucwords($value) : $value);
                } elseif ($key === 'assigned_to') {
                    $name = $this->metadata['assignee_name'] ?? 'someone';
                    $changes[] = "assignee to {$name}";
                } elseif ($key === 'qa_user_id') {
                    $name = $this->metadata['qa_name'] ?? 'someone';
                    $changes[] = "QA reviewer to {$name}";
                } else {
                    $label = str_replace('_', ' ', $key);
                    $changes[] = "{$label}";
                }
            }

            if (! empty($changes)) {
                return "Updated " . implode(', ', $changes);
            }
        }

        return $this->action->label() . " {$modelName}";
    }

    /**
     * Get the formatted timestamp.
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->created_at?->format('M j, Y g:i A') ?? '';
    }

    /**
     * Get the relative time.
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at?->diffForHumans() ?? '';
    }

    /**
     * Convert to array suitable for export.
     *
     * @return array<string, mixed>
     */
    public function toExportArray(): array
    {
        return [
            'id' => $this->public_id,
            'timestamp' => $this->created_at?->toIso8601String(),
            'user' => $this->user_name,
            'email' => $this->user_email,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'entity_type' => $this->auditable_type ? class_basename($this->auditable_type) : null,
            'entity_id' => $this->auditable_id,
            'ip_address' => $this->ip_address,
            'url' => $this->url,
            'method' => $this->method,
            'changes' => [
                'old' => $this->old_values,
                'new' => $this->new_values,
            ],
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Scope: Filter by action.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeAction(Builder $query, AuditAction|string $action): Builder
    {
        $value = $action instanceof AuditAction ? $action->value : $action;

        return $query->where('action', $value);
    }

    /**
     * Scope: Filter by category.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeCategory(Builder $query, AuditCategory|string $category): Builder
    {
        $value = $category instanceof AuditCategory ? $category->value : $category;

        return $query->where('category', $value);
    }

    /**
     * Scope: Filter by severity.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeSeverity(Builder $query, AuditSeverity|string $severity): Builder
    {
        $value = $severity instanceof AuditSeverity ? $severity->value : $severity;

        return $query->where('severity', $value);
    }

    /**
     * Scope: Filter by minimum severity.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeMinSeverity(Builder $query, AuditSeverity $severity): Builder
    {
        $severities = collect(AuditSeverity::cases())
            ->filter(fn (AuditSeverity $s) => $s->numericLevel() >= $severity->numericLevel())
            ->map(fn (AuditSeverity $s) => $s->value)
            ->values()
            ->toArray();

        return $query->whereIn('severity', $severities);
    }

    /**
     * Scope: Filter by user.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeByUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Filter by team.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeByTeam(Builder $query, Team|int $team): Builder
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return $query->where('team_id', $teamId);
    }

    /**
     * Scope: Filter by auditable model.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForModel(Builder $query, Model $model): Builder
    {
        return $query
            ->where('auditable_type', get_class($model))
            ->where('auditable_id', $model->getKey());
    }

    /**
     * Scope: Filter by date range.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeDateBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope: Filter by today's logs.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope: Search in user name, email, or IP.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('user_name', 'like', "%{$term}%")
                ->orWhere('user_email', 'like', "%{$term}%")
                ->orWhere('ip_address', 'like', "%{$term}%");
        });
    }
}
