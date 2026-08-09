<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\RoomUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRoomRequest;
use App\Http\Requests\JoinRoomRequest;
use App\Http\Requests\QuickMatchRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    /**
     * GET /api/v1/rooms
     * Headers: Authorization: Bearer <token>
     */
    public function index(): JsonResponse
    {
        $rooms = Room::with(['creator', 'players.user'])
            ->where('status', RoomStatus::WAITING->value)
            ->where('type', RoomType::PUBLIC->value)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => RoomResource::collection($rooms),
        ]);
    }

    /**
     * POST /api/v1/rooms
     * Headers: Authorization: Bearer <token>
     */
    public function store(CreateRoomRequest $request): JsonResponse
    {
        $user = $request->user();
        $entryFee = $request->input('entry_fee', 100);

        $userCoins = $user->wallet ? (int) $user->wallet->coins_balance : (int) $user->coins;
        if ($userCoins < $entryFee) {
            return response()->json(['status' => 'error', 'message' => 'Insufficient coins for room entry fee'], 400);
        }

        $room = Room::create([
            'room_code' => strtoupper(Str::random(6)),
            'type' => $request->input('type', RoomType::PUBLIC->value),
            'max_players' => $request->input('max_players', 4),
            'entry_fee' => $entryFee,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        // Add creator as seat 1 (Red)
        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
            'is_ready' => true,
            'joined_at' => now(),
        ]);

        $room->load(['creator', 'players.user']);

        return response()->json([
            'status' => 'success',
            'message' => 'Room created successfully',
            'data' => new RoomResource($room),
        ], 201);
    }

    /**
     * GET /api/v1/rooms/{id_or_code}
     * Headers: Authorization: Bearer <token>
     * 
     * Accepts either a numeric room ID (e.g. /rooms/1) or a room_code string (e.g. /rooms/WTMZGN).
     */
    public function show(string $id): JsonResponse
    {
        if (is_numeric($id)) {
            $room = Room::with(['creator', 'players.user'])->findOrFail((int) $id);
        } else {
            $room = Room::with(['creator', 'players.user'])
                ->where('room_code', strtoupper($id))
                ->firstOrFail();
        }

        return response()->json([
            'status' => 'success',
            'data' => new RoomResource($room),
        ]);
    }

    /**
     * POST /api/v1/rooms/join
     * Headers: Authorization: Bearer <token>
     * 
     * Request Payload (JSON):
     * {
     *   "room_code": "LUDO88"
     * }
     */
    public function join(JoinRoomRequest $request): JsonResponse
    {
        $user = $request->user();
        $room = Room::where('room_code', $request->room_code)
            ->where('status', RoomStatus::WAITING->value)
            ->firstOrFail();

        if ($room->players()->count() >= $room->max_players) {
            return response()->json(['status' => 'error', 'message' => 'Room is full'], 409);
        }

        if ($room->players()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Already in room',
                'data' => new RoomResource($room->load(['creator', 'players.user'])),
            ]);
        }

        if ($user->coins < $room->entry_fee) {
            return response()->json(['status' => 'error', 'message' => 'Insufficient coins for room entry fee'], 400);
        }

        $this->assignSeatAndColor($room, $user);

        $room->load(['creator', 'players.user']);

        // Explicit WebSocket broadcast via Reverb
        broadcast(new RoomUpdated($room->id, (new RoomResource($room))->resolve()));

        return response()->json([
            'status' => 'success',
            'message' => 'Joined room successfully',
            'data' => new RoomResource($room),
        ]);
    }

    /**
     * POST /api/v1/rooms/quick-match
     * Headers: Authorization: Bearer <token>
     *
     * Request Payload (JSON):
     * {
     *   "max_players": 4,
     *   "entry_fee": 100
     * }
     *
     * This endpoint now delegates to the queue-based MatchmakingService.
     * Clients should listen for the "match.found" WebSocket event on their
     * private channel "user.{user_id}" after receiving a "waiting" status.
     *
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": { "status": "waiting"|"matched", ... }
     * }
     */
    public function quickMatch(QuickMatchRequest $request): JsonResponse
    {
        $user = $request->user();
        $maxPlayers = $request->input('max_players', 4);
        $entryFee = $request->input('entry_fee', 100);

        $userCoins = $user->wallet ? (int) $user->wallet->coins_balance : (int) $user->coins;
        if ($userCoins < $entryFee) {
            return response()->json(['status' => 'error', 'message' => 'Insufficient coins for entry fee'], 400);
        }

        $matchmakingService = app(\App\Services\MatchmakingService::class);
        $result = $matchmakingService->join($user, $maxPlayers, $entryFee);

        $httpStatus = $result['status'] === 'matched' ? 200 : 200;

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ], $httpStatus);
    }

    /**
     * Helper to automatically assign next available seat position (1..4) and color.
     */
    private function assignSeatAndColor(Room $room, User $user): RoomPlayer
    {
        $existingPlayers = $room->players()->get();
        $takenSeats = $existingPlayers->pluck('seat_position')->toArray();
        $takenColors = $existingPlayers->pluck('color')->map(fn($c) => $c->value ?? $c)->toArray();

        $colorMap = [
            1 => PlayerColor::RED->value,
            2 => PlayerColor::GREEN->value,
            3 => PlayerColor::YELLOW->value,
            4 => PlayerColor::BLUE->value,
        ];

        $assignedSeat = 1;
        for ($s = 1; $s <= 4; $s++) {
            if (!in_array($s, $takenSeats, true)) {
                $assignedSeat = $s;
                break;
            }
        }

        $assignedColor = $colorMap[$assignedSeat] ?? PlayerColor::RED->value;
        if (in_array($assignedColor, $takenColors, true)) {
            foreach ($colorMap as $c) {
                if (!in_array($c, $takenColors, true)) {
                    $assignedColor = $c;
                    break;
                }
            }
        }

        return RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'seat_position' => $assignedSeat,
            'color' => $assignedColor,
            'is_ready' => true,
            'joined_at' => now(),
        ]);
    }
}
