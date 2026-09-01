<?php

namespace App\Http\Controllers\Api;

use App\Enums\FriendStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoomResource;
use App\Http\Resources\UserResource;
use App\Models\Friend;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomVisit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LobbyController extends Controller
{
    /**
     * GET /api/v1/lobby/explore?country={code}&page={page}&per_page={per_page}
     */
    public function explore(Request $request): JsonResponse
    {
        $country = $request->query('country');
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        // Define quick entry cards with rich metadata and actions
        $quickEntryCards = [
            [
                'id' => 'new_here',
                'title' => 'New here',
                'description' => 'Casual hangout for newcomers',
                'bg_asset' => 'assets/graphics/card_private.png',
                'gradient' => ['#00B2FF', '#0072BC'],
                'filter_category' => 'social',
                'tags' => ['Beginner', 'Casual'],
            ],
            [
                'id' => 'find_friends',
                'title' => 'Find Friends',
                'description' => 'Make new gaming buddies',
                'bg_asset' => 'assets/graphics/card_team.png',
                'gradient' => ['#56AB2F', '#1D976C'],
                'filter_category' => 'friends',
                'tags' => ['Social', 'Chat'],
            ],
            [
                'id' => 'small_talk',
                'title' => 'Small talk',
                'description' => 'Casual conversation & chill games',
                'bg_asset' => 'assets/graphics/card_domino_1v1.png',
                'gradient' => ['#00D2FF', '#0072BC'],
                'filter_category' => 'chat',
                'tags' => ['Chills', 'Talk'],
            ],
            [
                'id' => 'enjoy_music',
                'title' => 'Enjoy Music',
                'description' => 'Listen to beats & play together',
                'bg_asset' => 'assets/graphics/card_vip.png',
                'gradient' => ['#8E2DE2', '#4A00E0'],
                'filter_category' => 'music',
                'tags' => ['Music', 'Party'],
            ],
        ];

        // Query recommended rooms
        $query = Room::with(['creator', 'players.user'])
            ->where('is_live', true);

        if (!empty($country)) {
            $query->where('country_code', strtoupper($country));
        }

        $rooms = $query->orderBy('member_count', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'quick_entry_cards' => $quickEntryCards,
                'recommended_rooms' => RoomResource::collection($rooms->items()),
                'pagination' => [
                    'current_page' => $rooms->currentPage(),
                    'last_page' => $rooms->lastPage(),
                    'per_page' => $rooms->perPage(),
                    'total' => $rooms->total(),
                    'has_more' => $rooms->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/lobby/hot?page={page}&per_page={per_page}
     */
    public function hot(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        // Popular live hosts (Users who currently host live rooms, ordered by audience size)
        $liveRooms = Room::with(['creator', 'players.user'])
            ->where('is_live', true)
            ->orderBy('member_count', 'desc')
            ->get();

        $popularHosts = $liveRooms->map(function ($room) {
            $creator = $room->creator;
            return [
                'id' => $creator?->id ?? $room->created_by,
                'user_id' => $creator?->id ?? $room->created_by,
                'username' => $creator?->username ?? 'Host',
                'avatar_url' => $creator?->avatar_url,
                'country_code' => $creator?->country_code ?? $room->country_code,
                'badge' => '🔥',
                'active_room' => [
                    'room_id' => $room->id,
                    'room_code' => $room->room_code,
                    'title' => $room->title ?? 'Live Room',
                    'member_count' => $room->member_count,
                ],
            ];
        })->unique('user_id')->values()->take(10);

        // Trending live rooms
        $trendingRooms = Room::with(['creator', 'players.user'])
            ->where('is_live', true)
            ->orderBy('member_count', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'popular_hosts' => $popularHosts,
                'trending_rooms' => RoomResource::collection($trendingRooms->items()),
                'pagination' => [
                    'current_page' => $trendingRooms->currentPage(),
                    'last_page' => $trendingRooms->lastPage(),
                    'per_page' => $trendingRooms->perPage(),
                    'total' => $trendingRooms->total(),
                    'has_more' => $trendingRooms->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/lobby/my?filter={recently|joined|following|friends}&page={page}&per_page={per_page}
     */
    public function my(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = strtolower($request->query('filter', 'recently'));
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        $query = Room::with(['creator', 'players.user']);

        switch ($filter) {
            case 'joined':
                // Rooms where user is seated / active player
                $roomIds = RoomPlayer::where('user_id', $user->id)
                    ->pluck('room_id')
                    ->toArray();

                $query->whereIn('id', $roomIds)->orderBy('id', 'desc');
                break;

            case 'following':
                // Rooms created by users the requester follows
                $followedUserIds = $user->following()->pluck('followed_user_id')->toArray();
                $query->whereIn('created_by', $followedUserIds)->orderBy('id', 'desc');
                break;

            case 'friends':
                // Rooms where requester's accepted friends are in room_players
                $friendIds = Friend::where('user_id', $user->id)
                    ->where('status', FriendStatus::ACCEPTED->value)
                    ->pluck('friend_id')
                    ->toArray();

                $roomIds = RoomPlayer::whereIn('user_id', $friendIds)
                    ->pluck('room_id')
                    ->toArray();

                $query->whereIn('id', $roomIds)->orderBy('id', 'desc');
                break;

            case 'recently':
            default:
                // Rooms user recently visited
                $recentRoomVisits = RoomVisit::where('user_id', $user->id)
                    ->orderBy('visited_at', 'desc')
                    ->pluck('room_id')
                    ->unique()
                    ->values()
                    ->toArray();

                if (!empty($recentRoomVisits)) {
                    $cases = [];
                    foreach ($recentRoomVisits as $index => $roomId) {
                        $cases[] = "WHEN " . (int) $roomId . " THEN " . (int) $index;
                    }
                    $caseSql = "CASE id " . implode(' ', $cases) . " ELSE 99999 END";
                    $query->whereIn('id', $recentRoomVisits)
                        ->orderByRaw($caseSql);
                } else {
                    $query->whereRaw('1 = 0'); // Empty if no visits
                }
                break;
        }

        $rooms = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'filter' => $filter,
                'rooms' => RoomResource::collection($rooms->items()),
                'pagination' => [
                    'current_page' => $rooms->currentPage(),
                    'last_page' => $rooms->lastPage(),
                    'per_page' => $rooms->perPage(),
                    'total' => $rooms->total(),
                    'has_more' => $rooms->hasMorePages(),
                ],
            ],
        ]);
    }
}
