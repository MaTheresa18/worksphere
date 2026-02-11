<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\TaskStatus;
use App\Models\QaCheckTemplate;
use App\Models\Task;
use App\Models\TaskQaReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskWorkflowService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Create a system comment from notes.
     */
    protected function addSystemComment(Task $task, User $user, string $content): void
    {
        \App\Models\TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'content' => $content,
            'is_internal' => false, // Make visible as regular comments
        ]);
    }

    /**
     * Assign a task to a user.
     */
    public function assignTask(Task $task, User $assignee, User $assignedBy): Task
    {
        $previousAssignee = $task->assignee;

        $task->update([
            'assigned_to' => $assignee->id,
            'assigned_by' => $assignedBy->id,
            'assigned_at' => now(),
        ]);

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::TaskManagement,
            $task,
            $assignedBy,
            ['assigned_to' => $previousAssignee?->id],
            ['assigned_to' => $assignee->id],
            ['task_title' => $task->title, 'assignee_name' => $assignee->name]
        );

        return $task->fresh();
    }

    /**
     * Start working on a task (move from Open to InProgress).
     */
    public function startTask(Task $task, User $user): bool
    {
        if (! $task->assigned_to) {
            throw new \Exception('Task must be assigned to a user before it can be started.');
        }

        if (! $task->canTransitionTo(TaskStatus::InProgress)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::InProgress, $user, 'Work started');

        if ($result) {
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'started']
            );
        }

        return $result;
    }

    /**
     * Submit task for QA review.
     */
    public function submitForQa(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->hasAllChecklistItemsComplete()) {
            return false;
        }

        if (! $task->canTransitionTo(TaskStatus::Submitted)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::Submitted, $user, $notes ?? 'Submitted for QA review');

        if ($result) {
            $task->update(['submitted_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'submitted_for_qa']
            );

            if ($notes) {
                $this->addSystemComment($task, $user, "Submitted for QA: $notes");
            }

            // Notify stakeholders suitable for QA submission (e.g. QA Lead, Assignee confirming submission)
            $this->notifyStakeholders($task, \App\Notifications\TaskNotification::TYPE_QA_REVIEW, $user, 'Submitted for QA');
        }

        return $result;
    }

    /**
     * Start QA review for a task.
     */
    public function startQaReview(Task $task, User $reviewer, ?QaCheckTemplate $template = null): ?TaskQaReview
    {
        if (! $task->qa_user_id) {
            throw new \Exception('Task must have a QA user assigned before starting QA review.');
        }

        if (! $task->canTransitionTo(TaskStatus::InQa)) {
            return null;
        }

        return DB::transaction(function () use ($task, $reviewer, $template) {
            $task->transitionTo(TaskStatus::InQa, $reviewer, 'QA review started');

            $review = TaskQaReview::create([
                'task_id' => $task->id,
                'reviewer_id' => $reviewer->id,
                'qa_check_template_id' => $template?->id,
                'status' => 'in_progress',
            ]);

            $this->auditService->log(
                AuditAction::Created,
                AuditCategory::TaskManagement,
                $review,
                $reviewer,
                null,
                null,
                ['task_id' => $task->id, 'task_title' => $task->title]
            );

            return $review;
        });
    }

    /**
     * Complete QA review and approve or reject the task.
     */
    public function completeQaReview(
        TaskQaReview $review,
        array $results,
        User $reviewer,
        bool $approved,
        ?string $notes = null
    ): bool {
        $task = $review->task;
        $targetStatus = $approved ? TaskStatus::PmReview : TaskStatus::Rejected;

        if (! $task->canTransitionTo($targetStatus)) {
            return false;
        }

        return DB::transaction(function () use ($review, $results, $task, $targetStatus, $reviewer, $approved, $notes) {
            // Complete the review with results
            $review->complete($results, $notes);

            // Transition task status
            $statusNotes = $approved ? 'QA approved' : 'QA rejected: '.($notes ?? 'Issues found');
            $task->transitionTo($targetStatus, $reviewer, $statusNotes);

            if ($approved) {
                $task->update(['approved_at' => now()]);
            }

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $reviewer,
                null,
                null,
                [
                    'task_title' => $task->title,
                    'action' => $approved ? 'qa_approved' : 'qa_rejected',
                    'review_notes' => $notes,
                ]
            );

            if ($notes) {
                $action = $approved ? 'QA Approved' : 'QA Rejected';
                $this->addSystemComment($task, $reviewer, "$action: $notes");
            }

            // Notify stakeholders
            $type = $approved ? \App\Notifications\TaskNotification::TYPE_UPDATED : \App\Notifications\TaskNotification::TYPE_REJECTED; // Use UPDATED for PM review move, REJECTED for rejection
            $message = $approved ? 'QA Approved task' : 'QA Rejected task: '.($notes ?? 'Issues found');
            $this->notifyStakeholders($task, $type, $reviewer, $message);

            return true;
        });
    }

    /**
     * Send task to client for review.
     */
    public function sendToClient(Task $task, User $user, ?string $message = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::SentToClient)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::SentToClient, $user, $message ?? 'Sent to client for review');

        if ($result) {
            $task->update(['sent_to_client_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'sent_to_client']
            );

            if ($message) {
                $this->addSystemComment($task, $user, "Sent to Client: $message");
            }

            // TODO: Send notification to client
        }

        return $result;
    }

    /**
     * Record client approval of task.
     */
    public function clientApprove(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::ClientApproved)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::ClientApproved, $user, $notes ?? 'Approved by client');

        if ($result) {
            $task->update(['client_approved_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'client_approved']
            );

            if ($notes) {
                $this->addSystemComment($task, $user, "Client Approved: $notes");
            }
        }

        return $result;
    }

    /**
     * Record client rejection of task.
     */
    public function clientReject(Task $task, User $user, string $reason): bool
    {
        if (! $task->canTransitionTo(TaskStatus::ClientRejected)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::ClientRejected, $user, 'Client rejected: '.$reason);

        if ($result) {
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'client_rejected', 'reason' => $reason]
            );

            $this->addSystemComment($task, $user, "Client Rejected: $reason");

            // Notify stakeholders
            $this->notifyStakeholders(
                $task,
                \App\Notifications\TaskNotification::TYPE_REJECTED,
                $user,
                "Client Rejected task: $reason"
            );
        }

        return $result;
    }

    /**
     * Return task to in progress (after rejection).
     */
    public function returnToProgress(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::InProgress)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::InProgress, $user, $notes ?? 'Returned to in progress');

        if ($result) {
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'returned_to_progress']
            );

            if ($notes) {
                $this->addSystemComment($task, $user, "Returned to Progress: $notes");
            }
        }

        return $result;
    }

    /**
     * Complete a task.
     */
    public function completeTask(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::Completed)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::Completed, $user, $notes ?? 'Task completed');

        if ($result) {
            $task->update(['completed_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'completed']
            );

            if ($notes) {
                $this->addSystemComment($task, $user, "Completed: $notes");
            }
        }

        return $result;
    }

    /**
     * Archive a task.
     */
    public function archiveTask(Task $task, User $user): bool
    {
        if (! $task->canTransitionTo(TaskStatus::Archived)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::Archived, $user, 'Task archived');

        if ($result) {
            $task->update(['archived_at' => now(), 'archived_by' => $user->id]);

            $this->auditService->log(
                AuditAction::Archived,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title]
            );
        }

        return $result;
    }

    /**
     * Toggle On Hold status.
     */
    public function toggleHold(Task $task, User $user, ?string $notes = null): bool
    {
        $targetStatus = null;

        if ($task->status === TaskStatus::OnHold) {
            // Resume: Try to restore previous status from metadata
            $previousStatusValue = $task->metadata['previous_status'] ?? null;
            if ($previousStatusValue) {
                $targetStatus = TaskStatus::tryFrom($previousStatusValue);
            }
            // Fallback to InProgress if no history or invalid
            if (! $targetStatus) {
                $targetStatus = TaskStatus::InProgress;
            }
        } else {
            // Hold: Store current status in metadata before transitioning
            $metadata = $task->metadata ?? [];
            $metadata['previous_status'] = $task->status->value;
            $task->metadata = $metadata;
            $task->saveQuietly(); // Update metadata without triggering observers/timestamps yet

            $targetStatus = TaskStatus::OnHold;
        }

        // Validate transition
        if (! $task->canTransitionTo($targetStatus)) {
            // Fallbacks for Resume if original status is no longer valid
            if ($task->status === TaskStatus::OnHold) {
                if ($task->canTransitionTo(TaskStatus::InProgress)) {
                    $targetStatus = TaskStatus::InProgress;
                } elseif ($task->canTransitionTo(TaskStatus::Open)) {
                    $targetStatus = TaskStatus::Open;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }

        $result = $task->transitionTo($targetStatus, $user, $notes ?? ($targetStatus === TaskStatus::OnHold ? 'Put on hold' : 'Resumed from hold'));

        if ($result && $notes) {
            $action = $targetStatus === TaskStatus::OnHold ? 'Put on Hold' : 'Resumed';
            $this->addSystemComment($task, $user, "$action: $notes");
        }

        if ($result) {
            $type = $targetStatus === TaskStatus::OnHold ? \App\Notifications\TaskNotification::TYPE_ON_HOLD : \App\Notifications\TaskNotification::TYPE_UPDATED;
            $msg = $targetStatus === TaskStatus::OnHold ? 'Task put on hold' : 'Task resumed';
            if ($notes) {
                $msg .= ": $notes";
            }

            $this->notifyStakeholders($task, $type, $user, $msg);
        }

        return $result;
    }

    /**
     * Send task to PM for review.
     */
    /**
     * Send task to PM for review.
     */
    public function sendToPm(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::PmReview)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::PmReview, $user, $notes ?? 'Sent to PM for review');

        if ($result) {
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'sent_to_pm']
            );

            if ($notes) {
                $this->addSystemComment($task, $user, "Sent to PM: $notes");
            }
        }

        return $result;
    }

    /**
     * Helper to notify all stakeholders of a task.
     */
    protected function notifyStakeholders(Task $task, string $type, ?User $actor, string $message): void
    {
        // Collect stakeholders
        $stakeholders = collect([
            $task->assignee,         // Operator
            $task->qaUser,           // QA
            $task->assigner,         // Team Lead/Assigner
            $task->creator,          // Creator
            // Add Project Manager if accessible via relationship
        ])->filter(function (?User $user) use ($actor) {
            // Filter out nulls and the actor themselves
            return $user !== null && $actor && $user->id !== $actor->id;
        })->unique('id');

        // Send notification
        foreach ($stakeholders as $stakeholder) {
            $stakeholder->notify(new \App\Notifications\TaskNotification(
                $task,
                $type,
                $actor,
                $message
            ));
        }
    }

    /**
     * Get available transitions for a task.
     */
    public function getAvailableTransitions(Task $task): array
    {
        return array_map(
            fn (TaskStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            $task->status->allowedTransitions()
        );
    }
}
