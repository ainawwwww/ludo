<?php

namespace Tests\Feature;

use App\Models\LeagueDivision;
use App\Models\LeagueDivisionMember;
use App\Models\LeagueSeason;
use App\Models\LeagueTier;
use App\Models\User;
use App\Services\League\LeagueSeasonService;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueSeasonServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_start_new_season_creates_season_and_places_eligible_users_in_divisions(): void
    {
        // Create 35 test users at level 4 in Bronze tier, and 5 users at level 2
        User::factory()->count(35)->create(['league_points' => 100, 'level' => 4]);
        User::factory()->count(5)->create(['league_points' => 100, 'level' => 2]);

        $service = app(LeagueSeasonService::class);
        $season = $service->startNewSeason();

        $this->assertNotNull($season);
        $this->assertEquals(1, $season->season_number);
        $this->assertEquals('active', $season->status);

        // 35 eligible level 4 users in Bronze should be split into 2 divisions (30 + 5)
        $bronzeTier = LeagueTier::where('name', 'Bronze')->first();
        $divisions = LeagueDivision::where('league_season_id', $season->id)
            ->where('league_tier_id', $bronzeTier->id)
            ->get();

        $this->assertGreaterThanOrEqual(2, $divisions->count());

        $firstDivMembers = LeagueDivisionMember::where('league_division_id', $divisions[0]->id)->count();
        $this->assertEquals(30, $firstDivMembers);

        $secondDivMembers = LeagueDivisionMember::where('league_division_id', $divisions[1]->id)->count();
        $this->assertEquals(5, $secondDivMembers);
    }

    public function test_mid_season_level_up_to_4_automatically_enrolls_user_in_division_with_room(): void
    {
        $service = app(LeagueSeasonService::class);
        $season = $service->startNewSeason();

        $bronzeTier = LeagueTier::where('name', 'Bronze')->first();
        $division = LeagueDivision::where('league_season_id', $season->id)
            ->where('league_tier_id', $bronzeTier->id)
            ->first();

        $initialCount = LeagueDivisionMember::where('league_division_id', $division->id)->count();

        // Create a user at level 3
        $user = User::factory()->create([
            'level' => 3,
            'league_points' => 0,
        ]);

        // Level up user to 4 (triggers model event)
        $user->update(['level' => 4]);

        // Verify user was automatically enrolled
        $member = LeagueDivisionMember::where('user_id', $user->id)
            ->where('league_division_id', $division->id)
            ->first();

        $this->assertNotNull($member);
        $this->assertEquals(0, $member->points_in_division);
        $this->assertEquals($initialCount + 1, LeagueDivisionMember::where('league_division_id', $division->id)->count());
    }

    public function test_mid_season_enrollment_creates_new_division_when_existing_is_full(): void
    {
        // Create 30 users at level 4 in Bronze tier
        User::factory()->count(30)->create(['league_points' => 100, 'level' => 4]);

        $service = app(LeagueSeasonService::class);
        $season = $service->startNewSeason();

        $bronzeTier = LeagueTier::where('name', 'Bronze')->first();
        $this->assertEquals(1, LeagueDivision::where('league_season_id', $season->id)->where('league_tier_id', $bronzeTier->id)->count());

        // Create a new user at level 3, then level up to 4
        $newUser = User::factory()->create(['level' => 3, 'league_points' => 100]);
        $newUser->update(['level' => 4]);

        // Since division 1 had 30 members, division 2 should be created
        $divisions = LeagueDivision::where('league_season_id', $season->id)
            ->where('league_tier_id', $bronzeTier->id)
            ->orderBy('division_number')
            ->get();

        $this->assertEquals(2, $divisions->count());
        $this->assertEquals(2, $divisions[1]->division_number);

        $member = LeagueDivisionMember::where('user_id', $newUser->id)->first();
        $this->assertNotNull($member);
        $this->assertEquals($divisions[1]->id, $member->league_division_id);
    }

    public function test_off_season_level_up_does_not_enroll_user_until_season_starts(): void
    {
        // No active season
        $user = User::factory()->create(['level' => 3, 'league_points' => 100]);
        $user->update(['level' => 4]);

        $this->assertNull(LeagueDivisionMember::where('user_id', $user->id)->first());
    }

    public function test_end_season_completes_ranks_preserves_lifetime_points_and_starts_next_season(): void
    {
        // Create Silver tier users with 1500 lifetime points
        $users = User::factory()->count(10)->create(['league_points' => 1500, 'level' => 4]);

        $service = app(LeagueSeasonService::class);
        $season = $service->startNewSeason();

        // Assign points in division
        $members = LeagueDivisionMember::whereHas('division', function ($q) use ($season) {
            $q->where('league_season_id', $season->id);
        })->get();

        foreach ($members as $idx => $m) {
            $m->update(['points_in_division' => ($idx + 1) * 50]);
        }

        // End season
        $completedSeason = $service->endSeason($season->id);

        $this->assertNotNull($completedSeason);
        $this->assertEquals('completed', $completedSeason->status);

        // Check that old season members are marked 'completed' with accurate final_rank
        $completedMembers = LeagueDivisionMember::whereHas('division', function ($q) use ($season) {
            $q->where('league_season_id', $season->id);
        })->orderBy('final_rank')->get();

        $this->assertEquals(10, $completedMembers->count());
        $this->assertEquals('completed', $completedMembers[0]->result);
        $this->assertEquals(1, $completedMembers[0]->final_rank);
        $this->assertEquals(500, $completedMembers[0]->points_in_division);

        // Lifetime points should NOT be artificially modified
        $this->assertEquals(1500, User::find($completedMembers[0]->user_id)->league_points);

        // Active season #2 should be started with fresh divisions where points_in_division = 0
        $activeSeason = LeagueSeason::getActiveSeason();
        $this->assertNotNull($activeSeason);
        $this->assertEquals(2, $activeSeason->season_number);

        $newSeasonMembers = LeagueDivisionMember::whereHas('division', function ($q) use ($activeSeason) {
            $q->where('league_season_id', $activeSeason->id);
        })->get();

        $this->assertEquals(10, $newSeasonMembers->count());
        foreach ($newSeasonMembers as $nsm) {
            $this->assertEquals(0, $nsm->points_in_division);
        }
    }
}
