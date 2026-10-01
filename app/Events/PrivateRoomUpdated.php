<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PrivateRoomUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Dispatch event only after the database transaction commits.
     *
     * @var bool
     */
    public bool $afterCommit = true;

    public function __construct(
        public int $roomId,
        public string $reason,
        public ?int $actorUserId,
        public array $snapshot,
        public int $version
    ) {}

    /**
     * Broadcast to the private room lobby channel.
     * Laravel channel name: room-lobby.{roomId}
     * Wire name: private-room-lobby.{roomId}
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room-lobby.' . $this->roomId),
        ];
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'private_room.updated';
    }

    /**
     * Event data payload matching required schema:
     * reason, actor_user_id, snapshot (user-agnostic), version.
     */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'actor_user_id' => $this->actorUserId,
            'snapshot' => $this->snapshot,
            'version' => $this->version,
        ];
    }
}
