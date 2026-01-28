<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Task $task
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('projects.' . $this->task->project_id . '.tasks'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->task->loadMissing('assignee');

        return [
            'id' => $this->task->public_id,
            'public_id' => $this->task->public_id,
            'title' => $this->task->title,
            'status' => $this->task->status,
            'priority' => $this->task->priority,
            'updated_at' => $this->task->updated_at,
            'assignee' => $this->task->assignee ? [
                'id' => $this->task->assignee->id,
                'name' => $this->task->assignee->name,
                'avatar_url' => $this->task->assignee->profile_photo_url,
            ] : null,
        ];
    }
}
