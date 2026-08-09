<?php

namespace App\Services\GameEngine;

/**
 * Pure PHP Dice Service (Decoupled from Framework/Eloquent)
 */
class DiceService
{
    private ?int $forcedValue = null;

    /**
     * Set a forced dice value for deterministic testing.
     */
    public function forceRoll(?int $value): void
    {
        $this->forcedValue = $value;
    }

    /**
     * Roll a 6-sided die (1 to 6).
     */
    public function roll(): int
    {
        if ($this->forcedValue !== null && $this->forcedValue >= 1 && $this->forcedValue <= 6) {
            $val = $this->forcedValue;
            $this->forcedValue = null; // reset after one roll
            return $val;
        }

        return random_int(1, 6);
    }

    /**
     * Check if a rolled dice value grants a bonus turn (rolling 6).
     */
    public function isSix(int $diceValue): bool
    {
        return $diceValue === 6;
    }
}
