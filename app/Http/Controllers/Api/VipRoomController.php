<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoomType;
use App\Exceptions\PrivateRoomException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateVipRoomRequest;
use App\Http\Requests\JoinVipRoomRequest;
use App\Http\Resources\VipRoomResource;
use App\Models\Room;
use App\Services\PrivateRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VipRoomController extends Controller
{
    public function __construct(
        protected PrivateRoomService $privateRoomService
    ) {}

    /**
     * POST /api/v1/vip-rooms
     * Create a new VIP room.
     */
    public function create(CreateVipRoomRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->privateRoomService->create(
                $user,
                (int) $request->validated('max_players'),
                (int) $request->validated('entry_fee'),
                $request->validated('turn_seconds') !== null ? (int) $request->validated('turn_seconds') : null,
                $request->validated('title'),
                RoomType::VIP
            );

            $snapshot = $this->privateRoomService->snapshot($room, $user, RoomType::VIP);

            return (new VipRoomResource($snapshot))->response()->setStatusCode(201);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/vip-rooms/join
     * Join an existing VIP room by 6-character room code.
     */
    public function join(JoinVipRoomRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $roomCode = $request->validated('room_code');

            $player = $this->privateRoomService->join($user, $roomCode, RoomType::VIP);
            $snapshot = $this->privateRoomService->snapshot($player->room_id, $user, RoomType::VIP);

            return (new VipRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * GET /api/v1/vip-rooms/current
     * Returns the user's active VIP room snapshot (waiting or playing), or 404 if none.
     */
    public function current(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $activeRoom = $this->privateRoomService->getUserActiveRoom($user, RoomType::VIP);

            if (!$activeRoom) {
                return response()->json([
                    'error_code' => 'ROOM_NOT_FOUND',
                    'message' => 'No active VIP room found',
                ], 404);
            }

            $snapshot = $this->privateRoomService->snapshot($activeRoom, $user, RoomType::VIP);

            return (new VipRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * GET /api/v1/vip-rooms/{room}
     * Members-only view of a VIP room by ID.
     */
    public function show(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolveVipRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notFound();
            }

            $snapshot = $this->privateRoomService->snapshot($room, $user, RoomType::VIP);

            return (new VipRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/vip-rooms/{room}/ready
     * Toggle or set player ready state (members only).
     */
    public function ready(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolveVipRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notFound();
            }

            $isReady = $request->has('is_ready') ? $request->boolean('is_ready') : null;
            $this->privateRoomService->toggleReady($user, $room, $isReady, RoomType::VIP);

            $snapshot = $this->privateRoomService->snapshot($room, $user, RoomType::VIP);

            return (new VipRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/vip-rooms/{room}/leave
     * Leave a VIP room (members only). Cannot leave while match is in progress.
     */
    public function leave(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolveVipRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notFound();
            }

            $this->privateRoomService->leave($user, $room, RoomType::VIP);

            $freshRoom = Room::where('type', RoomType::VIP)->find($room->id);
            $snapshot = $this->privateRoomService->snapshot($freshRoom, $user, RoomType::VIP);

            return (new VipRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * POST /api/v1/vip-rooms/{room}/start
     * Start match in a VIP room (host only, full room, all guests ready).
     */
    public function start(Request $request, string $roomIdOrCode): JsonResponse
    {
        try {
            $user = $request->user();
            $room = $this->resolveVipRoom($roomIdOrCode);

            $isMember = $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                throw PrivateRoomException::notFound();
            }

            $this->privateRoomService->start($user, $room, RoomType::VIP);

            $freshRoom = Room::where('type', RoomType::VIP)->find($room->id);
            $snapshot = $this->privateRoomService->snapshot($freshRoom, $user, RoomType::VIP);

            return (new VipRoomResource($snapshot))->response()->setStatusCode(200);
        } catch (PrivateRoomException $e) {
            return $e->render($request);
        }
    }

    /**
     * Helper to resolve VIP room strictly by numeric ID.
     */
    protected function resolveVipRoom(string $idOrCode): Room
    {
        if (!ctype_digit((string) $idOrCode)) {
            throw PrivateRoomException::notFound();
        }

        $room = Room::where('type', RoomType::VIP)
            ->where('id', (int) $idOrCode)
            ->first();

        if (!$room) {
            throw PrivateRoomException::notFound();
        }

        return $room;
    }
}
