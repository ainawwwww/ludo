<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_get_leagues_returns_all_5_tiers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/leagues');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonCount(5, 'data');
    }

    public function test_get_my_division_returns_standings_and_season_info(): void
    {
        $user = User::factory()->create(['league_points' => 1200, 'level' => 4]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/leagues/my-division');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'season' => ['id', 'season_number', 'starts_at', 'ends_at', 'seconds_remaining'],
                    'user_summary' => ['tier_name', 'tier_order', 'lifetime_points', 'division_number', 'my_rank_in_division'],
                    'division_members',
                ]
            ]);
    }

    public function test_get_my_division_locked_when_below_level_4(): void
    {
        $user = User::factory()->create(['league_points' => 0, 'level' => 2]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/leagues/my-division');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'user_summary' => [
                        'is_locked' => true,
                        'unlock_level' => 4,
                    ]
                ]
            ]);
    }
}
