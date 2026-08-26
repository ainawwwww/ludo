<?php

namespace App\Services\League;

use App\Models\LeagueDivision;
use App\Models\LeagueDivisionMember;
use App\Models\LeagueSeason;
use App\Models\LeagueTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeagueSeasonService
{
    /**
     * Start a new weekly league season.
     *
     * 1. Creates a new active league_seasons row (+7 days).
     * 2. Fetches all active users and determines their tier via users.league_points.
     * 3. Groups users into 30-player divisions per tier.
     * 4. Populates league_divisions and league_division_members with points_in_division = 0.
     */
    public function startNewSeason(): LeagueSeason
    {
        return DB::transaction(function () {
            // Get current max season number
            $lastSeason = LeagueSeason::orderByDesc('season_number')->first();
            $nextSeasonNumber = $lastSeason ? ($lastSeason->season_number + 1) : 1;

            $now = Carbon::now();
            $endsAt = $now->copy()->addDays(7);

            // Create new active season
            $season = LeagueSeason::create([
                'season_number' => $nextSeasonNumber,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => 'active',
            ]);

            $tiers = LeagueTier::orderBy('tier_order', 'asc')->get();
            $allUsers = User::where('is_active', true)
                ->where('level', '>=', 4)
                ->get();

            // Bucket users by tier
            $usersByTier = [];
            foreach ($tiers as $tier) {
                $usersByTier[$tier->id] = [];
            }

            $bronzeTier = $tiers->firstWhere('tier_order', 1) ?? $tiers->first();

            foreach ($allUsers as $user) {
                $points = (int) ($user->league_points ?? 0);
                $matchedTier = LeagueTier::getTierForPoints($points) ?? $bronzeTier;
                $usersByTier[$matchedTier->id][] = $user;
            }

            // Create divisions of ~30 players for each tier
            foreach ($tiers as $tier) {
                $tierUsers = $usersByTier[$tier->id] ?? [];
                if (empty($tierUsers) && $tier->id === $bronzeTier->id) {
                    // Ensure at least 1 empty division exists for Bronze tier
                    LeagueDivision::create([
                        'league_season_id' => $season->id,
                        'league_tier_id' => $tier->id,
                        'division_number' => 1,
                        'max_players' => 30,
                    ]);
                    continue;
                }

                $chunks = array_chunk($tierUsers, 30);
                $divisionNumber = 1;

                foreach ($chunks as $chunk) {
                    $division = LeagueDivision::create([
                        'league_season_id' => $season->id,
                        'league_tier_id' => $tier->id,
                        'division_number' => $divisionNumber++,
                        'max_players' => 30,
                    ]);

                    foreach ($chunk as $user) {
                        LeagueDivisionMember::create([
                            'league_division_id' => $division->id,
                            'user_id' => $user->id,
                            'points_in_division' => 0,
                            'joined_at' => $now,
                        ]);
                    }
                }
            }

            Log::info("🏆 [LEAGUE] Started Season #{$season->season_number} (ID: {$season->id}) ending at {$endsAt}");

            return $season;
        });
    }

    /**
     * End an active season, finalize division ranks, and start the next season.
     * Tier assignment is purely points-based (derived from users.league_points).
     */
    public function endSeason(int $seasonId): LeagueSeason
    {
        return DB::transaction(function () use ($seasonId) {
            $season = LeagueSeason::where('id', $seasonId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($season->status === 'completed') {
                return $season;
            }

            $divisions = LeagueDivision::where('league_season_id', $season->id)
                ->with('tier')
                ->get();

            foreach ($divisions as $division) {
                $members = LeagueDivisionMember::where('league_division_id', $division->id)
                    ->orderByDesc('points_in_division')
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($members as $index => $member) {
                    $rank = $index + 1;

                    $member->update([
                        'final_rank' => $rank,
                        'result' => 'completed',
                    ]);
                }
            }

            $season->update(['status' => 'completed']);
            Log::info("🏆 [LEAGUE] Completed Season #{$season->season_number} with pure points-based tier assignment.");

            // Immediately start the next season with fresh divisions and 0 division points
            $this->startNewSeason();

            return $season->fresh();
        });
    }

    /**
     * Enroll a user in an active season division if eligible (level >= 4).
     * Used for mid-season joins when a user levels up to level 4 or higher.
     *
     * @param User $user
     * @return LeagueDivisionMember|null
     */
    public function enrollUserIfEligible(User $user): ?LeagueDivisionMember
    {
        // 1. Check eligibility: must be level >= 4
        if ((int) $user->level < 4) {
            return null;
        }

        // 2. Check for an active season
        $activeSeason = LeagueSeason::where('status', 'active')
            ->where('starts_at', '<=', Carbon::now())
            ->where('ends_at', '>', Carbon::now())
            ->first();

        if (!$activeSeason) {
            // Off-season: user will be enrolled on the next startNewSeason()
            return null;
        }

        // 3. Check if user is already enrolled in this active season
        $existing = LeagueDivisionMember::whereHas('division', function ($q) use ($activeSeason) {
            $q->where('league_season_id', $activeSeason->id);
        })->where('user_id', $user->id)->first();

        if ($existing) {
            return $existing;
        }

        // 4. Determine user tier from lifetime points (defaults to Bronze if 0)
        $userTier = LeagueTier::getTierForPoints((int) ($user->league_points ?? 0))
            ?? LeagueTier::orderBy('tier_order', 'asc')->first();

        if (!$userTier) {
            return null;
        }

        // 5. Add user to an existing division with < 30 members or create a new one
        return DB::transaction(function () use ($activeSeason, $userTier, $user) {
            $division = LeagueDivision::where('league_season_id', $activeSeason->id)
                ->where('league_tier_id', $userTier->id)
                ->whereHas('members', function () {}, '<', 30)
                ->first();

            if (!$division) {
                $maxDivNum = LeagueDivision::where('league_season_id', $activeSeason->id)
                    ->where('league_tier_id', $userTier->id)
                    ->max('division_number') ?? 0;

                $division = LeagueDivision::create([
                    'league_season_id' => $activeSeason->id,
                    'league_tier_id' => $userTier->id,
                    'division_number' => $maxDivNum + 1,
                    'max_players' => 30,
                ]);
            }

            return LeagueDivisionMember::firstOrCreate([
                'league_division_id' => $division->id,
                'user_id' => $user->id,
            ], [
                'points_in_division' => 0,
                'joined_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Gracefully ensure an eligible user is placed in an active division for the current season.
     */
    public function ensureUserInActiveDivision(User $user): ?LeagueDivisionMember
    {
        return $this->enrollUserIfEligible($user);
    }
}
