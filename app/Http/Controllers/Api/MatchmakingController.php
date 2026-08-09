<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MatchmakingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchmakingController extends Controller
{
    private MatchmakingService $matchmakingService;

    public function __construct(MatchmakingService $matchmakingService)
    {
        $this->matchmakingService = $matchmakingService;
    }

    /**
     * POST /api/v1/matchmaking/join
     * Headers: Authorization: Bearer <token>
     *
     * Adds the authenticated user to the matchmaking queue. If enough players are
     * waiting, a match is created atomically and all matched players receive a
     * "MatchFound" WebSocket event on their private channel (user.{user_id}).
     *
     * Request Payload (JSON):
     * {
     *   "max_players": 2,      // 2 or 4 (default: 2)
     *   "entry_fee": 100       // integer >= 0 (default: 0)
     * }
     *
     * Success Response — Waiting (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "status": "waiting",
     *     "queue_position": 1
     *   }
     * }
     *
     * Success Response — Matched Immediately (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "status": "matched",
     *     "room_id": 42,
     *     "game_id": 15,
     *     "players": [
     *       { "user_id": 1, "username": "player1", "avatar_url": null, "seat_position": 1, "color": "red" },
     *       { "user_id": 2, "username": "player2", "avatar_url": null, "seat_position": 2, "color": "green" }
     *     ]
     *   }
     * }
     *
     * Note: After calling this endpoint with status "waiting", clients should listen
     * for the "match.found" WebSocket event on their private channel "user.{user_id}"
     * using Laravel Reverb. The event payload contains { room_id, game_id, players[] }.
     */
    public function join(Request $request): JsonResponse
    {
        $request->validate([
            'max_players' => 'nullable|integer|in:2,4',
            'entry_fee' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();
        $maxPlayers = $request->input('max_players', 2);
        $entryFee = $request->input('entry_fee', 0);

        // Check sufficient coins
        $userCoins = $user->wallet ? (int) $user->wallet->coins_balance : (int) $user->coins;
        if ($entryFee > 0 && $userCoins < $entryFee) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient coins for entry fee',
            ], 400);
        }

        $result = $this->matchmakingService->join($user, $maxPlayers, $entryFee);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/v1/matchmaking/leave
     * Headers: Authorization: Bearer <token>
     *
     * Removes the authenticated user from whichever matchmaking queue they are in.
     *
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "message": "Left matchmaking queue"
     * }
     */
    public function leave(Request $request): JsonResponse
    {
        $result = $this->matchmakingService->leave($request->user());

        return response()->json($result);
    }

    /**
     * GET /api/v1/matchmaking/status
     * Headers: Authorization: Bearer <token>
     *
     * Returns the user's current matchmaking status. Useful for clients that
     * prefer polling over WebSocket listening for the MatchFound event.
     *
     * Response — Queued (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "status": "queued",
     *     "queue_position": 1,
     *     "queue_size": 1,
     *     "max_players": 2,
     *     "entry_fee": 100
     *   }
     * }
     *
     * Response — Idle (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "status": "idle",
     *     "message": "Not in any matchmaking queue"
     *   }
     * }
     */
    public function status(Request $request): JsonResponse
    {
        $result = $this->matchmakingService->status($request->user());

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
