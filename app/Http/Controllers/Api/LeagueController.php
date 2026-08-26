<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeagueResource;
use App\Models\LeagueDivisionMember;
use App\Models\LeagueSeason;
use App\Models\LeagueTier;
use App\Services\League\LeagueSeasonService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeagueController extends Controller
{
    private LeagueSeasonService $seasonService;

    public function __construct(LeagueSeasonService $seasonService)
    {
        $this->seasonService = $seasonService;
    }

    /**
     * GET /api/v1/leagues
     * Headers: Authorization: Bearer <token>
     *
     * Returns all 5 league tiers with point thresholds and icons.
     */
    public function index(): JsonResponse
    {
        $tiers = LeagueTier::orderBy('tier_order', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $tiers->map(function ($t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'tier_order' => $t->tier_order,
                    'min_points' => $t->min_points,
                    'max_points' => $t->max_points,
                    'icon_url' => $t->icon_url,
                ];
            }),
        ]);
    }

    /**
     * GET /api/v1/leagues/my-division
     * Headers: Authorization: Bearer <token>
     *
     * Returns the authenticated user's current division standings,
     * live season countdown timer, tier info, and 30-player division pool.
     */
    public function myDivision(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Get or create active season
        $activeSeason = LeagueSeason::getActiveSeason();
        if (!$activeSeason) {
            $activeSeason = $this->seasonService->startNewSeason();
        }

        // 2. If user is below level 4, return graceful preview without active division
        if ((int) $user->level < 4) {
            $tier = LeagueTier::getTierForPoints((int) $user->league_points)
                ?? LeagueTier::orderBy('tier_order', 'asc')->first();

            $nextTier = LeagueTier::getNextTier($tier ? $tier->tier_order : 1);
            $pointsNeededForNextTier = $nextTier ? max(0, $nextTier->min_points - (int) $user->league_points) : 0;
            $secondsRemaining = $activeSeason ? max(0, $activeSeason->ends_at->timestamp - Carbon::now()->timestamp) : 0;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'season' => [
                        'id' => $activeSeason ? $activeSeason->id : 0,
                        'season_number' => $activeSeason ? $activeSeason->season_number : 1,
                        'starts_at' => $activeSeason ? $activeSeason->starts_at->toIso8601String() : now()->toIso8601String(),
                        'ends_at' => $activeSeason ? $activeSeason->ends_at->toIso8601String() : now()->addDays(7)->toIso8601String(),
                        'seconds_remaining' => $secondsRemaining,
                    ],
                    'user_summary' => [
                        'tier_name' => $tier ? $tier->name : 'Bronze',
                        'tier_order' => $tier ? $tier->tier_order : 1,
                        'icon_url' => $tier ? $tier->icon_url : '/images/leagues/bronze.png',
                        'lifetime_points' => (int) $user->league_points,
                        'division_number' => 0,
                        'my_rank_in_division' => 0,
                        'points_needed_for_next_tier' => $pointsNeededForNextTier,
                        'is_locked' => true,
                        'unlock_level' => 4,
                    ],
                    'division_members' => [],
                ],
            ]);
        }

        // 3. Ensure user is in an active division for this season
        $member = $this->seasonService->ensureUserInActiveDivision($user);

        if (!$member) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to determine user league division.',
            ], 500);
        }

        $division = $member->division()->with('tier')->first();
        $tier = $division->tier ?? LeagueTier::getTierForPoints((int) $user->league_points);

        // Calculate next tier & points needed
        $nextTier = LeagueTier::getNextTier($tier ? $tier->tier_order : 1);
        $pointsNeededForNextTier = $nextTier ? max(0, $nextTier->min_points - (int) $user->league_points) : 0;

        // Season seconds remaining
        $secondsRemaining = max(0, $activeSeason->ends_at->timestamp - Carbon::now()->timestamp);

        // Get division members sorted by points_in_division DESC
        $divisionMembers = LeagueDivisionMember::where('league_division_id', $division->id)
            ->with('user')
            ->orderByDesc('points_in_division')
            ->orderBy('id', 'asc')
            ->get();

        $userRankInDivision = 1;
        $formattedMembers = [];

        foreach ($divisionMembers as $index => $m) {
            $rankPos = $index + 1;
            $isMe = ($m->user_id === $user->id);

            if ($isMe) {
                $userRankInDivision = $rankPos;
            }

            $formattedMembers[] = [
                'rank' => $rankPos,
                'user_id' => $m->user_id,
                'username' => $m->user->username ?? 'Player',
                'avatar_url' => $m->user->avatar_url ?? null,
                'country' => $m->user->country ?? 'PK',
                'points_in_division' => (int) $m->points_in_division,
                'is_me' => $isMe,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'season' => [
                    'id' => $activeSeason->id,
                    'season_number' => $activeSeason->season_number,
                    'starts_at' => $activeSeason->starts_at->toIso8601String(),
                    'ends_at' => $activeSeason->ends_at->toIso8601String(),
                    'seconds_remaining' => $secondsRemaining,
                ],
                'user_summary' => [
                    'tier_name' => $tier ? $tier->name : 'Bronze',
                    'tier_order' => $tier ? $tier->tier_order : 1,
                    'icon_url' => $tier ? $tier->icon_url : '/images/leagues/bronze.png',
                    'lifetime_points' => (int) $user->league_points,
                    'division_number' => $division->division_number,
                    'my_rank_in_division' => $userRankInDivision,
                    'points_needed_for_next_tier' => $pointsNeededForNextTier,
                ],
                'division_members' => $formattedMembers,
            ],
        ]);
    }
}
