<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public ChatMessage $chatMessage
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room.' . $this->roomId),
            new Channel('room.' . $this->roomId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->chatMessage->id,
            'room_id' => $this->roomId,
            'user_id' => $this->chatMessage->user_id,
            'username' => $this->chatMessage->user->username ?? 'Player',
            'avatar_url' => $this->chatMessage->user->avatar_url ?? null,
            'message' => $this->chatMessage->message,
            'message_type' => $this->chatMessage->message_type,
            'created_at' => $this->chatMessage->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
