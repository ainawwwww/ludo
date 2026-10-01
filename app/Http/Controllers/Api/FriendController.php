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
        $userId = $request->user()->id;
        $status = $request->input('status', FriendStatus::ACCEPTED->value);

        if ($status === 'all') {
            $friends = Friend::with(['user', 'friend'])
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->orWhere('friend_id', $userId);
                })
                ->get();
        } else {
            $friends = Friend::with(['user', 'friend'])
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->orWhere('friend_id', $userId);
                })
                ->where('status', $status)
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => FriendResource::collection($friends),
        ]);
    }

    /**
     * GET /api/v1/friends/requests
     * Headers: Authorization: Bearer <token>
     */
    public function incomingRequests(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $requests = Friend::with(['user', 'friend'])
            ->where('friend_id', $userId)
            ->where('status', FriendStatus::PENDING->value)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => FriendResource::collection($requests),
        ]);
    }

    /**
     * POST /api/v1/friends/request
     * Headers: Authorization: Bearer <token>
     */
    public function sendRequest(SendFriendRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $friendId = (int) $request->friend_id;

        if ($userId === $friendId) {
            return response()->json(['status' => 'error', 'message' => 'You cannot add yourself as a friend'], 400);
        }

        $existing = Friend::where(function ($q) use ($userId, $friendId) {
            $q->where('user_id', $userId)->where('friend_id', $friendId);
        })->orWhere(function ($q) use ($userId, $friendId) {
            $q->where('user_id', $friendId)->where('friend_id', $userId);
        })->first();

        if ($existing) {
            if ($existing->status === FriendStatus::ACCEPTED->value) {
                return response()->json(['status' => 'error', 'message' => 'Already friends'], 400);
            }
            return response()->json(['status' => 'error', 'message' => 'Friend request already exists or pending'], 400);
        }

        $friendship = Friend::create([
            'user_id' => $userId,
            'friend_id' => $friendId,
            'status' => FriendStatus::PENDING->value,
            'created_at' => now(),
        ]);

        $friendship->load(['user', 'friend']);

        return response()->json([
            'status' => 'success',
            'message' => 'Friend request sent successfully',
            'data' => new FriendResource($friendship),
        ]);
    }

    /**
     * POST /api/v1/friends/{id}/respond
     * Headers: Authorization: Bearer <token>
     */
    public function respondRequest(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:accepted,declined,blocked']);

        $friendship = Friend::where('id', $id)
            ->where('friend_id', $request->user()->id)
            ->firstOrFail();

        if ($request->status === 'declined') {
            $friendship->delete();
            return response()->json([
                'status' => 'success',
                'message' => 'Friend request declined',
            ]);
        }

        $friendship->update(['status' => $request->status]);
        $friendship->load(['user', 'friend']);

        return response()->json([
            'status' => 'success',
            'message' => "Friend request {$request->status}",
            'data' => new FriendResource($friendship),
        ]);
    }
}
