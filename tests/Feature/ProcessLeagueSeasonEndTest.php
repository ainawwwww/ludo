<?php

namespace Tests\Feature;

use App\Models\LeagueSeason;
use App\Models\User;
use App\Services\League\LeagueSeasonService;
use Carbon\Carbon;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessLeagueSeasonEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_artisan_command_processes_expired_active_seasons(): void
    {
        User::factory()->count(5)->create(['league_points' => 100]);

        $service = app(LeagueSeasonService::class);
        $season = $service->startNewSeason();

        // Artificially expire the season
        $season->update([
            'ends_at' => Carbon::now()->subMinutes(10),
        ]);

        $this->artisan('league:process-season-end')
            ->assertExitCode(0);

        $this->assertEquals('completed', $season->fresh()->status);
    }
}
