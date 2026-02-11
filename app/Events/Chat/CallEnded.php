<?php

namespace App\Events\Chat;

use App\Models\Chat\Chat;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chatPublicId;

    public string $chatType;

    public string $enderPublicId;

    public string $callId;

    public string $reason; // 'hangup', 'declined', 'timeout', 'failed'

    public function __construct(Chat $chat, string $enderPublicId, string $callId, string $reason = 'hangup')
    {
        $this->chatPublicId = $chat->public_id;
        $this->chatType = $chat->type ?? 'dm';
        $this->enderPublicId = $enderPublicId;
        $this->callId = $callId;
        $this->reason = $reason;
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'ender_public_id' => $this->enderPublicId,
            'reason' => $this->reason,
        ];
    }

    public function broadcastOn(): array
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';
        $channels = [new PrivateChannel("{$prefix}.{$this->chatPublicId}")];

        // Get all participants in the chat to notify them on their user channel
        $chat = \App\Models\Chat\Chat::where('public_id', $this->chatPublicId)->first();
        if ($chat) {
            foreach ($chat->participants as $participant) {
                // Broadcast to all participants EXCEPT the ender
                if ($participant->public_id !== $this->enderPublicId) {
                    $channels[] = new PrivateChannel("user.{$participant->public_id}");
                }
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'CallEnded';
    }
}
