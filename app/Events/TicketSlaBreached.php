<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketSlaBreached implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $publicId;

    public string $breachType;

    /**
     * Create a new event instance.
     *
     * @param  string  $breachType  'response' or 'resolution'
     */
    public function __construct(
        public Ticket $ticket,
        string $breachType = 'resolution'
    ) {
        $this->publicId = $ticket->public_id;
        $this->breachType = $breachType;
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
        return 'ticket.sla_breached';
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
            'breach_type' => $this->breachType,
            'priority' => $this->ticket->priority->value,
            'assignee' => [
                'public_id' => $this->ticket->assignee?->public_id,
                'name' => $this->ticket->assignee?->name,
            ],
            'due_at' => $this->ticket->due_at?->toISOString(),
            'breached_at' => now()->toISOString(),
        ];
    }
}
