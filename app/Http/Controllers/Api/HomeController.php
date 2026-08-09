<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HomeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * GET /api/v1/home
     * Headers: Authorization: Bearer <token>
     *
     * Returns home screen data for the authenticated user.
     *
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "username": "player123",
     *     "level": 5,
     *     "coins": 10000,
     *     "diamonds": 100,
     *     "current_league": {
     *       "name": "Bronze",
     *       "icon_url": "/images/leagues/bronze.png"
     *     },
     *     "global_rank": 42,
     *     "avatar_url": "https://example.com/avatars/player123.png"
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('wallet');

        return response()->json([
            'status' => 'success',
            'data' => new HomeResource($user),
        ]);
    }
}
