<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Room;
use App\Services\GameEngine\RedisGameStateStore;

class GameInitializerService
{
    public function __construct(
        protected RedisGameStateStore $stateStore
    ) {}

    /**
     * Create Game record and initialize Redis game state for a room.
     *
     * @param Room $room
     * @param array $playerData 0-indexed player info [['seat_position' => int, 'user_id' => int, 'username' => string, 'color' => string]]
     * @return array [Game $game, array $gameState]
     */
    public function initializeGame(Room $room, array $playerData): array
    {
        $game = Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $gameState = $this->stateStore->initializeState($room->id, $game->id, $playerData);

        if ($room->turn_seconds !== null) {
            $gameState['turn_seconds'] = (int) $room->turn_seconds;
            $this->stateStore->saveState($room->id, $gameState);
        }

        return [$game, $gameState];
    }
}
