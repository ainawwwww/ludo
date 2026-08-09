<?php

namespace App\Http\Controllers\Api;

use App\Enums\FriendStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendFriendRequest;
use App\Http\Resources\FriendResource;
use App\Models\Friend;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FriendController extends Controller
{
    /**
     * GET /api/v1/friends
     * Headers: Authorization: Bearer <token>
     */
    public function index(Request $request): JsonResponse
    {
        $friends = Friend::with('friend')
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => FriendResource::collection($friends),
        ]);
    }

    /**
     * POST /api/v1/friends/request
     * Headers: Authorization: Bearer <token>
     * 
     * Request Payload (JSON):
     * {
     *   "friend_id": 2
     * }
     */
    public function sendRequest(SendFriendRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $friendId = $request->friend_id;

        $existing = Friend::where('user_id', $userId)
            ->where('friend_id', $friendId)
            ->first();

        if ($existing) {
            return response()->json(['status' => 'error', 'message' => 'Friend request already sent or exists'], 400);
        }

        $friendship = Friend::create([
            'user_id' => $userId,
            'friend_id' => $friendId,
            'status' => FriendStatus::PENDING->value,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Friend request sent',
            'data' => new FriendResource($friendship),
        ]);
    }

    /**
     * POST /api/v1/friends/{id}/respond
     * Headers: Authorization: Bearer <token>
     * 
     * Request Payload (JSON):
     * {
     *   "status": "accepted"
     * }
     */
    public function respondRequest(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:accepted,blocked']);

        $friendship = Friend::where('id', $id)
            ->where('friend_id', $request->user()->id)
            ->firstOrFail();

        $friendship->update(['status' => $request->status]);

        return response()->json([
            'status' => 'success',
            'message' => "Friend request $request->status",
            'data' => new FriendResource($friendship),
        ]);
    }
}
