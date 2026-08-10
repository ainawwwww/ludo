<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TournamentLevelReached implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $tournamentId,
        public int $newLevel,
        public array $reward,
        public bool $isFinalLevel
    ) {}

    /**
     * Broadcast to the specific user's private channel.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'tournament.level.reached';
    }

    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->tournamentId,
            'new_level' => $this->newLevel,
            'reward' => $this->reward,
            'is_final_level' => $this->isFinalLevel,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
