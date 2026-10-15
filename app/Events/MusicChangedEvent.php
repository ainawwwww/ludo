<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MusicChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public string $action,
        public ?array $track,
        public int $userId
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
        return 'music.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'action' => $this->action,
            'track' => $this->track,
            'user_id' => $this->userId,
        ];
    }
}
