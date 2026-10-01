<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamMatchFound implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $roomId,
        public int $gameId,
        public array $team1,
        public array $team2,
        public int $mySeat,
        public string $myColor,
        public int $entryFee
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'team_match.found';
    }

    public function broadcastWith(): array
    {
        return [
            'match_id' => $this->roomId,
            'room_id' => $this->roomId,
            'game_id' => $this->gameId,
            'team_1' => $this->team1,
            'team_2' => $this->team2,
            'my_seat' => $this->mySeat,
            'my_color' => $this->myColor,
            'entry_fee' => $this->entryFee,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
