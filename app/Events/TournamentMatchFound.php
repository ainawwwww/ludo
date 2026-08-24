<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TournamentMatchFound implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $tournamentId,
        public int $level,
        public int $roomId,
        public int $gameId,
        public array $players
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
        return 'tournament.match.found';
    }

    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->tournamentId,
            'level' => $this->level,
            'room_id' => $this->roomId,
            'game_id' => $this->gameId,
            'players' => $this->players,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
