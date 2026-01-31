<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TeamAnalyticsService
{
    /**
     * Get overview statistics for the team.
     */
    public function getOverviewStats(Team $team): array
    {
        // 1. Task Counts
        $taskCounts = $team->projects()
            ->join('tasks', 'projects.id', '=', 'tasks.project_id')
            ->selectRaw("
                count(*) as total,
                sum(case when tasks.status = 'completed' then 1 else 0 end) as completed,
                sum(case when tasks.status = 'in_progress' then 1 else 0 end) as in_progress,
                sum(case when tasks.status = 'pending' then 1 else 0 end) as pending,
                sum(case when tasks.status != 'completed' and tasks.due_date < ? then 1 else 0 end) as overdue
            ", [now()])
            ->first();

        // 2. Adherence Rate (On-time completion)
        // Formula: (Tasks completed on or before due date / Total completed tasks with due date) * 100
        $completedWithDueDate = $team->projects()
            ->join('tasks', 'projects.id', '=', 'tasks.project_id')
            ->where('tasks.status', 'completed')
            ->whereNotNull('tasks.due_date')
            ->count();

        $completedOnTime = $team->projects()
            ->join('tasks', 'projects.id', '=', 'tasks.project_id')
            ->where('tasks.status', 'completed')
            ->whereNotNull('tasks.due_date')
            ->whereColumn('tasks.completed_at', '<=', 'tasks.due_date')
            ->count();

        $adherenceRate = $completedWithDueDate > 0 ? round(($completedOnTime / $completedWithDueDate) * 100, 1) : 0;

        // 3. Average Cycle Time (in days)
        // Time from 'in_progress' (or created_at if generic) to 'completed_at'
        // For simplicity using created_at to completed_at for now as start_date might be null
        $completedTasks = $team->projects()
            ->join('tasks', 'projects.id', '=', 'tasks.project_id')
            ->where('tasks.status', 'completed')
            ->whereNotNull('tasks.completed_at')
            ->get(['tasks.created_at', 'tasks.completed_at']);

        $avgCycleTime = $completedTasks->avg(function ($task) {
            // Ensure dates are Carbon instances (casts should handle this, but being safe)
            return \Carbon\Carbon::parse($task->completed_at)->diffInHours($task->created_at);
        });
        
        $avgCycleDays = $avgCycleTime ? round($avgCycleTime / 24, 1) : 0;

        // 4. Tasks Due This Week
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();
        
        $dueThisWeek = $team->projects()
            ->join('tasks', 'projects.id', '=', 'tasks.project_id')
            ->whereBetween('tasks.due_date', [$startOfWeek, $endOfWeek])
            ->where('tasks.status', '!=', 'completed')
            ->count();

        return [
            'total_tasks' => $taskCounts->total,
            'completed_tasks' => $taskCounts->completed,
            'overdue_tasks' => $taskCounts->overdue,
            'adherence_rate' => $adherenceRate,
            'avg_cycle_time_days' => $avgCycleDays,
            'due_this_week' => $dueThisWeek,
            'active_projects_count' => $team->projects()->where('status', 'active')->count(),
        ];
    }

    /**
     * Get statistics per member.
     */
    public function getMemberStats(Team $team): array
    {
        $members = $team->members;
        
        $stats = [];
        
        foreach ($members as $member) {
            // Get user's tasks within this team
            $query = Task::query()
                ->whereIn('project_id', $team->projects()->pluck('projects.id'))
                ->where('assigned_to', $member->id);

            $totalAssigned = (clone $query)->count();
            $completed = (clone $query)->where('status', 'completed')->count();
            
            // Overdue: Not completed + Due date passed
            $overdue = (clone $query)
                ->where('status', '!=', 'completed')
                ->where('due_date', '<', now())
                ->count();
                
            // Member Adherence
            $completedWithDue = (clone $query)
                ->where('status', 'completed')
                ->whereNotNull('due_date')
                ->count();
                
            $completedOnTime = (clone $query)
                ->where('status', 'completed')
                ->whereNotNull('due_date')
                ->whereColumn('completed_at', '<=', 'due_date')
                ->count();

            $adherence = $completedWithDue > 0 ? round(($completedOnTime / $completedWithDue) * 100, 1) : 0;

            // Rejection Rate
            // Formula: (Failed QA Reviews / Total QA Reviews) * 100
            // Scope: QA reviews on tasks assigned to this user
            $totalReviews = \App\Models\TaskQaReview::whereHas('task', function ($q) use ($member, $team) {
                 $q->where('assigned_to', $member->id)
                   ->whereIn('project_id', $team->projects()->pluck('projects.id'));
            })->count();

            $failedReviews = \App\Models\TaskQaReview::whereHas('task', function ($q) use ($member, $team) {
                 $q->where('assigned_to', $member->id)
                   ->whereIn('project_id', $team->projects()->pluck('projects.id'));
            })->where('status', 'failed')->count();

            $rejectionRate = $totalReviews > 0 ? round(($failedReviews / $totalReviews) * 100, 1) : 0;

            $stats[] = [
                'user' => [
                    'id' => $member->public_id,
                    'name' => $member->name,
                    'avatar_url' => $member->avatar_url,
                    'initials' => $member->initials,
                ],
                'role' => $member->pivot->role, // Team role
                'total_assigned' => $totalAssigned,
                'completed' => $completed,
                'overdue' => $overdue,
                'adherence_rate' => $adherence,
                'rejection_rate' => $rejectionRate,
            ];
        }

        // Sort by most tasks assigned for now
        usort($stats, fn($a, $b) => $b['total_assigned'] <=> $a['total_assigned']);

        return $stats;
    }
}
