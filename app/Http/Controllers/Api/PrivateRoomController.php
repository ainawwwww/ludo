<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoomType;
use App\Exceptions\PrivateRoomException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePrivateRoomRequest;
use App\Http\Requests\JoinPrivateRoomRequest;
use App\Http\Resources\PrivateRoomResource;
use App\Models\Room;
use App\Services\PrivateRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivateRoomController extends Controller
{
    public function __construct(
        protected PrivateRoomService $privateRoomService
    ) {}

    /**
     * POST /api/v1/private-rooms
     * Create a new private room.
     */
    public function create(CreatePrivateRoomRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->privateRoomService->create(
                $user,
                (int) $request->validated('max_players'),
                (int) $request->validated('entry_fee'),
                $request->validated('turn_seconds') !== null ? (int) $request->validated('turn_seconds') : null,
                $request->validated('title')
            );

            $snapshot = $this->privateRoomService->snapshot($room, $user);

            return (new PrivateRoomResource($snapshot))->response()->setStatusCode(201);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/private-rooms/join
     * Join an existing private room by 6-character room code.
     * Rate limited via config.
     */
    public function join(JoinPrivateRoomRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $roomCode = $request->validated('room_code');

            $player = $this->privateRoomService->join($user, $roomCode);
            $snapshot = $this->privateRoomService->snapshot($player->room_id, $user);

            return (new PrivateRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * GET /api/v1/private-rooms/current
     * Returns the user's active private room snapshot (waiting or playing), or 404 if none.
     */
    public function current(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $activeRoom = $this->privateRoomService->getUserActiveRoom($user);

            if (!$activeRoom) {
                return response()->json([
                    'error_code' => 'ROOM_NOT_FOUND',
                    'message' => 'No active private room found',
                ], 404);
            }

            $snapshot = $this->privateRoomService->snapshot($activeRoom, $user);

            return (new PrivateRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * GET /api/v1/private-rooms/{room}
     * Members-only view of a private room by ID or code.
     */
    public function show(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolvePrivateRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notMember();
            }

            $snapshot = $this->privateRoomService->snapshot($room, $user);

            return (new PrivateRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/private-rooms/{room}/ready
     * Toggle or set player ready state (members only).
     */
    public function ready(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolvePrivateRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notMember();
            }

            $isReady = $request->has('is_ready') ? $request->boolean('is_ready') : null;
            $this->privateRoomService->toggleReady($user, $room, $isReady);

            $snapshot = $this->privateRoomService->snapshot($room, $user);

            return (new PrivateRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/private-rooms/{room}/leave
     * Leave a private room (members only). Cannot leave while match is in progress.
     */
    public function leave(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolvePrivateRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notMember();
            }

            $this->privateRoomService->leave($user, $room);

            // Fresh snapshot after leave
            $freshRoom = Room::where('type', RoomType::PRIVATE)->find($room->id);
            $snapshot = $this->privateRoomService->snapshot($freshRoom, $user);

            return (new PrivateRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/private-rooms/{room}/start
     * Start match in a private room (host only, full room, all guests ready).
     */
    public function start(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolvePrivateRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notMember();
            }

            $this->privateRoomService->start($user, $room);

            $freshRoom = Room::where('type', RoomType::PRIVATE)->find($room->id);
            $snapshot = $this->privateRoomService->snapshot($freshRoom, $user);

            return (new PrivateRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * Helper to resolve private room by numeric ID or alphanumeric code.
     *
     * @throws PrivateRoomException
     */
    protected function resolvePrivateRoom(string $idOrCode): Room
    {
        $query = Room::where('type', RoomType::PRIVATE);

        if (is_numeric($idOrCode)) {
            $room = $query->where('id', (int) $idOrCode)->first();
        } else {
            $room = $query->where('room_code', strtoupper(trim($idOrCode)))->first();
        }

        if (!$room) {
            throw PrivateRoomException::notFound();
        }

        return $room;
    }
}
