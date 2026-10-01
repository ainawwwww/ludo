<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiceRolled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $seatPosition,
        public int $userId,
        public int $diceValue,
        public array $movableTokens,
        public bool $isPrivate = false
    ) {}

    /**
     * Type-aware dual-channel broadcast:
     * - Private rooms: PrivateChannel ONLY (room.{id} auth guard requires membership).
     * - Public/quick-match/tournament: both PrivateChannel + public Channel for network resilience.
     *   Frontend deduplicates via _recentWsEventSignatures. Do NOT remove either channel for public rooms.
     */
    public function broadcastOn(): array
    {
        if ($this->isPrivate) {
            return [
                new PrivateChannel('room.' . $this->roomId),
            ];
        }

        return [
            new PrivateChannel('room.' . $this->roomId),
            new Channel('room.' . $this->roomId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dice.rolled';
    }

    public function broadcastWith(): array
    {
        return [
            'seat_position' => $this->seatPosition,
            'user_id' => $this->userId,
            'dice_value' => $this->diceValue,
            'movable_tokens' => $this->movableTokens,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
