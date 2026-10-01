<?php

namespace App\Support;

/**
 * Single Source of Truth for 2v2 Team Seat & Color Assignments.
 * Shared across MatchmakingService, MoveValidator, Controllers, and Game Engine.
 *
 * Rules:
 *  - Team 1: Seat 1 (Red) + Seat 3 (Yellow)
 *  - Team 2: Seat 2 (Green) + Seat 4 (Blue)
 */
class TeamAssignment
{
    public const TEAM_1 = 1;
    public const TEAM_2 = 2;

    public const SEAT_TEAMS = [
        1 => self::TEAM_1, // Red
        2 => self::TEAM_2, // Green
        3 => self::TEAM_1, // Yellow
        4 => self::TEAM_2, // Blue
    ];

    public const SEAT_COLORS = [
        1 => 'red',
        2 => 'green',
        3 => 'yellow',
        4 => 'blue',
    ];

    public const COLOR_TEAMS = [
        'red' => self::TEAM_1,
        'yellow' => self::TEAM_1,
        'green' => self::TEAM_2,
        'blue' => self::TEAM_2,
    ];

    /**
     * Get Team ID (1 or 2) for a given 1-indexed seat position.
     */
    public static function teamForSeat(int $seatPosition): int
    {
        return self::SEAT_TEAMS[$seatPosition] ?? self::TEAM_1;
    }

    /**
     * Get partner's seat position for a given 1-indexed seat position.
     */
    public static function teammateSeat(int $seatPosition): int
    {
        return match ($seatPosition) {
            1 => 3,
            3 => 1,
            2 => 4,
            4 => 2,
            default => $seatPosition,
        };
    }

    /**
     * Get color string for a 1-indexed seat position.
     */
    public static function colorForSeat(int $seatPosition): string
    {
        return self::SEAT_COLORS[$seatPosition] ?? 'red';
    }

    /**
     * Get Team ID for a given color ('red', 'green', 'yellow', 'blue').
     */
    public static function teamForColor(string $color): int
    {
        return self::COLOR_TEAMS[strtolower($color)] ?? self::TEAM_1;
    }

    /**
     * Check if two seats are on the same team.
     */
    public static function areTeammates(int $seat1, int $seat2): bool
    {
        if ($seat1 === $seat2) {
            return false;
        }
        return self::teamForSeat($seat1) === self::teamForSeat($seat2);
    }

    /**
     * Check if two player colors are on the same team.
     */
    public static function areColorsTeammates(string $color1, string $color2): bool
    {
        $c1 = strtolower($color1);
        $c2 = strtolower($color2);

        if ($c1 === $c2) {
            return false;
        }

        return self::teamForColor($c1) === self::teamForColor($c2);
    }
}
