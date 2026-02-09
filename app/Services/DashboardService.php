<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;

class DashboardService
{
    /**
     * Get all dashboard data for a user.
     *
     * @return array<string, mixed>
     */
    public function getDashboard(User $user, ?Team $team = null, string $period = 'week', ?string $projectPublicId = null): array
    {
        $project = null;
        if ($projectPublicId) {
            $project = Project::where('public_id', $projectPublicId)->first();
        }

        return [
            'stats' => $this->getStats($user, $team, $project),
            'features' => $this->getFeatureFlags($user, $team),
            'financial' => $this->getFinancialStats($user, $team, $project),
            'task_detail' => $this->getDetailedTaskStats($user, $team, $project),
            'activity' => $this->getActivityFeed($user, $team, 5, $project),
            'projects' => $this->getProjectSummary($user, $team, 4),
            'charts' => $this->getChartData($user, $team, $period, $project),
        ];
    }

    /**
     * Get feature-based statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStats(User $user, ?Team $team = null, ?Project $project = null): array
    {
        $stats = [];
        $features = $this->getFeatureFlags($user, $team);

        // Projects stat
        if ($features['projects_enabled'] && ! $project) {
            $stats[] = $this->getProjectStats($user, $team);
        }

        // Tasks stat
        if ($features['tasks_enabled']) {
            $stats[] = $this->getTaskStats($user, $team, $project);
        }

        // Tickets stat
        if ($features['tickets_enabled'] && ! $project) {
            $stats[] = $this->getTicketStats($user, $team);
        }

        // Team members stat
        if ($team && ! $project) {
            $stats[] = $this->getTeamMemberStats($team);
        }

        return $stats;
    }

    /**
     * Get feature flags based on user permissions.
     *
     * @return array<string, bool>
     */
    public function getFeatureFlags(User $user, ?Team $team = null): array
    {
        return [
            'projects_enabled' => $user->can('projects.view') || $user->can('projects.view_assigned'),
            'tickets_enabled' => $user->can('tickets.view'),
            'tasks_enabled' => $user->can('tasks.view') || $user->can('tasks.view_assigned'),
            'invoices_enabled' => $user->can('invoices.view'),
            'is_demo_mode' => config('app.is_demo_mode', false),
        ];
    }

    /**
     * Get project statistics.
     *
     * @return array<string, mixed>
     */
    protected function getProjectStats(User $user, ?Team $team = null): array
    {
        $query = Project::query();

        if ($team) {
            $query->where('team_id', $team->id);
        }

        // Filter by user access if not admin
        if (! $user->can('projects.view')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        $currentCount = $query->clone()->whereNull('archived_at')->count();

        // Calculate change from last month
        $lastMonthCount = $query->clone()
            ->whereNull('archived_at')
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $change = $this->calculateChange($currentCount, $lastMonthCount);

        return [
            'id' => 'projects',
            'label' => 'Active Projects',
            'value' => (string) $currentCount,
            'change' => $change['formatted'],
            'change_value' => $change['value'],
            'trend' => $change['trend'],
            'icon' => 'folder-kanban',
            'color' => 'from-blue-500 to-blue-600',
        ];
    }

    /**
     * Get task statistics.
     *
     * @return array<string, mixed>
     */
    protected function getTaskStats(User $user, ?Team $team = null, ?Project $project = null): array
    {
        $query = Task::query();

        if ($project) {
            $query->where('project_id', $project->id);
        } elseif ($team) {
            $query->whereHas('project', fn ($q) => $q->where('team_id', $team->id));
        }

        // Filter by user access if not admin
        if (! $user->can('tasks.view')) {
            $query->where('assigned_to', $user->id);
        }

        $currentCount = $query->clone()
            ->whereNotIn('status', ['completed', 'archived'])
            ->count();

        $lastMonthCount = $query->clone()
            ->whereNotIn('status', ['completed', 'archived'])
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $change = $this->calculateChange($currentCount, $lastMonthCount);

        return [
            'id' => 'tasks',
            'label' => 'Active Tasks',
            'value' => (string) $currentCount,
            'change' => $change['formatted'],
            'change_value' => $change['value'],
            'trend' => $change['trend'],
            'icon' => 'clock',
            'color' => 'from-purple-500 to-purple-600',
        ];
    }

    /**
     * Get ticket statistics.
     *
     * @return array<string, mixed>
     */
    protected function getTicketStats(User $user, ?Team $team = null): array
    {
        $query = Ticket::query();

        // Filter by team if provided
        if ($team) {
            $query->where('team_id', $team->id);
        }

        // Filter by user access if not admin
        if (! $user->can('tickets.view')) {
            $query->where(function ($q) use ($user) {
                $q->where('reporter_id', $user->id)
                    ->orWhere('assigned_to', $user->id);
            });
        }

        $currentCount = $query->clone()
            ->whereIn('status', ['open', 'in_progress', 'pending'])
            ->count();

        $lastMonthCount = $query->clone()
            ->whereIn('status', ['open', 'in_progress', 'pending'])
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $change = $this->calculateChange($currentCount, $lastMonthCount);

        return [
            'id' => 'tickets',
            'label' => 'Open Tickets',
            'value' => (string) $currentCount,
            'change' => $change['formatted'],
            'change_value' => $change['value'],
            'trend' => $change['trend'],
            'icon' => 'ticket',
            'color' => 'from-orange-500 to-orange-600',
        ];
    }

    /**
     * Get team member statistics.
     *
     * @return array<string, mixed>
     */
    protected function getTeamMemberStats(Team $team): array
    {
        $currentCount = $team->members()->count();

        // Members added this month
        $addedThisMonth = $team->members()
            ->wherePivot('joined_at', '>=', now()->startOfMonth())
            ->count();

        return [
            'id' => 'members',
            'label' => 'Team Members',
            'value' => (string) $currentCount,
            'change' => $addedThisMonth > 0 ? "+{$addedThisMonth}" : '0',
            'change_value' => $addedThisMonth,
            'trend' => $addedThisMonth > 0 ? 'up' : 'neutral',
            'icon' => 'users',
            'color' => 'from-emerald-500 to-emerald-600',
        ];
    }

    /**
     * Get recent activity feed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActivityFeed(User $user, ?Team $team = null, int $limit = 10, ?Project $project = null): array
    {
        $actions = [
            \App\Enums\AuditAction::Created,
            \App\Enums\AuditAction::Updated,
            \App\Enums\AuditAction::Deleted,
        ];

        $auditableTypes = [
            'App\\Models\\Project',
            'App\\Models\\Task',
        ];

        if ($user->can('tickets.view')) {
            $actions = array_merge($actions, [
                \App\Enums\AuditAction::TicketCreated,
                \App\Enums\AuditAction::TicketUpdated,
                \App\Enums\AuditAction::TicketAssigned,
            ]);
            $auditableTypes[] = 'App\\Models\\Ticket';
        }

        $query = AuditLog::query()
            ->with('user:id,public_id,name')
            ->whereIn('action', $actions)
            ->whereIn('auditable_type', $auditableTypes)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($project) {
            $query->where(function ($q) use ($project) {
                // If the audit is for the project itself
                $q->where(function ($sq) use ($project) {
                    $sq->where('auditable_type', 'App\\Models\\Project')
                        ->where('auditable_id', $project->id);
                })
                // Or if it's for a task in this project
                    ->orWhere(function ($sq) use ($project) {
                        $sq->where('auditable_type', 'App\\Models\\Task')
                            ->whereIn('auditable_id', fn ($q) => $q->select('id')->from('tasks')->where('project_id', $project->id));
                    });
            });
        } elseif ($team) {
            $query->where('team_id', $team->id);
        }

        $logs = $query->get();

        return $logs->map(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'user' => [
                    'name' => $log->user?->name ?? 'System',
                    'avatar_url' => $log->user?->avatar_url,
                    'initials' => $log->user?->initials ?? 'S',
                ],
                'action' => $this->formatAction($log->action, $log->auditable_type),
                'target' => $log->metadata['name'] ?? $log->metadata['title'] ?? class_basename($log->auditable_type),
                'target_type' => strtolower(class_basename($log->auditable_type)),
                'target_id' => $log->auditable_id,
                'time' => $log->created_at->diffForHumans(),
                'timestamp' => $log->created_at->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Get project summary for dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProjectSummary(User $user, ?Team $team = null, int $limit = 4): array
    {
        $query = Project::query()
            ->with(['members:id,public_id,name'])
            ->whereNull('archived_at')
            ->orderBy('updated_at', 'desc');

        if ($team) {
            $query->where('team_id', $team->id);
        }

        if (! $user->can('projects.view')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->limit($limit)->get()->map(function (Project $project) {
            return [
                'id' => $project->public_id,
                'name' => $project->name,
                'progress' => $project->progress_percentage,
                'status' => [
                    'value' => $project->status->value,
                    'label' => $project->status->label(),
                ],
                'member_count' => $project->members->count(),
                'due_date' => $project->due_date?->toDateString(),
                'is_overdue' => $project->is_overdue,
            ];
        })->toArray();
    }

    /**
     * Get chart data for dashboard.
     *
     * @return array<string, mixed>
     */
    public function getChartData(User $user, ?Team $team = null, string $period = 'week', ?Project $project = null): array
    {
        $dates = $this->getDateRange($period);

        return [
            'activity' => $this->getActivityChartData($user, $team, $dates, $project),
            'project_status' => $project ? null : $this->getProjectStatusChartData($user, $team),
            'ticket_trends' => $project ? null : $this->getTicketTrendsChartData($user, $team, $period),
        ];
    }

    /**
     * Get activity chart data (tasks and tickets created over time).
     *
     * @param  array<string>  $dates
     * @return array<string, mixed>
     */
    protected function getActivityChartData(User $user, ?Team $team, array $dates, ?Project $project = null): array
    {
        $labels = [];
        $tasksData = [];
        $ticketsData = [];

        $daysCount = count($dates);

        foreach ($dates as $date) {
            $carbon = Carbon::parse($date);

            if ($daysCount <= 7) {
                $labels[] = $carbon->format('D');
            } elseif ($daysCount <= 31) {
                $labels[] = $carbon->day % 5 === 0 ? $carbon->format('M d') : '';
            } else {
                $labels[] = $carbon->day === 1 ? $carbon->format('M d') : '';
            }

            // Count tasks created on this day
            $taskQuery = Task::whereDate('created_at', $date);
            if ($project) {
                $taskQuery->where('project_id', $project->id);
            } elseif ($team) {
                $taskQuery->whereHas('project', fn ($q) => $q->where('team_id', $team->id));
            }
            $tasksData[] = $taskQuery->count();

            // Count tickets created on this day
            $ticketQuery = Ticket::whereDate('created_at', $date);

            if ($team) {
                $ticketQuery->where('team_id', $team->id);
            }

            if ($project || ! $user->can('tickets.view')) {
                // Tickets only shown for support capable users if not project-scoped
                $ticketsData[] = 0;
            } else {
                $ticketsData[] = $ticketQuery->count();
            }
        }

        $datasets = [
            [
                'label' => 'Tasks',
                'data' => $tasksData,
                'borderColor' => 'rgb(139, 92, 246)',
                'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
            ],
        ];

        if (! $project && $user->can('tickets.view')) {
            $datasets[] = [
                'label' => 'Tickets',
                'data' => $ticketsData,
                'borderColor' => 'rgb(249, 115, 22)',
                'backgroundColor' => 'rgba(249, 115, 22, 0.1)',
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Get project status distribution chart data.
     *
     * @return array<string, mixed>
     */
    protected function getProjectStatusChartData(User $user, ?Team $team): array
    {
        $query = Project::query()->whereNull('archived_at');

        if ($team) {
            $query->where('team_id', $team->id);
        }

        if (! $user->can('projects.view')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        // Get raw counts by status - process each result to handle enum properly
        $results = $query->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->get();

        // Build status counts with string keys (handle both enum objects and string values)
        $statusCounts = [];
        foreach ($results as $result) {
            $statusKey = $result->status instanceof \App\Enums\ProjectStatus
                ? $result->status->value
                : (string) $result->status;
            $statusCounts[$statusKey] = $result->count;
        }

        // Use the enum to get proper display values
        $statusConfig = [
            'draft' => ['label' => 'Draft', 'color' => 'rgb(156, 163, 175)'],
            'active' => ['label' => 'Active', 'color' => 'rgb(59, 130, 246)'],
            'on_hold' => ['label' => 'On Hold', 'color' => 'rgb(245, 158, 11)'],
            'completed' => ['label' => 'Completed', 'color' => 'rgb(34, 197, 94)'],
        ];

        $labels = [];
        $data = [];
        $backgroundColors = [];

        foreach ($statusConfig as $statusValue => $config) {
            if (isset($statusCounts[$statusValue]) && $statusCounts[$statusValue] > 0) {
                $labels[] = $config['label'];
                $data[] = $statusCounts[$statusValue];
                $backgroundColors[] = $config['color'];
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'backgroundColor' => $backgroundColors,
        ];
    }

    /**
     * Get ticket trends chart data.
     *
     * @return array<string, mixed>
     */
    protected function getTicketTrendsChartData(User $user, ?Team $team, string $period): array
    {
        if (! $user->can('tickets.view')) {
            return [
                'labels' => [],
                'datasets' => [],
            ];
        }

        $days = match ($period) {
            '30d' => 30,
            '60d' => 60,
            '90d' => 90,
            'month' => 30,
            'year' => 365,
            default => 7,
        };

        // If range is large, aggregate by week
        $useWeeks = $days > 7;
        $intervals = $useWeeks ? (int) ceil($days / 7) : $days;

        $labels = [];
        $openedData = [];
        $closedData = [];

        for ($i = $intervals - 1; $i >= 0; $i--) {
            if ($useWeeks) {
                $startDate = now()->subWeeks($i)->startOfWeek();
                $endDate = now()->subWeeks($i)->endOfWeek();
                $labels[] = 'W'.(now()->subWeeks($i)->weekOfYear);
            } else {
                $startDate = now()->subDays($i)->startOfDay();
                $endDate = now()->subDays($i)->endOfDay();
                $labels[] = $startDate->format('D');
            }

            $openedQuery = Ticket::whereBetween('created_at', [$startDate, $endDate]);
            $closedQuery = Ticket::where('status', 'closed')
                ->whereBetween('updated_at', [$startDate, $endDate]);

            if ($team) {
                $openedQuery->where('team_id', $team->id);
                $closedQuery->where('team_id', $team->id);
            }

            $openedData[] = $openedQuery->count();
            $closedData[] = $closedQuery->count();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Opened',
                    'data' => $openedData,
                    'backgroundColor' => 'rgba(249, 115, 22, 0.8)',
                ],
                [
                    'label' => 'Closed',
                    'data' => $closedData,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.8)',
                ],
            ],
        ];
    }

    /**
     * Get financial statistics for the dashboard.
     *
     * @return array<string, mixed>|null
     */
    protected function getFinancialStats(User $user, ?Team $team = null, ?Project $project = null): ?array
    {
        if (! $user->can('invoices.view') || ! $team) {
            return null;
        }

        $query = Invoice::where('team_id', $team->id);

        if ($project) {
            $query->where('project_id', $project->id);
        }

        $collected = $query->clone()
            ->where('status', InvoiceStatus::Paid)
            ->sum('total');

        $pending = $query->clone()
            ->whereIn('status', [
                InvoiceStatus::Sent,
                InvoiceStatus::Viewed,
                InvoiceStatus::Overdue,
            ])
            ->sum('total');

        return [
            'collected' => [
                'label' => 'Total Collected',
                'value' => number_format($collected, 2),
                'raw' => $collected,
                'currency' => $team->currency ?? 'USD',
            ],
            'pending' => [
                'label' => 'Pending Payments',
                'value' => number_format($pending, 2),
                'raw' => $pending,
                'currency' => $team->currency ?? 'USD',
            ],
        ];
    }

    /**
     * Get detailed task breakdown.
     *
     * @return array<string, mixed>|null
     */
    protected function getDetailedTaskStats(User $user, ?Team $team = null, ?Project $project = null): ?array
    {
        if (! $user->can('tasks.view') && ! $user->can('tasks.view_assigned')) {
            return null;
        }

        $query = Task::query();

        if ($project) {
            $query->where('project_id', $project->id);
        } elseif ($team) {
            $query->whereHas('project', fn ($q) => $q->where('team_id', $team->id));
        }

        if (! $user->can('tasks.view')) {
            $query->where('assigned_to', $user->id);
        }

        $completed = $query->clone()->where('status', 'completed')->count();
        $inProgress = $query->clone()->where('status', 'in_progress')->count();
        $pastDue = $query->clone()
            ->whereNotIn('status', ['completed', 'archived'])
            ->where('due_date', '<', now())
            ->whereNotNull('due_date')
            ->count();
        $total = $query->clone()->where('status', '!=', 'archived')->count();

        return [
            'completed' => [
                'label' => 'Completed',
                'count' => $completed,
                'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
            ],
            'in_progress' => [
                'label' => 'In Progress',
                'count' => $inProgress,
                'percentage' => $total > 0 ? round(($inProgress / $total) * 100) : 0,
            ],
            'past_due' => [
                'label' => 'Past Due',
                'count' => $pastDue,
                'percentage' => $total > 0 ? round(($pastDue / $total) * 100) : 0,
            ],
            'total' => $total,
        ];
    }

    /**
     * Calculate percentage change between two values.
     *
     * @return array<string, mixed>
     */
    protected function calculateChange(int $current, int $previous): array
    {
        if ($previous === 0) {
            $changeValue = $current > 0 ? 100 : 0;
        } else {
            $changeValue = (int) round((($current - $previous) / $previous) * 100);
        }

        $trend = $changeValue > 0 ? 'up' : ($changeValue < 0 ? 'down' : 'neutral');
        $formatted = ($changeValue >= 0 ? '+' : '').$changeValue.'%';

        return [
            'value' => $changeValue,
            'formatted' => $formatted,
            'trend' => $trend,
        ];
    }

    /**
     * Get date range for chart period.
     *
     * @return array<string>
     */
    protected function getDateRange(string $period): array
    {
        $dates = [];
        $days = match ($period) {
            'week' => 7,
            '30d' => 30,
            '60d' => 60,
            '90d' => 90,
            'month' => 30,
            'year' => 365,
            default => 7,
        };

        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = now()->subDays($i)->toDateString();
        }

        return $dates;
    }

    /**
     * Format action string for display.
     */
    protected function formatAction(\App\Enums\AuditAction $action, string $type): string
    {
        $typeLabel = strtolower(class_basename($type));

        return match ($action) {
            \App\Enums\AuditAction::Created => "created {$typeLabel}",
            \App\Enums\AuditAction::Updated => "updated {$typeLabel}",
            \App\Enums\AuditAction::Deleted => "deleted {$typeLabel}",
            \App\Enums\AuditAction::TicketCreated => "created {$typeLabel}",
            \App\Enums\AuditAction::TicketUpdated => "updated {$typeLabel}",
            \App\Enums\AuditAction::TicketAssigned => "was assigned {$typeLabel}",
            default => "{$action->value} {$typeLabel}",
        };
    }
}
