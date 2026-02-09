<?php

namespace App\Observers;

use App\Models\Task;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        if ($task->project) {
            $task->project->recalculateProgress();
        }

        // Notify assignee if assigned at creation, unless assigned to self
        if ($task->assigned_to && $task->assigned_to !== \Illuminate\Support\Facades\Auth::id()) {
            $task->loadMissing('assignee');
            if ($task->assignee) {
                $task->assignee->notify(new \App\Notifications\TaskNotification(
                    $task,
                    \App\Notifications\TaskNotification::TYPE_ASSIGNED,
                    \Illuminate\Support\Facades\Auth::user()
                ));
            }
        }
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        if ($task->project && $task->wasChanged('status')) {
            $task->project->recalculateProgress();
        }

        // Notify new assignee if assignment changed
        if ($task->wasChanged('assigned_to') && $task->assigned_to && $task->assigned_to !== \Illuminate\Support\Facades\Auth::id()) {
            $task->load('assignee');
            if ($task->assignee) {
                $task->assignee->notify(new \App\Notifications\TaskNotification(
                    $task,
                    \App\Notifications\TaskNotification::TYPE_ASSIGNED,
                    \Illuminate\Support\Facades\Auth::user()
                ));
            }
        }
    }

    /**
     * Handle the Task "deleted" event.
     */
    public function deleted(Task $task): void
    {
        if ($task->project) {
            $task->project->recalculateProgress();
        }
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        if ($task->project) {
            $task->project->recalculateProgress();
        }
    }
}
