<?php

namespace App\Http\Controllers\Api;

use App\Enums\FriendStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendFriendRequest;
use App\Http\Resources\FriendResource;
use App\Models\Friend;
use App\Models\User;

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
     * GET /api/v1/friends/search?query=...
     * Headers: Authorization: Bearer <token>
     */
    public function search(Request $request): JsonResponse
    {
        $currentUserId = $request->user()->id;
        $query = trim($request->input('query', ''));

        if (empty($query)) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ]);
        }

        // Search by numeric ID or by username (case-insensitive substring)
        $users = User::where('id', '!=', $currentUserId)
            ->where(function ($q) use ($query) {
                if (is_numeric($query)) {
                    $q->where('id', (int) $query)
                      ->orWhere('username', 'LIKE', "%{$query}%");
                } else {
                    $q->where('username', 'LIKE', "%{$query}%");
                }
            })
            ->take(20)
            ->get();

        $targetUserIds = $users->pluck('id')->toArray();

        // Fetch existing friendships with current user
        $friendships = Friend::where(function ($q) use ($currentUserId, $targetUserIds) {
            $q->where('user_id', $currentUserId)->whereIn('friend_id', $targetUserIds);
        })->orWhere(function ($q) use ($currentUserId, $targetUserIds) {
            $q->whereIn('user_id', $targetUserIds)->where('friend_id', $currentUserId);
        })->get();

        $friendshipMap = [];
        $friendshipIdMap = [];
        foreach ($friendships as $f) {
            $otherId = ($f->user_id === $currentUserId) ? $f->friend_id : $f->user_id;
            $friendshipIdMap[$otherId] = $f->id;
            if ($f->status === FriendStatus::ACCEPTED->value) {
                $friendshipMap[$otherId] = 'accepted';
            } elseif ($f->status === FriendStatus::PENDING->value) {
                $friendshipMap[$otherId] = ($f->user_id === $currentUserId) ? 'pending_sent' : 'pending_received';
            } else {
                $friendshipMap[$otherId] = $f->status instanceof FriendStatus ? $f->status->value : (string) $f->status;
            }
        }

        $results = $users->map(function ($u) use ($friendshipMap, $friendshipIdMap) {
            return [
                'id' => $u->id,
                'user_id' => $u->id,
                'username' => $u->username,
                'name' => $u->username,
                'avatar_url' => $u->avatar_url,
                'level' => $u->level ?? 1,
                'is_guest' => (bool) $u->is_guest,
                'friendship_status' => $friendshipMap[$u->id] ?? 'none',
                'friend_request_id' => $friendshipIdMap[$u->id] ?? null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $results,
        ]);
    }

    /**
     * POST /api/v1/friends/request
     * Headers: Authorization: Bearer <token>
     */
    public function sendRequest(SendFriendRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $friendId = $request->friend_id ? (int) $request->friend_id : null;

        if (!$friendId && $request->filled('username')) {
            $targetUser = User::where('username', trim($request->username))->first();
            if ($targetUser) {
                $friendId = $targetUser->id;
            }
        }

        if (!$friendId) {
            return response()->json(['status' => 'error', 'message' => 'Target player not found'], 404);
        }

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

        broadcast(new \App\Events\FriendRequestUpdated($userId, $friendId, 'pending', $friendship->id));

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

        $senderId = $friendship->user_id;
        $receiverId = $friendship->friend_id;

        if ($request->status === 'declined') {
            $friendship->delete();
            broadcast(new \App\Events\FriendRequestUpdated($senderId, $receiverId, 'declined', $id));
            return response()->json([
                'status' => 'success',
                'message' => 'Friend request declined',
            ]);
        }

        $friendship->update(['status' => $request->status]);
        $friendship->load(['user', 'friend']);

        broadcast(new \App\Events\FriendRequestUpdated($senderId, $receiverId, $request->status, $friendship->id));

        return response()->json([
            'status' => 'success',
            'message' => "Friend request {$request->status}",
            'data' => new FriendResource($friendship),
        ]);
    }
}
