<?php

namespace App\Services\GameEngine;

/**
 * Pure PHP Turn Manager (Decoupled from Framework/Eloquent)
 * Manages turn rotation, extra turns, and consecutive 6s rules.
 */
class TurnManager
{
    public const MAX_CONSECUTIVE_SIXES = 3;

    /**
     * Determine the next active seat position.
     */
    public function getNextTurn(int $currentSeat, array $activeSeats, bool $hasExtraTurn = false): int
    {
        if (empty($activeSeats)) {
            return $currentSeat;
        }

        sort($activeSeats);

        if ($hasExtraTurn && in_array($currentSeat, $activeSeats, true)) {
            return $currentSeat;
        }

        // Find index of current seat
        $currentIndex = array_search($currentSeat, $activeSeats, true);
        if ($currentIndex === false) {
            return $activeSeats[0];
        }

        $nextIndex = ($currentIndex + 1) % count($activeSeats);
        return $activeSeats[$nextIndex];
    }

    /**
     * Check if player gets an extra turn based on game actions.
     */
    public function shouldGrantExtraTurn(
        int $diceRoll,
        bool $isKill,
        bool $reachedHome,
        int $currentConsecutiveSixes
    ): bool {
        // If 3 consecutive 6s rolled, forfeit extra turn
        if ($diceRoll === 6 && $currentConsecutiveSixes >= self::MAX_CONSECUTIVE_SIXES) {
            return false;
        }

        return $diceRoll === 6 || $isKill || $reachedHome;
    }

    /**
     * Update consecutive 6s counter.
     */
    public function updateConsecutiveSixes(int $diceRoll, int $currentCount): int
    {
        if ($diceRoll === 6) {
            return $currentCount + 1;
        }

        return 0; // reset on non-six roll
    }
}
