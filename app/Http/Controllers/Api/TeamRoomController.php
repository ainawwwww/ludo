<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoomType;
use App\Exceptions\PrivateRoomException;
use App\Http\Controllers\Controller;
use App\Services\TeamLobbyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TeamRoomController extends Controller
{
    public function __construct(
        protected TeamLobbyService $teamLobbyService
    ) {}

    /**
     * Create a 2-seat Team Party Lobby (CREATE path).
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_fee' => ['sometimes', 'integer', 'min:0'],
            'turn_seconds' => ['sometimes', 'integer', 'in:15,30,45'],
        ]);

        try {
            $user = $request->user();
            $fee = $validated['entry_fee'] ?? 500;
            $turnSeconds = $validated['turn_seconds'] ?? 15;

            $room = $this->teamLobbyService->create($user, $fee, $turnSeconds);

            return response()->json([
                'status' => 'success',
                'message' => 'Team room created successfully.',
                'data' => [
                    'room' => $this->formatRoomPayload($room),
                ],
            ], 201);
        } catch (PrivateRoomException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Join an existing Team Party Lobby via 6-digit code (JOIN path).
     */
    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $user = $request->user();
            $room = $this->teamLobbyService->join($user, $validated['code']);

            return response()->json([
                'status' => 'success',
                'message' => 'Joined team room successfully.',
                'data' => [
                    'room' => $this->formatRoomPayload($room),
                ],
            ]);
        } catch (PrivateRoomException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        }
    }

    /**
     * Get current active team lobby room for authenticated user.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $room = $this->teamLobbyService->getUserActiveTeamRoom($user);

        if (!$room) {
            return response()->json([
                'status' => 'success',
                'data' => ['room' => null],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'room' => $this->formatRoomPayload($room),
            ],
        ]);
    }

    /**
     * Show team room by code (Members only guard).
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $room = $this->teamLobbyService->getUserActiveTeamRoom($user);

        if (!$room || strtoupper($room->room_code) !== strtoupper($code)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Team room not found or access denied.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'room' => $this->formatRoomPayload($room),
            ],
        ]);
    }

    /**
     * Leave team lobby.
     */
    public function leave(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $result = $this->teamLobbyService->leave($user);

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'data' => [
                    'action' => $result['status'],
                    'room' => $this->formatRoomPayload($result['room']),
                ],
            ]);
        } catch (PrivateRoomException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        }
    }

    /**
     * Toggle ready state for guest in team party lobby.
     */
    public function ready(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_ready' => ['sometimes', 'boolean'],
        ]);

        try {
            $user = $request->user();
            $room = $this->teamLobbyService->toggleReady($user, $validated['is_ready'] ?? null);

            return response()->json([
                'status' => 'success',
                'message' => 'Ready state updated.',
                'data' => [
                    'room' => $this->formatRoomPayload($room),
                ],
            ]);
        } catch (PrivateRoomException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        }
    }

    /**
     * Join 2v2 Team Matchmaking as a Solo player (SINGLE path).
     */
    public function joinSolo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_fee' => ['sometimes', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $entryFee = $validated['entry_fee'] ?? 500;

        $matchmakingService = app(\App\Services\MatchmakingService::class);
        $result = $matchmakingService->joinTeamSolo($user, $entryFee);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Enqueue a ready 2-player team lobby into the unified 2v2 matchmaking queue (CREATE / JOIN path).
     */
    public function readyForMatch(Request $request): JsonResponse
    {
        $user = $request->user();
        $room = $this->teamLobbyService->getUserActiveTeamRoom($user);

        if (!$room || (int) $room->created_by !== (int) $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only team room host can initiate matchmaking.',
            ], 403);
        }

        if (!$this->teamLobbyService->canQueueForMatch($room)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Team room is not ready for matchmaking. Both players must be present and ready.',
            ], 409);
        }

        $matchmakingService = app(\App\Services\MatchmakingService::class);
        $result = $matchmakingService->enqueueTeamPair($room);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Format standardized payload for room response.
     */
    private function formatRoomPayload($room): array
    {
        if (!$room) {
            return [];
        }

        $players = [];
        foreach ($room->players as $p) {
            $u = $p->user;
            $players[] = [
                'user_id' => $p->user_id,
                'username' => $u ? $u->username : 'Player',
                'avatar_url' => $u ? $u->avatar_url : null,
                'seat_position' => $p->seat_position,
                'color' => $p->color instanceof \App\Enums\PlayerColor ? $p->color->value : (string) $p->color,
                'is_ready' => (bool) $p->is_ready,
                'is_host' => ((int) $p->user_id === (int) $room->created_by),
            ];
        }

        return [
            'id' => $room->id,
            'room_code' => $room->room_code,
            'type' => $room->type instanceof RoomType ? $room->type->value : (string) $room->type,
            'status' => $room->status instanceof \App\Enums\RoomStatus ? $room->status->value : (string) $room->status,
            'max_players' => $room->max_players,
            'member_count' => $room->member_count,
            'entry_fee' => $room->entry_fee,
            'turn_seconds' => $room->turn_seconds,
            'state_version' => $room->state_version,
            'created_by' => $room->created_by,
            'can_start' => $this->teamLobbyService->canQueueForMatch($room),
            'players' => $players,
        ];
    }
}
