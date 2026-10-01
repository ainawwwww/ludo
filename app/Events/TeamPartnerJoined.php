<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamPartnerJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $roomId,
        public int $joinedUserId,
        public string $joinedUsername,
        public int $seatPosition,
        public string $color,
        public array $snapshot,
        public int $version
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room-lobby.' . $this->roomId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'team_partner.joined';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'joined_user_id' => $this->joinedUserId,
            'joined_username' => $this->joinedUsername,
            'seat_position' => $this->seatPosition,
            'color' => $this->color,
            'snapshot' => $this->snapshot,
            'version' => $this->version,
        ];
    }
}
