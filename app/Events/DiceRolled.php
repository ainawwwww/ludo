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
        public array $movableTokens
    ) {}

    /**
     * Intentional dual-channel broadcast for network resilience:
     * - PrivateChannel: Secure authenticated room channel.
     * - Channel: Public fallback channel ensuring delivery even during socket auth re-negotiations.
     * Frontend deduplicates via _recentWsEventSignatures. Do NOT remove either channel.
     */
    public function broadcastOn(): array
    {
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
