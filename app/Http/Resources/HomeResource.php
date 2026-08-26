<?php

namespace App\Http\Resources;

use App\Models\League;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * HomeResource
 *
 * Formats the home screen data for the authenticated user.
 *
 * @mixin \App\Models\User
 *
 * Response Shape (Postman-style):
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
class HomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isUnlocked = ((int) ($this->level ?? 1)) >= 4;
        $league = $isUnlocked ? League::getLeagueForPoints($this->league_points ?? 0) : null;

        return [
            'username' => $this->username,
            'level' => $this->level ?? 1,
            'coins' => $this->coins,
            'diamonds' => $this->diamonds,
            'current_league' => $league ? [
                'name' => $league->name,
                'icon_url' => $league->icon_url,
            ] : null,
            'is_league_locked' => !$isUnlocked,
            'league_unlock_level' => 4,
            'global_rank' => $this->rank,
            'avatar_url' => $this->avatar_url,
        ];
    }
}
