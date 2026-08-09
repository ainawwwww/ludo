<?php

namespace App\Services\GameEngine;

/**
 * Pure PHP Board Service (Decoupled from Framework/Eloquent)
 * Handles token step conversions, board track coordinates, safe spots, and home paths.
 */
class BoardService
{
    public const POSITION_BASE = -1;
    public const POSITION_HOME = 56;
    public const TOTAL_MAIN_TRACK_TILES = 52;
    public const MAX_STEPS = 56;

    /**
     * Main track starting offset per player color.
     */
    private const COLOR_OFFSETS = [
        'red' => 0,
        'green' => 13,
        'yellow' => 26,
        'blue' => 39,
    ];

    /**
     * Global main track safe spots (4 starting spots + 4 star spots).
     */
    private const SAFE_SPOTS = [0, 8, 13, 21, 26, 34, 39, 47];

    /**
     * Get main track start offset for a given color.
     */
    public function getStartOffset(string $color): int
    {
        return self::COLOR_OFFSETS[strtolower($color)] ?? 0;
    }

    /**
     * Check if a global main track position is a safe spot.
     */
    public function isSafeSpot(int $globalPos): bool
    {
        return in_array($globalPos, self::SAFE_SPOTS, true);
    }

    /**
     * Convert relative steps (0 to 56) for a color to a global position payload.
     * Returns:
     *  - position_type: 'base' (-1), 'main' (0..51), 'home_stretch' (51..55), 'home' (56)
     *  - global_pos: global board index or null
     *  - is_safe: bool
     */
    public function calculatePosition(string $color, int $relativeSteps): array
    {
        if ($relativeSteps < 0) {
            return [
                'type' => 'base',
                'relative_steps' => self::POSITION_BASE,
                'global_pos' => null,
                'is_safe' => true,
            ];
        }

        if ($relativeSteps >= self::POSITION_HOME) {
            return [
                'type' => 'home',
                'relative_steps' => self::POSITION_HOME,
                'global_pos' => null,
                'is_safe' => true,
            ];
        }

        // Home stretch (steps 51 to 55)
        if ($relativeSteps >= 51) {
            return [
                'type' => 'home_stretch',
                'relative_steps' => $relativeSteps,
                'global_pos' => 100 + ($relativeSteps - 50), // 101..105
                'is_safe' => true,
            ];
        }

        // Main track (steps 0 to 50)
        $startOffset = $this->getStartOffset($color);
        $globalPos = ($startOffset + $relativeSteps) % self::TOTAL_MAIN_TRACK_TILES;

        return [
            'type' => 'main',
            'relative_steps' => $relativeSteps,
            'global_pos' => $globalPos,
            'is_safe' => $this->isSafeSpot($globalPos),
        ];
    }
}
