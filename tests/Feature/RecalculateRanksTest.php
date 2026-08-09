<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateRanksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_recalculate_ranks_command_orders_users_by_points_and_wins(): void
    {
        // Create 3 users with different league points
        $userLow = User::factory()->create(['username' => 'low_user', 'league_points' => 100]);
        $userMid = User::factory()->create(['username' => 'mid_user', 'league_points' => 500]);
        $userTop = User::factory()->create(['username' => 'top_user', 'league_points' => 1500]);

        // Run Artisan command
        $this->artisan('leaderboard:recalculate-ranks')
            ->assertExitCode(0);

        // Verify ranks: top=1, mid=2, low=3
        $this->assertEquals(1, $userTop->fresh()->rank);
        $this->assertEquals(2, $userMid->fresh()->rank);
        $this->assertEquals(3, $userLow->fresh()->rank);
    }

    public function test_recalculate_ranks_uses_wins_as_tie_breaker(): void
    {
        // Create 2 users with equal league points
        $user1 = User::factory()->create(['username' => 'user1', 'league_points' => 500]);
        $user2 = User::factory()->create(['username' => 'user2', 'league_points' => 500]);

        // Give user2 a completed win
        $room = Room::create(['room_code' => 'WINNER', 'type' => 'public', 'max_players' => 2, 'entry_fee' => 0, 'status' => 'finished', 'created_by' => $user2->id]);
        Game::create(['room_id' => $room->id, 'winner_id' => $user2->id, 'status' => GameStatus::COMPLETED->value]);

        $this->artisan('leaderboard:recalculate-ranks')
            ->assertExitCode(0);

        // User2 should be rank 1 due to tie-breaker (1 win vs 0 wins)
        $this->assertEquals(1, $user2->fresh()->rank);
        $this->assertEquals(2, $user1->fresh()->rank);
    }
}
