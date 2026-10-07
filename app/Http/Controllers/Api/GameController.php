<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;

use App\Events\DiceRolled;
use App\Events\GameEnded;
use App\Events\GameStarted;
use App\Events\PlayerForfeited;
use App\Events\TokenMoved;
use App\Events\TurnChanged;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveTokenRequest;
use App\Http\Requests\RollDiceRequest;

use App\Jobs\ProcessTurnTimeout;

use App\Enums\TransactionType;
use App\Models\Game;
use App\Models\GameMove;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\Wallet;

use App\Services\GameEngine\DiceService;
use App\Services\GameEngine\GameForfeitService;
use App\Services\GameEngine\MoveValidator;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\GameEngine\TurnManager;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;

class GameController extends Controller
{
    private RedisGameStateStore $stateStore;
    private DiceService $diceService;
    private MoveValidator $moveValidator;
    private TurnManager $turnManager;
    private GameForfeitService $forfeitService;

    public function __construct(
        RedisGameStateStore $stateStore,
        DiceService $diceService,
        MoveValidator $moveValidator,
        TurnManager $turnManager,
        GameForfeitService $forfeitService
    ) {
        $this->stateStore = $stateStore;
        $this->diceService = $diceService;
        $this->moveValidator = $moveValidator;
        $this->turnManager = $turnManager;
        $this->forfeitService = $forfeitService;
    }

    /**
     * POST /api/v1/game/start
     * Headers: Authorization: Bearer <token>
     */
    public function start(Request $request): JsonResponse
    {
        $roomId = (int) ($request->quick_match_id ?? $request->room_id);
        if (!$roomId) {
            return response()->json(['status' => 'error', 'message' => 'quick_match_id or room_id is required'], 400);
        }
        $room = Room::with('players.user')->findOrFail($roomId);

        if ($room->isPrivateOrVip()) {
            return response()->json(['status' => 'error', 'message' => 'Private rooms can only be started via private room endpoint'], 403);
        }

        if ($room->created_by !== $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'Only match host can start game'], 403);
        }

        if ($room->players->count() < 2) {
            return response()->json(['status' => 'error', 'message' => 'At least 2 players required to start'], 400);
        }

        $room->update(['status' => RoomStatus::PLAYING->value]);

        $game = Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $playerData = [];
        foreach ($room->players as $p) {
            $playerData[] = [
                'seat_position' => $p->seat_position - 1,
                'user_id' => $p->user_id,
                'username' => $p->user->username ?? "Player",
                'color' => strtolower($p->color->value ?? $p->color),
            ];
        }

        $roomType = $room->type instanceof RoomType ? $room->type->value : (string) ($room->type ?? 'public');
        $gameState = $this->stateStore->initializeState($room->id, $game->id, $playerData, $roomType);

        $isPrivate = $room ? $room->isPrivateOrVip() : false;

        // Explicit WebSocket Broadcast
        broadcast(new GameStarted($room->id, $gameState, $isPrivate));

        // Dispatch turn timeout job: 20s default, or turn_seconds + 2 if explicitly set
        $delay = isset($gameState['turn_seconds']) && $gameState['turn_seconds'] !== null
            ? ((int) $gameState['turn_seconds'] + 2)
            : 20;
        ProcessTurnTimeout::dispatch($room->id, $gameState['current_turn_seat'], $gameState['last_action_at'])
            ->delay(now()->addSeconds($delay));

        return response()->json([
            'status' => 'success',
            'message' => 'Game started successfully',
            'data' => $gameState,
        ]);
    }

    /**
     * GET /api/v1/game/state?room_id=1 or GET /api/v1/quick-match/state?quick_match_id=1
     * Headers: Authorization: Bearer <token>
     */
    public function getGameState(Request $request): JsonResponse
    {
        $roomId = (int) ($request->query('quick_match_id') ?? $request->query('room_id'));
        $state = $this->stateStore->getState($roomId);

        if (!$state) {
            return response()->json(['status' => 'error', 'message' => 'Active match state not found'], 404);
        }

        $room = Room::find($roomId);
        if ($room && $room->isPrivateOrVip()) {
            $user = $request->user();
            $isParticipant = false;
            foreach ($state['players'] as $p) {
                if ((int)$p['user_id'] === (int)$user->id) {
                    $isParticipant = true;
                    break;
                }
            }
            if (!$isParticipant) {
                return response()->json(['status' => 'error', 'message' => 'Active match state not found'], 404);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $state,
        ]);
    }

    /**
     * POST /api/v1/game/roll or POST /api/v1/quick-match/roll
     * Headers: Authorization: Bearer <token>
     */
    public function rollDice(RollDiceRequest $request): JsonResponse
    {
        $user = $request->user();
        $roomId = (int) ($request->quick_match_id ?? $request->room_id);
        $lock = Cache::lock("ludo:lock:game:{$roomId}", 5);

        try {
            return $lock->block(3, function () use ($request, $roomId, $user) {
                $state = $this->stateStore->getState($roomId);

                if (!$state || $state['status'] !== 'in_progress') {
                    return response()->json(['status' => 'error', 'message' => 'No active match found'], 400);
                }

                $room = Room::find($roomId);
                $isPrivate = $room ? $room->isPrivateOrVip() : false;
                if ($isPrivate) {
                    $isParticipant = false;
                    foreach ($state['players'] as $p) {
                        if ((int)$p['user_id'] === (int)$user->id) {
                            $isParticipant = true;
                            break;
                        }
                    }
                    if (!$isParticipant) {
                        return response()->json(['status' => 'error', 'message' => 'Active match state not found'], 404);
                    }
                }

                if ($state['current_turn_user_id'] !== $user->id) {
                    return response()->json(['status' => 'error', 'message' => 'Not your turn'], 403);
                }

                if (!$state['can_roll']) {
                    return response()->json(['status' => 'error', 'message' => 'Already rolled dice'], 400);
                }

                $diceRoll = $this->diceService->roll();
                $consecutiveSixes = $this->turnManager->updateConsecutiveSixes($diceRoll, $state['consecutive_sixes']);

                $seat = $state['current_turn_seat'];
                $playerColor = $state['players'][$seat]['color'];
                $tokens = $state['token_positions'][$playerColor];

                $movableTokens = $this->moveValidator->getMovableTokens($tokens, $diceRoll);

                $state['dice_value'] = $diceRoll;
                $state['consecutive_sixes'] = $consecutiveSixes;
                if (!isset($state['missed_turns']) || !is_array($state['missed_turns'])) {
                    $state['missed_turns'] = [];
                }
                $state['missed_turns'][$seat] = 0;
                $delay = isset($state['turn_seconds']) && $state['turn_seconds'] !== null
                    ? ((int) $state['turn_seconds'] + 2)
                    : 20;

                // Rule: 3 consecutive sixes forfeits turn
                if ($consecutiveSixes >= TurnManager::MAX_CONSECUTIVE_SIXES) {
                    $state['can_roll'] = true;
                    $state['must_move'] = false;
                    $state['consecutive_sixes'] = 0;
                    $state['dice_value'] = null;

                    $nextSeat = $this->turnManager->getNextTurn($seat, $state['active_seats'], false);
                    $state['current_turn_seat'] = $nextSeat;
                    $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];

                    $this->stateStore->saveState($roomId, $state);

                    broadcast(new DiceRolled($roomId, $seat, $user->id, $diceRoll, [], $isPrivate));
                    broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], false, $isPrivate));

                    ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));

                    return response()->json([
                        'status' => 'success',
                        'message' => '3 consecutive 6s! Turn forfeited.',
                        'data' => $state,
                    ]);
                }

                if (empty($movableTokens)) {
                    // No legal moves available
                    $hasExtraTurn = ($diceRoll === 6);
                    $nextSeat = $this->turnManager->getNextTurn($seat, $state['active_seats'], $hasExtraTurn);

                    $state['can_roll'] = true;
                    $state['must_move'] = false;
                    $state['current_turn_seat'] = $nextSeat;
                    $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];

                    $this->stateStore->saveState($roomId, $state);

                    broadcast(new DiceRolled($roomId, $seat, $user->id, $diceRoll, [], $isPrivate));
                    broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], $hasExtraTurn, $isPrivate));

                    ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));

                    return response()->json([
                        'status' => 'success',
                        'message' => 'No legal moves. Turn passed.',
                        'data' => $state,
                    ]);
                }

                $state['can_roll'] = false;
                $state['must_move'] = true;
                $this->stateStore->saveState($roomId, $state);

                broadcast(new DiceRolled($roomId, $seat, $user->id, $diceRoll, $movableTokens, $isPrivate));

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'dice_value' => $diceRoll,
                        'movable_tokens' => $movableTokens,
                        'game_state' => $state,
                    ]
                ]);
            });
        } catch (LockTimeoutException $e) {
            return response()->json(['status' => 'error', 'message' => 'Action in progress, please retry'], 409);
        }
    }

    /**
     * POST /api/v1/game/move or POST /api/v1/quick-match/move
     * Headers: Authorization: Bearer <token>
     */
    public function moveToken(MoveTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $roomId = (int) ($request->quick_match_id ?? $request->room_id);
        $lock = Cache::lock("ludo:lock:game:{$roomId}", 5);

        try {
            return $lock->block(3, function () use ($request, $roomId, $user) {
                $state = $this->stateStore->getState($roomId);

                if (!$state || $state['status'] !== 'in_progress') {
                    return response()->json(['status' => 'error', 'message' => 'No active match found'], 400);
                }

                $room = Room::find($roomId);
                $isPrivate = $room ? $room->isPrivateOrVip() : false;
                if ($isPrivate) {
                    $isParticipant = false;
                    foreach ($state['players'] as $p) {
                        if ((int)$p['user_id'] === (int)$user->id) {
                            $isParticipant = true;
                            break;
                        }
                    }
                    if (!$isParticipant) {
                        return response()->json(['status' => 'error', 'message' => 'Active match state not found'], 404);
                    }
                }

                if ($state['current_turn_user_id'] !== $user->id) {
                    return response()->json(['status' => 'error', 'message' => 'Not your turn'], 403);
                }

                if (!$state['must_move'] || $state['dice_value'] === null) {
                    return response()->json(['status' => 'error', 'message' => 'Roll dice before moving'], 400);
                }

                $seat = $state['current_turn_seat'];
                $playerColor = $state['players'][$seat]['color'];
                $tokenIndex = $request->token_index;
                $diceRoll = $state['dice_value'];

                $roomType = $state['room_type'] ?? ($room ? ($room->type instanceof RoomType ? $room->type->value : (string) $room->type) : 'public');

                $moveResult = $this->moveValidator->validateMove(
                    $state['token_positions'],
                    $playerColor,
                    $tokenIndex,
                    $diceRoll,
                    $roomType
                );

                if (!$moveResult['is_valid']) {
                    return response()->json(['status' => 'error', 'message' => $moveResult['reason']], 400);
                }

                $state['token_positions'][$playerColor][$tokenIndex] = $moveResult['new_steps'];
                if (!isset($state['missed_turns']) || !is_array($state['missed_turns'])) {
                    $state['missed_turns'] = [];
                }
                $state['missed_turns'][$seat] = 0;

                if ($moveResult['is_kill']) {
                    foreach ($moveResult['killed_tokens'] as $killed) {
                        $state['token_positions'][$killed['color']][$killed['token_index']] = -1;
                    }
                }

                GameMove::create([
                    'game_id' => $state['game_id'],
                    'user_id' => $user->id,
                    'token_id' => $tokenIndex,
                    'from_pos' => $moveResult['old_steps'],
                    'to_pos' => $moveResult['new_steps'],
                    'dice_value' => $diceRoll,
                    'is_kill' => $moveResult['is_kill'],
                    'created_at' => now(),
                ]);

                broadcast(new TokenMoved(
                    $roomId,
                    $seat,
                    $user->id,
                    $playerColor,
                    $tokenIndex,
                    $moveResult['old_steps'],
                    $moveResult['new_steps'],
                    $moveResult['target_position'],
                    $moveResult['is_kill'],
                    $moveResult['killed_tokens'],
                    $moveResult['reached_home'],
                    $isPrivate
                ));

                // Check WIN condition
                if ($moveResult['has_won']) {
                    $state['status'] = 'completed';
                    $state['winner_id'] = $user->id;
                    $this->stateStore->saveState($roomId, $state);

                    $game = Game::find($state['game_id']);
                    if ($game) {
                        $game->update([
                            'winner_id' => $user->id,
                            'status' => GameStatus::COMPLETED->value,
                            'ended_at' => now(),
                        ]);

                        app(\App\Services\LeagueService::class)->awardLeaguePoints($game);
                        app(\App\Services\TournamentService::class)->processMatchResult($roomId, $user->id);
                    }

                    $room = Room::find($roomId);
                    $roomTypeVal = $room ? ($room->type instanceof RoomType ? $room->type->value : (string) $room->type) : 'public';
                    $isTeamRoom = ($roomTypeVal === RoomType::TEAM->value || $roomTypeVal === 'team');

                    if ($isTeamRoom) {
                        if ($room) {
                            $room->update(['status' => RoomStatus::FINISHED->value]);
                            $room->increment('state_version');
                        }

                        $entryFee = (int) ($room->entry_fee ?? 100);
                        $rawPot = $entryFee * 4;
                        $cutPct = (float) config('private_room.platform_cut_percentage', 0);
                        $platformCut = (int) floor($rawPot * ($cutPct / 100.0));
                        $totalPrize = max(0, $rawPot - $platformCut);
                        $sharePerTeammate = (int) floor($totalPrize / 2);

                        $movingTeam = \App\Support\TeamAssignment::teamForColor($playerColor);

                        $winningUserIds = [];
                        foreach ($state['players'] as $p) {
                            $pColor = strtolower($p['color']);
                            if (\App\Support\TeamAssignment::teamForColor($pColor) === $movingTeam) {
                                $winningUserIds[] = (int) $p['user_id'];
                            }
                        }
                        $winningUserIds = array_unique($winningUserIds);

                        if ($sharePerTeammate > 0) {
                            foreach ($winningUserIds as $winnerUserId) {
                                Wallet::where('user_id', $winnerUserId)->increment('coins_balance', $sharePerTeammate);

                                Transaction::create([
                                    'user_id' => $winnerUserId,
                                    'type' => TransactionType::WIN,
                                    'currency_type' => 'coins',
                                    'amount' => $sharePerTeammate,
                                    'reference_id' => (string) $roomId,
                                    'created_at' => now(),
                                ]);
                            }
                        }

                        broadcast(new GameEnded($roomId, $state['game_id'], $user->id, $user->username, $totalPrize, true));
                    } elseif ($room && $room->isPrivateOrVip()) {
                        $room->update(['status' => RoomStatus::FINISHED->value]);
                        $room->increment('state_version');

                        $entryFee = (int) ($room->entry_fee ?? 0);
                        $maxPlayers = (int) ($room->max_players ?? 2);
                        $rawPot = $entryFee > 0 ? ($entryFee * $maxPlayers) : 0;
                        $cutPct = (float) config('private_room.platform_cut_percentage', 0);
                        $platformCut = (int) floor($rawPot * ($cutPct / 100.0));
                        $totalPrize = max(0, $rawPot - $platformCut);

                        if ($totalPrize > 0) {
                            Wallet::where('user_id', $user->id)->increment('coins_balance', $totalPrize);

                            Transaction::create([
                                'user_id' => $user->id,
                                'type' => TransactionType::WIN,
                                'currency_type' => 'coins',
                                'amount' => $totalPrize,
                                'reference_id' => (string) $roomId,
                                'created_at' => now(),
                            ]);
                        }

                        broadcast(new GameEnded($roomId, $state['game_id'], $user->id, $user->username, $totalPrize, true));
                    } else {
                        if ($room) {
                            $room->update(['status' => RoomStatus::FINISHED->value]);
                        }

                        broadcast(new GameEnded($roomId, $state['game_id'], $user->id, $user->username, 400));
                    }

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Congratulations! You won the game!',
                        'data' => $state,
                    ]);
                }

                $grantExtraTurn = $this->turnManager->shouldGrantExtraTurn(
                    $diceRoll,
                    $moveResult['is_kill'],
                    $moveResult['reached_home'],
                    $state['consecutive_sixes']
                );

                $nextSeat = $this->turnManager->getNextTurn($seat, $state['active_seats'], $grantExtraTurn);

                $state['can_roll'] = true;
                $state['must_move'] = false;
                $state['dice_value'] = null;
                $state['current_turn_seat'] = $nextSeat;
                $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];
                $state['last_action_at'] = now()->toIso8601String();
                $delay = isset($state['turn_seconds']) && $state['turn_seconds'] !== null
                    ? ((int) $state['turn_seconds'] + 2)
                    : 20;

                $this->stateStore->saveState($roomId, $state);

                broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], $grantExtraTurn, $isPrivate));

                ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'move_result' => $moveResult,
                        'game_state' => $state,
                    ]
                ]);
            });
        } catch (LockTimeoutException $e) {
            return response()->json(['status' => 'error', 'message' => 'Action in progress, please retry'], 409);
        }
    }

    /**
     * POST /api/v1/quick-match/timeout or POST /api/v1/game/timeout
     * Headers: Authorization: Bearer <token>
     */
    public function processTimeout(Request $request): JsonResponse
    {
        $user = $request->user();
        $roomId = (int) ($request->quick_match_id ?? $request->room_id);
        if (!$roomId) {
            return response()->json(['status' => 'error', 'message' => 'Room ID is required'], 400);
        }

        $lock = Cache::lock("ludo:lock:game:{$roomId}", 5);

        try {
            return $lock->block(3, function () use ($roomId, $user) {
                $state = $this->stateStore->getState($roomId);
                if (!$state || $state['status'] !== 'in_progress') {
                    return response()->json(['status' => 'error', 'message' => 'No active game in progress'], 400);
                }

                $seat = $state['current_turn_seat'];
                $turnUserId = (int) $state['current_turn_user_id'];
                if ($turnUserId !== (int) $user->id) {
                    return response()->json(['status' => 'error', 'message' => 'Not your turn to timeout'], 403);
                }

                $room = Room::find($roomId);
                $isPrivate = $room ? $room->isPrivateOrVip() : false;
                $delay = isset($state['turn_seconds']) && $state['turn_seconds'] !== null
                    ? ((int) $state['turn_seconds'] + 2)
                    : 15;

                // 1. If player hasn't rolled yet -> pass turn
                if ($state['can_roll']) {
                    $missedTurns = (int) ($state['missed_turns'][$seat] ?? 0) + 1;
                    if (!isset($state['missed_turns']) || !is_array($state['missed_turns'])) {
                        $state['missed_turns'] = [];
                    }
                    $state['missed_turns'][$seat] = $missedTurns;

                    if ($missedTurns >= 3) {
                        $result = $this->forfeitService->forfeitSeat($roomId, $seat, 'timeout');
                        return response()->json([
                            'status' => 'success',
                            'message' => 'Turn forfeited due to 3 missed turns.',
                            'data' => $result,
                        ]);
                    }

                    $nextSeat = $this->turnManager->getNextTurn($seat, $state['active_seats'], false);
                    $state['can_roll'] = true;
                    $state['must_move'] = false;
                    $state['dice_value'] = null;
                    $state['consecutive_sixes'] = 0;
                    $state['current_turn_seat'] = $nextSeat;
                    $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];
                    $state['last_action_at'] = now()->toIso8601String();

                    $this->stateStore->saveState($roomId, $state);

                    broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], false, $isPrivate));

                    ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Turn passed due to timeout.',
                        'data' => $state,
                    ]);
                }

                // 2. Player rolled but timed out moving: auto-move first legal token
                $playerColor = $state['players'][$seat]['color'];
                $tokens = $state['token_positions'][$playerColor];
                $diceRoll = $state['dice_value'];
                $movableTokens = $this->moveValidator->getMovableTokens($tokens, $diceRoll);
                $chosenToken = !empty($movableTokens) ? $movableTokens[0] : 0;

                $roomType = $state['room_type'] ?? ($room ? ($room->type instanceof \App\Enums\RoomType ? $room->type->value : (string) $room->type) : 'public');
                $moveResult = $this->moveValidator->validateMove($state['token_positions'], $playerColor, $chosenToken, $diceRoll, $roomType);

                if ($moveResult['is_valid']) {
                    $state['token_positions'][$playerColor][$chosenToken] = $moveResult['new_steps'];
                    if ($moveResult['is_kill']) {
                        foreach ($moveResult['killed_tokens'] as $killed) {
                            $state['token_positions'][$killed['color']][$killed['token_index']] = -1;
                        }
                    }

                    broadcast(new \App\Events\TokenMoved(
                        $roomId,
                        $seat,
                        $user->id,
                        $playerColor,
                        $chosenToken,
                        $moveResult['old_steps'],
                        $moveResult['new_steps'],
                        $moveResult['target_position'],
                        $moveResult['is_kill'],
                        $moveResult['killed_tokens'],
                        $moveResult['reached_home'],
                        $isPrivate
                    ));
                }

                $grantExtra = $moveResult['is_valid'] && $this->turnManager->shouldGrantExtraTurn($diceRoll, $moveResult['is_kill'], $moveResult['reached_home'], $state['consecutive_sixes']);
                $nextSeat = $this->turnManager->getNextTurn($seat, $state['active_seats'], $grantExtra);

                $state['can_roll'] = true;
                $state['must_move'] = false;
                $state['dice_value'] = null;
                $state['current_turn_seat'] = $nextSeat;
                $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];
                $state['last_action_at'] = now()->toIso8601String();

                $this->stateStore->saveState($roomId, $state);

                broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], $grantExtra, $isPrivate));

                ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));

                return response()->json([
                    'status' => 'success',
                    'message' => 'Auto-moved token due to timeout.',
                    'data' => $state,
                ]);
            });
        } catch (LockTimeoutException $e) {
            return response()->json(['status' => 'error', 'message' => 'Action in progress, please retry'], 409);
        }
    }

    /**
     * POST /api/v1/quick-match/forfeit or POST /api/v1/game/forfeit
     * Headers: Authorization: Bearer <token>
     */
    public function forfeitMatch(Request $request): JsonResponse
    {
        $user = $request->user();
        $roomId = (int) ($request->quick_match_id ?? $request->room_id);

        if (!$roomId) {
            return response()->json(['status' => 'error', 'message' => 'Room ID is required'], 400);
        }

        $lock = Cache::lock("ludo:lock:game:{$roomId}", 5);

        try {
            return $lock->block(3, function () use ($roomId, $user) {
                $state = $this->stateStore->getState($roomId);
                if (!$state || $state['status'] !== 'in_progress') {
                    return response()->json(['status' => 'error', 'message' => 'No active in-progress match found'], 400);
                }

                $leaverSeat = null;
                foreach ($state['players'] as $seat => $player) {
                    if ((int)$player['user_id'] === (int)$user->id) {
                        $leaverSeat = (int)$seat;
                        break;
                    }
                }

                if ($leaverSeat === null) {
                    return response()->json(['status' => 'error', 'message' => 'You are not a player in this match'], 403);
                }

                $result = $this->forfeitService->forfeitSeat($roomId, $leaverSeat, 'manual');

                return response()->json([
                    'status' => 'success',
                    'message' => $result['is_game_over'] ? 'You forfeited the match. Remaining player awarded victory.' : 'You left the match.',
                    'data' => [
                        'is_game_over' => $result['is_game_over'],
                        'winner_id' => $result['winner_id'] ?? null,
                        'winner_username' => $result['winner_username'] ?? null,
                        'prize_coins' => $result['prize_coins'] ?? 400,
                        'game_state' => $result['game_state'] ?? null,
                    ],
                ]);
            });
        } catch (LockTimeoutException $e) {
            return response()->json(['status' => 'error', 'message' => 'Action in progress, please retry'], 409);
        }
    }
}
