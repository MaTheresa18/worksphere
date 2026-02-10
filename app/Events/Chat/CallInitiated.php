<?php

namespace App\Events\Chat;

use App\Models\Chat\Chat;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallInitiated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chatPublicId;

    public string $chatType;

    public string $callerPublicId;

    public string $callerName;

    public ?string $callerAvatar;

    public string $callId;

    public string $callType; // 'video' or 'audio'

    public function __construct(Chat $chat, User $caller, string $callId, string $callType = 'video')
    {
        $this->chatPublicId = $chat->public_id;
        $this->chatType = $chat->type ?? 'dm';
        $this->callerPublicId = $caller->public_id;
        $this->callerName = $caller->name;
        $this->callerAvatar = $caller->avatar_thumb_url;
        $this->callId = $callId;
        $this->callType = $callType;
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'call_type' => $this->callType,
            'caller_public_id' => $this->callerPublicId,
            'caller_name' => $this->callerName,
            'caller_avatar' => $this->callerAvatar,
            'chat_id' => $this->chatPublicId,
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';

        return new PrivateChannel("{$prefix}.{$this->chatPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'CallInitiated';
    }
}
