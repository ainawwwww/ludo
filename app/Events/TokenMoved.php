<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TokenMoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $seatPosition,
        public int $userId,
        public string $color,
        public int $tokenIndex,
        public int $oldSteps,
        public int $newSteps,
        public array $targetPosition,
        public bool $isKill,
        public array $killedTokens,
        public bool $reachedHome
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room.' . $this->roomId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'token.moved';
    }

    public function broadcastWith(): array
    {
        return [
            'seat_position' => $this->seatPosition,
            'user_id' => $this->userId,
            'color' => $this->color,
            'token_index' => $this->tokenIndex,
            'old_steps' => $this->oldSteps,
            'new_steps' => $this->newSteps,
            'target_position' => $this->targetPosition,
            'is_kill' => $this->isKill,
            'killed_tokens' => $this->killedTokens,
            'reached_home' => $this->reachedHome,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
