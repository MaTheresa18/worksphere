<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TaskNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    /**
     * Notification types.
     */
    public const TYPE_ASSIGNED = 'task_assigned';
    public const TYPE_UPDATED = 'task_updated';
    public const TYPE_QA_REVIEW = 'task_qa_review';
    public const TYPE_CLIENT_REVIEW = 'task_client_review';
    public const TYPE_COMPLETED = 'task_completed';
    public const TYPE_REJECTED = 'task_rejected';
    public const TYPE_ON_HOLD = 'task_on_hold';

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Task $task,
        public string $type,
        public ?User $actor = null,
        public ?string $message = null,
        public array $metadata = []
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'action_url' => "/teams/{$this->task->project->team->public_id}/projects/{$this->task->project->public_id}?tab=tasks&view=board&task={$this->task->public_id}",
            'action_label' => 'View Task',
            'metadata' => array_merge([
                'task_id' => $this->task->public_id,
                'task_title' => $this->task->title,
                'project_id' => $this->task->project->public_id,
                'project_name' => $this->task->project->name,
                'status' => $this->task->status->label(),
                'priority' => ucfirst($this->task->priority), // Check if enum or int
            ], $this->metadata),
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
            'type' => self::class,
        ]);
    }

    /**
     * Get the notification title based on type.
     */
    protected function getTitle(): string
    {
        return match ($this->type) {
            self::TYPE_ASSIGNED => 'Task Assigned',
            self::TYPE_UPDATED => 'Task Updated',
            self::TYPE_QA_REVIEW => 'Task Ready for QA',
            self::TYPE_CLIENT_REVIEW => 'Task Ready for Client',
            self::TYPE_COMPLETED => 'Task Completed',
            self::TYPE_REJECTED => 'Task Rejected',
            self::TYPE_ON_HOLD => 'Task On Hold',
            default => 'Task Notification',
        };
    }

    /**
     * Get the notification message.
     */
    protected function getMessage(): string
    {
        if ($this->message) {
            return $this->message;
        }

        $actorName = $this->actor?->name ?? 'Someone';
        $title = Str::limit($this->task->title, 50);

        return match ($this->type) {
            self::TYPE_ASSIGNED => "{$actorName} assigned you to task: {$title}",
            self::TYPE_UPDATED => "{$actorName} updated task: {$title}",
            self::TYPE_QA_REVIEW => "{$actorName} submitted task for QA: {$title}",
            self::TYPE_CLIENT_REVIEW => "{$actorName} sent task for client review: {$title}",
            self::TYPE_COMPLETED => "{$actorName} completed task: {$title}",
            self::TYPE_REJECTED => "{$actorName} rejected task: {$title}",
            self::TYPE_ON_HOLD => "{$actorName} put task on hold: {$title}",
            default => "Update on task: {$title}",
        };
    }
}
