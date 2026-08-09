<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $gameId,
        public int $winnerId,
        public string $winnerUsername,
        public int $prizeCoins
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room.' . $this->roomId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'game.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'game_id' => $this->gameId,
            'winner_id' => $this->winnerId,
            'winner_username' => $this->winnerUsername,
            'prize_coins' => $this->prizeCoins,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
