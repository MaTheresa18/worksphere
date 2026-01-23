<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketSlaWarning implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $publicId;

    public string $warningType;

    public float $progress;

    /**
     * Create a new event instance.
     *
     * @param  string  $warningType  'response' or 'resolution'
     * @param  float  $progress  SLA progress percentage
     */
    public function __construct(
        public Ticket $ticket,
        string $warningType = 'resolution',
        float $progress = 0
    ) {
        $this->publicId = $ticket->public_id;
        $this->warningType = $warningType;
        $this->progress = $progress;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tickets.'.$this->publicId),
            new PrivateChannel('tickets.queue'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'ticket.sla_warning';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'public_id' => $this->publicId,
            'ticket_number' => $this->ticket->ticket_number,
            'title' => $this->ticket->title,
            'warning_type' => $this->warningType,
            'progress' => $this->progress,
            'priority' => $this->ticket->priority->value,
            'assignee' => [
                'public_id' => $this->ticket->assignee?->public_id,
                'name' => $this->ticket->assignee?->name,
            ],
            'warned_at' => now()->toISOString(),
        ];
    }
}
