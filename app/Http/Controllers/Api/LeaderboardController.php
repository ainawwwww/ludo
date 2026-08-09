<?php

namespace App\Http\Controllers\Api;

use App\Enums\FriendStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardResource;
use App\Models\Friend;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    /**
     * GET /api/v1/leaderboard?type=global|country|friends
     * Headers: Authorization: Bearer <token>
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "type": "global",
     *   "data": [
     *     {
     *       "rank": 1,
     *       "user_id": 1,
     *       "username": "ludo_king",
     *       "avatar_url": "https://example.com/avatar.png",
     *       "country": "PK",
     *       "total_wins": 25,
     *       "total_games": 30,
     *       "win_rate": 83.33
     *     }
     *   ],
     *   "pagination": {
     *     "current_page": 1,
     *     "last_page": 1,
     *     "total": 1
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'global');
        $currentUser = $request->user();

        $query = User::query()
            ->select('users.id', 'users.username', 'users.avatar_url', 'users.country')
            ->selectSub(
                DB::table('games')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('games.winner_id', 'users.id')
                    ->where('games.status', 'completed'),
                'total_wins'
            )
            ->selectSub(
                DB::table('room_players')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('room_players.user_id', 'users.id'),
                'total_games'
            );

        if ($type === 'country') {
            $userCountry = $currentUser->country ?? 'PK';
            $query->where('users.country', $userCountry);
        } elseif ($type === 'friends') {
            $friendIds = Friend::where('user_id', $currentUser->id)
                ->where('status', FriendStatus::ACCEPTED->value)
                ->pluck('friend_id')
                ->toArray();

            $friendIds[] = $currentUser->id; // Include user themselves
            $query->whereIn('users.id', array_unique($friendIds));
        }

        $users = $query->orderByDesc('total_wins')
            ->orderByDesc('total_games')
            ->paginate(20);

        // Assign rank numbers dynamically based on pagination
        $startRank = ($users->currentPage() - 1) * $users->perPage() + 1;
        $rankedCollection = $users->getCollection()->map(function ($u, $index) use ($startRank) {
            $u->rank = $startRank + $index;
            return $u;
        });

        return response()->json([
            'status' => 'success',
            'type' => $type,
            'data' => LeaderboardResource::collection($rankedCollection),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ]
        ]);
    }
}
