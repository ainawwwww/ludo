<?php

namespace App\Services\GameEngine;

/**
 * Pure PHP Move Validator (Decoupled from Framework/Eloquent)
 * Validates legal moves, token kills, and win conditions.
 */
class MoveValidator
{
    private BoardService $boardService;

    public function __construct(?BoardService $boardService = null)
    {
        $this->boardService = $boardService ?? new BoardService();
    }

    /**
     * Validate a token move and calculate new state.
     *
     * @param array<string, array<int, int>> $allPlayerTokens Map of color => array of 4 relative step positions [-1..56]
     * @param string $movingColor Color of the player making the move ('red', 'green', 'yellow', 'blue')
     * @param int $tokenIndex Index of the token being moved (0..3)
     * @param int $diceValue Value rolled on dice (1..6)
     * @return array Result containing valid status, new steps, killed tokens, and win status
     */
    public function validateMove(
        array $allPlayerTokens,
        string $movingColor,
        int $tokenIndex,
        int $diceValue,
        string $roomType = 'public'
    ): array {
        $movingColor = strtolower($movingColor);

        if (!isset($allPlayerTokens[$movingColor][$tokenIndex])) {
            return [
                'is_valid' => false,
                'reason' => 'Invalid token index or player color.',
            ];
        }

        $currentSteps = $allPlayerTokens[$movingColor][$tokenIndex];

        // 1. Token in Base (-1)
        if ($currentSteps === BoardService::POSITION_BASE) {
            if ($diceValue !== 6) {
                return [
                    'is_valid' => false,
                    'reason' => 'Requires a 6 to exit base.',
                ];
            }

            $newSteps = 0; // Exits base to start position (0 steps)
        }
        // 2. Token already completed (56)
        elseif ($currentSteps === BoardService::POSITION_HOME) {
            return [
                'is_valid' => false,
                'reason' => 'Token has already reached home.',
            ];
        }
        // 3. Token on track or home stretch
        else {
            $newSteps = $currentSteps + $diceValue;

            if ($newSteps > BoardService::POSITION_HOME) {
                return [
                    'is_valid' => false,
                    'reason' => 'Move overshoots home destination.',
                ];
            }
        }

        // Calculate target position metadata
        $targetPosInfo = $this->boardService->calculatePosition($movingColor, $newSteps);
        $killedTokens = [];

        // Check for token capture (kill) on main track non-safe spots
        if ($targetPosInfo['type'] === 'main' && !$targetPosInfo['is_safe']) {
            $targetGlobalPos = $targetPosInfo['global_pos'];

            foreach ($allPlayerTokens as $color => $tokens) {
                if ($color === $movingColor) {
                    continue; // Cannot kill own tokens
                }

                // 2v2 Team Mode: friendly-fire protection (cannot kill teammate tokens)
                if (strtolower($roomType) === 'team' && \App\Support\TeamAssignment::areColorsTeammates($movingColor, $color)) {
                    continue;
                }

                foreach ($tokens as $oppIndex => $oppSteps) {
                    if ($oppSteps < 0 || $oppSteps >= 51) {
                        continue; // Opponent token not on main track
                    }

                    $oppPosInfo = $this->boardService->calculatePosition($color, $oppSteps);
                    if ($oppPosInfo['type'] === 'main' && $oppPosInfo['global_pos'] === $targetGlobalPos) {
                        // Kill opponent token!
                        $killedTokens[] = [
                            'color' => $color,
                            'token_index' => $oppIndex,
                            'old_steps' => $oppSteps,
                            'new_steps' => BoardService::POSITION_BASE,
                        ];
                    }
                }
            }
        }

        // Simulate new steps for moving player to test win condition
        $simulatedTokens = $allPlayerTokens;
        $simulatedTokens[$movingColor][$tokenIndex] = $newSteps;

        if (strtolower($roomType) === 'team') {
            // 2v2 Team Mode Win Condition: Both teammates must finish all 8 tokens total
            $movingTeam = \App\Support\TeamAssignment::teamForColor($movingColor);
            $hasWon = true;

            foreach ($simulatedTokens as $color => $tokens) {
                if (\App\Support\TeamAssignment::teamForColor($color) === $movingTeam) {
                    foreach ($tokens as $steps) {
                        if ($steps !== BoardService::POSITION_HOME) {
                            $hasWon = false;
                            break 2;
                        }
                    }
                }
            }
        } else {
            // Standard Mode Win Condition: Individual player finishes all 4 tokens
            $hasWon = true;
            foreach ($simulatedTokens[$movingColor] as $steps) {
                if ($steps !== BoardService::POSITION_HOME) {
                    $hasWon = false;
                    break;
                }
            }
        }

        return [
            'is_valid' => true,
            'color' => $movingColor,
            'token_index' => $tokenIndex,
            'old_steps' => $currentSteps,
            'new_steps' => $newSteps,
            'target_position' => $targetPosInfo,
            'is_kill' => count($killedTokens) > 0,
            'killed_tokens' => $killedTokens,
            'reached_home' => $newSteps === BoardService::POSITION_HOME,
            'has_won' => $hasWon,
            'reason' => null,
        ];
    }

    /**
     * Check if a player has finished all 4 tokens.
     *
     * @param array<int, int> $playerTokens Array of 4 token step positions
     * @return bool
     */
    public function isPlayerFinished(array $playerTokens): bool
    {
        if (count($playerTokens) < 4) {
            return false;
        }

        foreach ($playerTokens as $steps) {
            if ($steps !== BoardService::POSITION_HOME) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a player's turn should be skipped (e.g. all 4 tokens are home).
     *
     * @param array<int, int> $playerTokens Array of 4 token step positions
     * @return bool
     */
    public function shouldSkipTurn(array $playerTokens): bool
    {
        return $this->isPlayerFinished($playerTokens);
    }

    /**
     * Get list of movable token indices for a player given a dice roll.
     *
     * @param array<int, int> $playerTokens Array of 4 token step positions
     * @param int $diceValue
     * @return array<int> Indices of tokens that have legal moves
     */
    public function getMovableTokens(array $playerTokens, int $diceValue): array
    {
        if ($this->shouldSkipTurn($playerTokens)) {
            return [];
        }

        $movable = [];
        foreach ($playerTokens as $index => $steps) {
            if ($steps === BoardService::POSITION_BASE) {
                if ($diceValue === 6) {
                    $movable[] = $index;
                }
            } elseif ($steps < BoardService::POSITION_HOME) {
                if ($steps + $diceValue <= BoardService::POSITION_HOME) {
                    $movable[] = $index;
                }
            }
        }
        return $movable;
    }
}
