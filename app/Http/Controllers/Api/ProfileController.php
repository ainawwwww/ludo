<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * GET /api/v1/profile
     * Headers: Authorization: Bearer <token>
     *
     * Returns full profile for the authenticated user including game stats,
     * league info, and achievements.
     *
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "id": 1,
     *     "name": "player123",
     *     "level": 5,
     *     "avatar_url": "https://example.com/avatars/player123.png",
     *     "gender": "male",
     *     "dob": "2000-01-15",
     *     "country": "PK",
     *     "bio": "I love Ludo!",
     *     "total_games_played": 50,
     *     "total_wins": 30,
     *     "total_losses": 20,
     *     "win_rate": 60.0,
     *     "league_info": {
     *       "current_league": {
     *         "name": "Silver",
     *         "icon_url": "/images/leagues/silver.png"
     *       },
     *       "league_points": 1500,
     *       "points_needed_for_next_tier": 1000,
     *       "progress_status": "mid"
     *     },
     *     "achievements": {
     *       "level_badge": {
     *         "name": "Bronze",
     *         "icon": "/images/badges/level_bronze.png",
     *         "level": 5
     *       },
     *       "favorite_dice": null
     *     }
     *   }
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => new ProfileResource($user),
        ]);
    }

    /**
     * PUT /api/v1/profile
     * Headers: Authorization: Bearer <token>
     * Content-Type: multipart/form-data
     *
     * Updates the authenticated user's profile. Accepts multipart/form-data for avatar upload.
     *
     * Request Fields:
     *   name        (string, optional)  — Max 3 changes per rolling 24h period.
     *   avatar      (file, optional)    — Profile image. Max 2MB, jpg/png only.
     *   gender      (string, optional)  — male | female | unspecified
     *   dob         (date, optional)    — YYYY-MM-DD. Must be a past date, minimum age 13.
     *   country     (string, optional)  — Max 100 chars.
     *   bio         (string, optional)  — Max 255 chars.
     *
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "message": "Profile updated successfully",
     *   "data": { ... ProfileResource ... }
     * }
     *
     * Error Response — Name change limit exceeded (422):
     * {
     *   "status": "error",
     *   "message": "Name can only be changed 3 times per day"
     * }
     *
     * Error Response — Validation failure (422):
     * {
     *   "status": "error",
     *   "message": "The given data was invalid.",
     *   "errors": { ... }
     * }
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = [];

        // Handle name change with rolling 24-hour rate limiting
        if ($request->filled('name') && $request->input('name') !== $user->username) {
            $now = Carbon::now();

            // Reset counter if 24 hours have passed since last reset
            if ($user->name_change_reset_at === null || abs($now->diffInSeconds($user->name_change_reset_at)) >= 86400) {
                $user->name_change_count = 0;
                $user->name_change_reset_at = $now;
            }

            // Check if limit is reached
            if ($user->name_change_count >= 3) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Name can only be changed 3 times per day',
                ], 422);
            }

            $data['username'] = $request->input('name');
            $user->name_change_count = $user->name_change_count + 1;
            $data['name_change_count'] = $user->name_change_count;
            $data['name_change_reset_at'] = $user->name_change_reset_at;
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if stored locally
            if ($user->avatar_url) {
                $oldPath = str_replace('/storage/', '', $user->avatar_url);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar_url'] = '/storage/' . $path;
        }

        // Handle simple profile fields
        if ($request->filled('gender')) {
            $data['gender'] = $request->input('gender');
        }
        if ($request->has('dob')) {
            $data['dob'] = $request->input('dob');
        }
        if ($request->filled('country')) {
            $data['country'] = $request->input('country');
        }
        if ($request->has('bio')) {
            $data['bio'] = $request->input('bio');
        }

        if (!empty($data)) {
            $user->update($data);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'data' => new ProfileResource($user->fresh()),
        ]);
    }
}
