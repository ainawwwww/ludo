<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TurnChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $currentTurnSeat,
        public int $currentTurnUserId,
        public bool $hasExtraTurn = false
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
        return 'turn.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'current_turn_seat' => $this->currentTurnSeat,
            'current_turn_user_id' => $this->currentTurnUserId,
            'has_extra_turn' => $this->hasExtraTurn,
            'timer_seconds' => 15,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
