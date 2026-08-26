<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_home_returns_correct_user_data(): void
    {
        $user = User::factory()->create([
            'username' => 'home_test_user',
            'level' => 5,
            'league_points' => 500,
            'rank' => 10,
            'avatar_url' => '/avatars/test.png',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/home');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'username',
                    'level',
                    'coins',
                    'diamonds',
                    'current_league',
                    'global_rank',
                    'avatar_url',
                ],
            ])
            ->assertJsonPath('data.username', 'home_test_user')
            ->assertJsonPath('data.level', 5)
            ->assertJsonPath('data.global_rank', 10)
            ->assertJsonPath('data.avatar_url', '/avatars/test.png');
    }

    public function test_home_returns_correct_league_for_points(): void
    {
        $user = User::factory()->create(['league_points' => 1500, 'level' => 4]);

        $response = $this->actingAs($user)->getJson('/api/v1/home');

        $response->assertStatus(200)
            ->assertJsonPath('data.current_league.name', 'Silver')
            ->assertJsonPath('data.is_league_locked', false);
    }

    public function test_home_returns_bronze_league_for_zero_points_when_level_4(): void
    {
        $user = User::factory()->create(['league_points' => 0, 'level' => 4]);

        $response = $this->actingAs($user)->getJson('/api/v1/home');

        $response->assertStatus(200)
            ->assertJsonPath('data.current_league.name', 'Bronze')
            ->assertJsonPath('data.is_league_locked', false);
    }

    public function test_home_returns_null_league_and_locked_when_below_level_4(): void
    {
        $user = User::factory()->create(['league_points' => 0, 'level' => 1]);

        $response = $this->actingAs($user)->getJson('/api/v1/home');

        $response->assertStatus(200)
            ->assertJsonPath('data.current_league', null)
            ->assertJsonPath('data.is_league_locked', true);
    }

    public function test_home_returns_coins_and_diamonds_from_wallet(): void
    {
        $user = User::factory()->create();

        // Factory auto-creates wallet with 10000 coins & 100 diamonds
        $response = $this->actingAs($user)->getJson('/api/v1/home');

        $response->assertStatus(200)
            ->assertJsonPath('data.coins', 10000)
            ->assertJsonPath('data.diamonds', 100);
    }

    public function test_home_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/home');
        $response->assertStatus(401);
    }
}
