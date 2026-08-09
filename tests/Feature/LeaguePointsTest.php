<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\LeagueService;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaguePointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_game_completion_updates_winner_and_loser_league_points(): void
    {
        config([
            'ludo.league_points_win' => 25,
            'ludo.league_points_loss' => 10,
        ]);

        $winner = User::factory()->create(['league_points' => 100]);
        $loser1 = User::factory()->create(['league_points' => 50]);
        $loser2 = User::factory()->create(['league_points' => 5]); // Points drop to 0 max

        $room = Room::create([
            'room_code' => 'GAME01',
            'type' => RoomType::PUBLIC->value,
            'max_players' => 3,
            'entry_fee' => 0,
            'status' => RoomStatus::FINISHED->value,
            'created_by' => $winner->id,
            'created_at' => now(),
        ]);

        RoomPlayer::create(['room_id' => $room->id, 'user_id' => $winner->id, 'seat_position' => 1, 'color' => 'red', 'is_ready' => true, 'joined_at' => now()]);
        RoomPlayer::create(['room_id' => $room->id, 'user_id' => $loser1->id, 'seat_position' => 2, 'color' => 'green', 'is_ready' => true, 'joined_at' => now()]);
        RoomPlayer::create(['room_id' => $room->id, 'user_id' => $loser2->id, 'seat_position' => 3, 'color' => 'yellow', 'is_ready' => true, 'joined_at' => now()]);

        $game = Game::create([
            'room_id' => $room->id,
            'winner_id' => $winner->id,
            'status' => GameStatus::COMPLETED->value,
            'started_at' => now(),
            'ended_at' => now(),
            'created_at' => now(),
        ]);

        app(LeagueService::class)->awardLeaguePoints($game);

        // Winner gets +25 (100 -> 125)
        $this->assertEquals(125, $winner->fresh()->league_points);

        // Loser1 gets -10 (50 -> 40)
        $this->assertEquals(40, $loser1->fresh()->league_points);

        // Loser2 gets -10, clamped at 0 (5 -> 0)
        $this->assertEquals(0, $loser2->fresh()->league_points);
    }
}
