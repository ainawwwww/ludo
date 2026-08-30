<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerForfeited implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $userId,
        public string $username,
        public bool $isGameOver = false,
        public ?int $winnerId = null,
        public ?string $winnerUsername = null,
        public int $prizeCoins = 400
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
        return 'player.forfeited';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'username' => $this->username,
            'is_game_over' => $this->isGameOver,
            'winner_id' => $this->winnerId,
            'winner_username' => $this->winnerUsername,
            'prize_coins' => $this->prizeCoins,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
