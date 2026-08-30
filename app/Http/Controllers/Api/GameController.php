<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;

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
use App\Services\GameEngine\MoveValidator;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\GameEngine\TurnManager;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    private RedisGameStateStore $stateStore;
    private DiceService $diceService;
    private MoveValidator $moveValidator;
    private TurnManager $turnManager;

    public function __construct(
        RedisGameStateStore $stateStore,
        DiceService $diceService,
        MoveValidator $moveValidator,
        TurnManager $turnManager
    ) {
        $this->stateStore = $stateStore;
        $this->diceService = $diceService;
        $this->moveValidator = $moveValidator;
        $this->turnManager = $turnManager;
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

        $gameState = $this->stateStore->initializeState($room->id, $game->id, $playerData);

        // Explicit WebSocket Broadcast
        broadcast(new GameStarted($room->id, $gameState));

        // Dispatch 20-second turn timeout job
        ProcessTurnTimeout::dispatch($room->id, $gameState['current_turn_seat'], $gameState['last_action_at'])
            ->delay(now()->addSeconds(20));

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
        $state = $this->stateStore->getState($roomId);

        if (!$state || $state['status'] !== 'in_progress') {
            return response()->json(['status' => 'error', 'message' => 'No active match found'], 400);
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

            broadcast(new DiceRolled($roomId, $seat, $user->id, $diceRoll, []));
            broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id']));

            ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds(20));

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

            broadcast(new DiceRolled($roomId, $seat, $user->id, $diceRoll, []));
            broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], $hasExtraTurn));

            ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds(20));

            return response()->json([
                'status' => 'success',
                'message' => 'No legal moves. Turn passed.',
                'data' => $state,
            ]);
        }

        $state['can_roll'] = false;
        $state['must_move'] = true;
        $this->stateStore->saveState($roomId, $state);

        broadcast(new DiceRolled($roomId, $seat, $user->id, $diceRoll, $movableTokens));

        return response()->json([
            'status' => 'success',
            'data' => [
                'dice_value' => $diceRoll,
                'movable_tokens' => $movableTokens,
                'game_state' => $state,
            ]
        ]);
    }

    /**
     * POST /api/v1/game/move or POST /api/v1/quick-match/move
     * Headers: Authorization: Bearer <token>
     */
    public function moveToken(MoveTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $roomId = (int) ($request->quick_match_id ?? $request->room_id);
        $state = $this->stateStore->getState($roomId);

        if (!$state || $state['status'] !== 'in_progress') {
            return response()->json(['status' => 'error', 'message' => 'No active match found'], 400);
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

        $moveResult = $this->moveValidator->validateMove(
            $state['token_positions'],
            $playerColor,
            $tokenIndex,
            $diceRoll
        );

        if (!$moveResult['is_valid']) {
            return response()->json(['status' => 'error', 'message' => $moveResult['reason']], 400);
        }

        $state['token_positions'][$playerColor][$tokenIndex] = $moveResult['new_steps'];

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
            $moveResult['reached_home']
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

            Room::where('id', $roomId)->update(['status' => RoomStatus::FINISHED->value]);

            broadcast(new GameEnded($roomId, $state['game_id'], $user->id, $user->username, 400));

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

        $this->stateStore->saveState($roomId, $state);

        broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], $grantExtraTurn));

        ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds(20));

        return response()->json([
            'status' => 'success',
            'data' => [
                'move_result' => $moveResult,
                'game_state' => $state,
            ]
        ]);
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

        $state = $this->stateStore->getState($roomId);
        if (!$state || $state['status'] !== 'in_progress') {
            return response()->json(['status' => 'error', 'message' => 'No active in-progress match found'], 400);
        }

        $room = Room::find($roomId);
        $entryFee = (int) ($room?->entry_fee ?? 200);
        $maxPlayers = (int) ($room?->max_players ?? 2);
        $totalPrize = max(400, $entryFee * $maxPlayers);

        // Find leaver's seat
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

        // Remove leaver from active seats
        $activeSeats = array_values(array_diff($state['active_seats'], [$leaverSeat]));
        $state['active_seats'] = $activeSeats;
        if (isset($state['players'][$leaverSeat])) {
            $state['players'][$leaverSeat]['is_connected'] = false;
        }

        // Check if only 1 (or 0) active player remains -> GAME OVER, Remaining Player WINS Full Pot!
        if (count($activeSeats) <= 1) {
            $winnerSeat = !empty($activeSeats) ? $activeSeats[0] : null;
            $winnerPlayer = $winnerSeat !== null ? ($state['players'][$winnerSeat] ?? null) : null;
            $winnerId = $winnerPlayer['user_id'] ?? null;
            $winnerUsername = $winnerPlayer['username'] ?? 'Winner';

            $state['status'] = 'completed';
            $state['winner_id'] = $winnerId;
            $this->stateStore->saveState($roomId, $state);

            // Update Game in DB
            $game = Game::find($state['game_id']);
            if ($game) {
                $game->update([
                    'winner_id' => $winnerId,
                    'status' => GameStatus::COMPLETED->value,
                    'ended_at' => now(),
                ]);

                if ($winnerId) {
                    app(\App\Services\LeagueService::class)->awardLeaguePoints($game);
                    app(\App\Services\TournamentService::class)->processMatchResult($roomId, $winnerId);
                }
            }

            // Award full pot coins to the winning player's wallet
            if ($winnerId) {
                Wallet::where('user_id', $winnerId)->increment('coins', $totalPrize);

                Transaction::create([
                    'user_id' => $winnerId,
                    'type' => TransactionType::REWARD,
                    'currency_type' => 'coins',
                    'amount' => $totalPrize,
                    'reference_id' => (string) $roomId,
                    'created_at' => now(),
                ]);
            }

            if ($room) {
                $room->update(['status' => RoomStatus::FINISHED->value]);
            }

            // Broadcast real-time events
            broadcast(new GameEnded($roomId, $state['game_id'], $winnerId ?? 0, $winnerUsername, $totalPrize));
            broadcast(new PlayerForfeited($roomId, $user->id, $user->username, true, $winnerId, $winnerUsername, $totalPrize));

            return response()->json([
                'status' => 'success',
                'message' => 'You forfeited the match. Remaining player awarded victory.',
                'data' => [
                    'is_game_over' => true,
                    'winner_id' => $winnerId,
                    'winner_username' => $winnerUsername,
                    'prize_coins' => $totalPrize,
                    'game_state' => $state,
                ],
            ]);
        }

        // More than 1 active player remains (4-player match continues with remaining players)
        // If it was the leaver's turn, pass turn to the next active player
        if ($state['current_turn_seat'] === $leaverSeat) {
            $nextSeat = $this->turnManager->getNextTurn($leaverSeat, $state['active_seats'], false);
            $state['current_turn_seat'] = $nextSeat;
            $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];
            $state['can_roll'] = true;
            $state['must_move'] = false;
            $state['dice_value'] = null;

            broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id']));
            ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds(20));
        }

        $this->stateStore->saveState($roomId, $state);

        broadcast(new PlayerForfeited($roomId, $user->id, $user->username, false));

        return response()->json([
            'status' => 'success',
            'message' => 'You left the match. Match continues for remaining players.',
            'data' => [
                'is_game_over' => false,
                'game_state' => $state,
            ],
        ]);
    }
}
