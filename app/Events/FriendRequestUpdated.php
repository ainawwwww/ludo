<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FriendRequestUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $friendId,
        public string $status,
        public ?int $requestId = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
            new PrivateChannel('user.' . $this->friendId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'friend.request_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'friend_id' => $this->friendId,
            'status' => $this->status,
            'request_id' => $this->requestId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
