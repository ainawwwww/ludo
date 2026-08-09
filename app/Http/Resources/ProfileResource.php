<?php

namespace App\Http\Resources;

use App\Models\League;
use App\Services\BadgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ProfileResource
 *
 * Formats the full profile view for the authenticated user.
 *
 * @mixin \App\Models\User
 *
 * Response Shape (Postman-style):
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
 *       "favorite_dice": "Fire Dice"
 *     }
 *   }
 * }
 */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $badgeService = app(BadgeService::class);
        $leaguePoints = $this->league_points ?? 0;
        $currentLeague = League::getLeagueForPoints($leaguePoints);

        // Compute league progress info
        $leagueInfo = null;
        if ($currentLeague) {
            $nextLeague = League::getNextLeague($currentLeague->tier_order);
            $pointsNeeded = $nextLeague ? ($nextLeague->min_points - $leaguePoints) : 0;

            // Compute progress_status (low / mid / high) within current tier
            $range = $currentLeague->max_points - $currentLeague->min_points;
            $progressInTier = $leaguePoints - $currentLeague->min_points;
            $percentage = $range > 0 ? ($progressInTier / $range) * 100 : 100;

            if ($percentage < 33.33) {
                $progressStatus = 'low';
            } elseif ($percentage < 66.67) {
                $progressStatus = 'mid';
            } else {
                $progressStatus = 'high';
            }

            $leagueInfo = [
                'current_league' => [
                    'name' => $currentLeague->name,
                    'icon_url' => $currentLeague->icon_url,
                ],
                'league_points' => $leaguePoints,
                'points_needed_for_next_tier' => max(0, $pointsNeeded),
                'progress_status' => $progressStatus,
            ];
        }

        return [
            'id' => $this->id,
            'name' => $this->username,
            'level' => $this->level ?? 1,
            'avatar_url' => $this->avatar_url,
            'gender' => $this->gender ?? 'unspecified',
            'dob' => $this->dob?->toDateString(),
            'country' => $this->country,
            'bio' => $this->bio,
            'total_games_played' => $this->total_games_played,
            'total_wins' => $this->total_wins,
            'total_losses' => $this->total_losses,
            'win_rate' => $this->win_rate,
            'league_info' => $leagueInfo,
            'achievements' => [
                'level_badge' => $badgeService->getLevelBadge($this->level ?? 1),
                'favorite_dice' => $badgeService->getFavoriteDice($this->resource),
            ],
        ];
    }
}
