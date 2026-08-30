<?php

namespace App\Services\GameEngine;

use Illuminate\Support\Facades\Cache;
use Exception;

class RedisGameStateStore
{
    private const KEY_PREFIX = 'ludo:game:room:';
    private const DEFAULT_TTL = 86400; // 24 hours in seconds

    /**
     * Initialize live game match state in Redis.
     */
    public function initializeState(int $roomId, int $gameId, array $players): array
    {
        $tokenPositions = [];
        $playerSeats = [];

        foreach ($players as $player) {
            $seat = (int) $player['seat_position'];
            $color = strtolower($player['color']);

            $playerSeats[$seat] = [
                'user_id' => (int) $player['user_id'],
                'username' => $player['username'] ?? "Player $seat",
                'color' => $color,
                'seat_position' => $seat,
                'is_connected' => true,
            ];

            // Initial tokens: all 4 tokens in Base (-1)
            $tokenPositions[$color] = [-1, -1, -1, -1];
        }

        ksort($playerSeats);
        $activeSeats = array_keys($playerSeats);
        $initialTurnSeat = reset($activeSeats);
        $initialUserId = $playerSeats[$initialTurnSeat]['user_id'] ?? null;

        $state = [
            'quick_match_id' => $roomId,
            'room_id' => $roomId,
            'game_id' => $gameId,
            'current_turn_seat' => $initialTurnSeat,
            'current_turn_user_id' => $initialUserId,
            'active_seats' => $activeSeats,
            'dice_value' => null,
            'can_roll' => true,
            'must_move' => false,
            'consecutive_sixes' => 0,
            'token_positions' => $tokenPositions,
            'players' => $playerSeats,
            'status' => 'in_progress',
            'winner_id' => null,
            'last_action_at' => now()->toIso8601String(),
        ];

        $this->saveState($roomId, $state);
        return $state;
    }

    /**
     * Get current live game state from Redis.
     */
    public function getState(int $roomId): ?array
    {
        try {
            return Cache::get(self::KEY_PREFIX . $roomId);
        } catch (Exception $e) {
            // Fallback to array cache if Redis connection is unavailable in local dev
            return Cache::driver('file')->get(self::KEY_PREFIX . $roomId);
        }
    }

    /**
     * Save/Update live game state in Redis.
     */
    public function saveState(int $roomId, array $state): bool
    {
        $state['last_action_at'] = now()->toIso8601String();

        try {
            return Cache::put(self::KEY_PREFIX . $roomId, $state, self::DEFAULT_TTL);
        } catch (Exception $e) {
            return Cache::driver('file')->put(self::KEY_PREFIX . $roomId, $state, self::DEFAULT_TTL);
        }
    }

    /**
     * Delete game state from Redis when match finishes.
     */
    public function deleteState(int $roomId): bool
    {
        try {
            return Cache::forget(self::KEY_PREFIX . $roomId);
        } catch (Exception $e) {
            return Cache::driver('file')->forget(self::KEY_PREFIX . $roomId);
        }
    }
}
