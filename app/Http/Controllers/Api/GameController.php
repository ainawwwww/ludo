<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;

use App\Events\DiceRolled;
use App\Events\GameEnded;
use App\Events\GameStarted;
use App\Events\TokenMoved;
use App\Events\TurnChanged;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveTokenRequest;
use App\Http\Requests\RollDiceRequest;

use App\Jobs\ProcessTurnTimeout;

use App\Models\Game;
use App\Models\GameMove;
use App\Models\Room;

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
        $request->validate(['room_id' => 'required|integer|exists:rooms,id']);
        $room = Room::with('players.user')->findOrFail($request->room_id);

        if ($room->created_by !== $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'Only room host can start game'], 403);
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
     * GET /api/v1/game/state?room_id=1
     * Headers: Authorization: Bearer <token>
     */
    public function getGameState(Request $request): JsonResponse
    {
        $roomId = (int) $request->query('room_id');
        $state = $this->stateStore->getState($roomId);

        if (!$state) {
            return response()->json(['status' => 'error', 'message' => 'Active game state not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $state,
        ]);
    }

    /**
     * POST /api/v1/game/roll
     * Headers: Authorization: Bearer <token>
     */
    public function rollDice(RollDiceRequest $request): JsonResponse
    {
        $user = $request->user();
        $state = $this->stateStore->getState($request->room_id);

        if (!$state || $state['status'] !== 'in_progress') {
            return response()->json(['status' => 'error', 'message' => 'No active game in room'], 400);
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

            $this->stateStore->saveState($request->room_id, $state);

            broadcast(new DiceRolled($request->room_id, $seat, $user->id, $diceRoll, []));
            broadcast(new TurnChanged($request->room_id, $nextSeat, $state['current_turn_user_id']));

            ProcessTurnTimeout::dispatch($request->room_id, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds(20));

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

            $this->stateStore->saveState($request->room_id, $state);

            broadcast(new DiceRolled($request->room_id, $seat, $user->id, $diceRoll, []));
            broadcast(new TurnChanged($request->room_id, $nextSeat, $state['current_turn_user_id'], $hasExtraTurn));

            ProcessTurnTimeout::dispatch($request->room_id, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds(20));

            return response()->json([
                'status' => 'success',
                'message' => 'No legal moves. Turn passed.',
                'data' => $state,
            ]);
        }

        $state['can_roll'] = false;
        $state['must_move'] = true;
        $this->stateStore->saveState($request->room_id, $state);

        broadcast(new DiceRolled($request->room_id, $seat, $user->id, $diceRoll, $movableTokens));

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
     * POST /api/v1/game/move
     * Headers: Authorization: Bearer <token>
     */
    public function moveToken(MoveTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $state = $this->stateStore->getState($request->room_id);

        if (!$state || $state['status'] !== 'in_progress') {
            return response()->json(['status' => 'error', 'message' => 'No active game in room'], 400);
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
            $request->room_id,
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
            $this->stateStore->saveState($request->room_id, $state);

            $game = Game::find($state['game_id']);
            if ($game) {
                $game->update([
                    'winner_id' => $user->id,
                    'status' => GameStatus::COMPLETED->value,
                    'ended_at' => now(),
                ]);
            }

            Room::where('id', $request->room_id)->update(['status' => RoomStatus::FINISHED->value]);

            broadcast(new GameEnded($request->room_id, $state['game_id'], $user->id, $user->username, 400));

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

        $this->stateStore->saveState($request->room_id, $state);

        broadcast(new TurnChanged($request->room_id, $nextSeat, $state['current_turn_user_id'], $grantExtraTurn));

        ProcessTurnTimeout::dispatch($request->room_id, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds(20));

        return response()->json([
            'status' => 'success',
            'data' => [
                'move_result' => $moveResult,
                'game_state' => $state,
            ]
        ]);
    }
}
