<?php

namespace App\Jobs;

use App\Events\DiceRolled;
use App\Events\TokenMoved;
use App\Events\TurnChanged;
use App\Services\GameEngine\DiceService;
use App\Services\GameEngine\MoveValidator;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\GameEngine\TurnManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTurnTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $turnSeat,
        public string $turnStartedAt
    ) {}

    public function handle(
        RedisGameStateStore $stateStore,
        DiceService $diceService,
        MoveValidator $moveValidator,
        TurnManager $turnManager
    ): void {
        $lock = \Illuminate\Support\Facades\Cache::lock("ludo:lock:game:{$this->roomId}", 5);

        try {
            $lock->block(3, function () use ($stateStore, $diceService, $moveValidator, $turnManager) {
                $state = $stateStore->getState($this->roomId);

                if (!$state || $state['status'] !== 'in_progress') {
                    return;
                }

                // Check if player hasn't acted and turn timestamp matches
                if ($state['current_turn_seat'] !== $this->turnSeat || $state['last_action_at'] !== $this->turnStartedAt) {
                    return; // Player acted in time, do nothing
                }

                $seat = $state['current_turn_seat'];
                $userId = $state['current_turn_user_id'];
                $playerColor = $state['players'][$seat]['color'];
                $tokens = $state['token_positions'][$playerColor];
                $turnSeconds = (int) ($state['turn_seconds'] ?? 15);
                $delay = $turnSeconds + 2;

                // 1. If player hasn't rolled yet -> timeout expired! Forfeit turn and pass directly to next player (NO AUTO-ROLL)
                if ($state['can_roll']) {
                    $nextSeat = $turnManager->getNextTurn($seat, $state['active_seats'], false);
                    $state['can_roll'] = true;
                    $state['must_move'] = false;
                    $state['dice_value'] = null;
                    $state['consecutive_sixes'] = 0;
                    $state['current_turn_seat'] = $nextSeat;
                    $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];
                    $state['last_action_at'] = now()->toIso8601String();

                    $stateStore->saveState($this->roomId, $state);

                    broadcast(new TurnChanged($this->roomId, $nextSeat, $state['current_turn_user_id'], false));

                    // Dispatch delayed turn timeout job for next player
                    self::dispatch($this->roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));
                    return;
                }

                // 2. Player rolled previously but timed out picking a piece: auto-pick first legal movable token
                $diceRoll = $state['dice_value'];
                $movableTokens = $moveValidator->getMovableTokens($tokens, $diceRoll);
                $chosenToken = !empty($movableTokens) ? $movableTokens[0] : 0;

                // 2. Perform auto-move on chosen token
                $moveResult = $moveValidator->validateMove($state['token_positions'], $playerColor, $chosenToken, $diceRoll);

                if ($moveResult['is_valid']) {
                    $state['token_positions'][$playerColor][$chosenToken] = $moveResult['new_steps'];

                    if ($moveResult['is_kill']) {
                        foreach ($moveResult['killed_tokens'] as $killed) {
                            $state['token_positions'][$killed['color']][$killed['token_index']] = -1;
                        }
                    }

                    broadcast(new TokenMoved(
                        $this->roomId,
                        $seat,
                        $userId,
                        $playerColor,
                        $chosenToken,
                        $moveResult['old_steps'],
                        $moveResult['new_steps'],
                        $moveResult['target_position'],
                        $moveResult['is_kill'],
                        $moveResult['killed_tokens'],
                        $moveResult['reached_home']
                    ));
                }

                // Rotate turn
                $grantExtra = $moveResult['is_valid'] && $turnManager->shouldGrantExtraTurn($diceRoll, $moveResult['is_kill'], $moveResult['reached_home'], $state['consecutive_sixes']);
                $nextSeat = $turnManager->getNextTurn($seat, $state['active_seats'], $grantExtra);

                $state['can_roll'] = true;
                $state['must_move'] = false;
                $state['dice_value'] = null;
                $state['current_turn_seat'] = $nextSeat;
                $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];
                $state['last_action_at'] = now()->toIso8601String();

                $stateStore->saveState($this->roomId, $state);

                broadcast(new TurnChanged($this->roomId, $nextSeat, $state['current_turn_user_id'], $grantExtra));

                self::dispatch($this->roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Lock contention, another action was executing
        }
    }
}
