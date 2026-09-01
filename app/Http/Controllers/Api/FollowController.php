<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    /**
     * POST /api/v1/users/{id}/follow
     */
    public function follow(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user->id === $id) {
            return response()->json(['status' => 'error', 'message' => 'You cannot follow yourself'], 400);
        }

        $targetUser = User::findOrFail($id);

        $follow = Follow::firstOrCreate([
            'user_id' => $user->id,
            'followed_user_id' => $targetUser->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "You are now following {$targetUser->username}",
            'data' => [
                'is_following' => true,
                'followed_user_id' => $targetUser->id,
            ],
        ]);
    }

    /**
     * POST /api/v1/users/{id}/unfollow
     */
    public function unfollow(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $targetUser = User::findOrFail($id);

        Follow::where('user_id', $user->id)
            ->where('followed_user_id', $targetUser->id)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => "You unfollowed {$targetUser->username}",
            'data' => [
                'is_following' => false,
                'followed_user_id' => $targetUser->id,
            ],
        ]);
    }

    /**
     * GET /api/v1/users/{id}/follow-status
     */
    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isFollowing = Follow::where('user_id', $user->id)
            ->where('followed_user_id', $id)
            ->exists();

        return response()->json([
            'status' => 'success',
            'data' => [
                'is_following' => $isFollowing,
                'user_id' => $id,
            ],
        ]);
    }
}
