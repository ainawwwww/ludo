<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GameEngine\RedisGameStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamModePayoutAndForfeitTest extends TestCase
{
    use RefreshDatabase;

    protected User $redUser;
    protected User $greenUser;
    protected User $yellowUser;
    protected User $blueUser;
    protected Room $room;
    protected Game $game;
    protected RedisGameStateStore $stateStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateStore = app(RedisGameStateStore::class);

        $this->redUser = User::factory()->create(['username' => 'RedPlayer']);
        $this->greenUser = User::factory()->create(['username' => 'GreenPlayer']);
        $this->yellowUser = User::factory()->create(['username' => 'YellowPlayer']);
        $this->blueUser = User::factory()->create(['username' => 'BluePlayer']);

        // Set initial wallet balances explicitly
        Wallet::updateOrCreate(['user_id' => $this->redUser->id], ['coins_balance' => 0]);
        Wallet::updateOrCreate(['user_id' => $this->greenUser->id], ['coins_balance' => 0]);
        Wallet::updateOrCreate(['user_id' => $this->yellowUser->id], ['coins_balance' => 0]);
        Wallet::updateOrCreate(['user_id' => $this->blueUser->id], ['coins_balance' => 0]);

        $this->room = Room::create([
            'room_code' => 'TEAM50',
            'title' => '2v2 Team Match',
            'type' => RoomType::TEAM->value,
            'max_players' => 4,
            'entry_fee' => 100,
            'status' => RoomStatus::PLAYING->value,
            'created_by' => $this->redUser->id,
            'member_count' => 4,
        ]);

        RoomPlayer::create(['room_id' => $this->room->id, 'user_id' => $this->redUser->id, 'seat_position' => 1, 'color' => PlayerColor::RED->value]);
        RoomPlayer::create(['room_id' => $this->room->id, 'user_id' => $this->greenUser->id, 'seat_position' => 2, 'color' => PlayerColor::GREEN->value]);
        RoomPlayer::create(['room_id' => $this->room->id, 'user_id' => $this->yellowUser->id, 'seat_position' => 3, 'color' => PlayerColor::YELLOW->value]);
        RoomPlayer::create(['room_id' => $this->room->id, 'user_id' => $this->blueUser->id, 'seat_position' => 4, 'color' => PlayerColor::BLUE->value]);

        $this->game = Game::create([
            'room_id' => $this->room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);
    }

    public function test_team_win_splits_pot_50_50_between_both_teammates(): void
    {
        $players = [
            ['seat_position' => 0, 'user_id' => $this->redUser->id, 'username' => 'RedPlayer', 'color' => 'red'],
            ['seat_position' => 1, 'user_id' => $this->greenUser->id, 'username' => 'GreenPlayer', 'color' => 'green'],
            ['seat_position' => 2, 'user_id' => $this->yellowUser->id, 'username' => 'YellowPlayer', 'color' => 'yellow'],
            ['seat_position' => 3, 'user_id' => $this->blueUser->id, 'username' => 'BluePlayer', 'color' => 'blue'],
        ];

        $state = $this->stateStore->initializeState($this->room->id, $this->game->id, $players, 'team');

        // Setup tokens state: Red has 4 home (56,56,56,56), Yellow has 3 home + 1 step away (56,56,56,55)
        $state['token_positions']['red'] = [56, 56, 56, 56];
        $state['token_positions']['yellow'] = [56, 56, 56, 55];
        $state['current_turn_seat'] = 2; // Yellow turn
        $state['current_turn_user_id'] = $this->yellowUser->id;
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 1;

        $this->stateStore->saveState($this->room->id, $state);

        // Yellow moves token index 3 by 1 step -> reaches 56 -> Team 1 (Red & Yellow) WINS!
        $response = $this->actingAs($this->yellowUser, 'sanctum')
            ->postJson('/api/v1/game/move', [
                'room_id' => $this->room->id,
                'token_index' => 3,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Assert balances: Red & Yellow each get 200 (50% of 400 pot)
        $this->assertEquals(200, $this->redUser->fresh()->wallet->coins_balance);
        $this->assertEquals(200, $this->yellowUser->fresh()->wallet->coins_balance);
        $this->assertEquals(0, $this->greenUser->fresh()->wallet->coins_balance);
        $this->assertEquals(0, $this->blueUser->fresh()->wallet->coins_balance);

        // Assert Transactions created for both Red and Yellow
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->redUser->id,
            'type' => TransactionType::WIN->value,
            'amount' => 200,
            'reference_id' => (string) $this->room->id,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->yellowUser->id,
            'type' => TransactionType::WIN->value,
            'amount' => 200,
            'reference_id' => (string) $this->room->id,
        ]);

        // Assert Room & Game status finished
        $this->assertEquals(RoomStatus::FINISHED->value, $this->room->fresh()->status->value);
        $this->assertEquals(GameStatus::COMPLETED->value, $this->game->fresh()->status->value);
    }

    public function test_full_gameplay_sequence_triggers_team_win_and_payout(): void
    {
        $players = [
            ['seat_position' => 0, 'user_id' => $this->redUser->id, 'username' => 'RedPlayer', 'color' => 'red'],
            ['seat_position' => 1, 'user_id' => $this->greenUser->id, 'username' => 'GreenPlayer', 'color' => 'green'],
            ['seat_position' => 2, 'user_id' => $this->yellowUser->id, 'username' => 'YellowPlayer', 'color' => 'yellow'],
            ['seat_position' => 3, 'user_id' => $this->blueUser->id, 'username' => 'BluePlayer', 'color' => 'blue'],
        ];

        // Initialize state in Redis
        $state = $this->stateStore->initializeState($this->room->id, $this->game->id, $players, 'team');

        // Setup Red with 4 finished tokens (56,56,56,56) and Yellow with 3 finished tokens (56,56,56,50)
        $state['token_positions']['red'] = [56, 56, 56, 56];
        $state['token_positions']['yellow'] = [56, 56, 56, 50];
        $state['current_turn_seat'] = 2; // Yellow's turn
        $state['current_turn_user_id'] = $this->yellowUser->id;
        $state['can_roll'] = true;
        $state['must_move'] = false;

        $this->stateStore->saveState($this->room->id, $state);

        // 1. Roll Dice via roll endpoint
        $rollResponse = $this->actingAs($this->yellowUser, 'sanctum')
            ->postJson('/api/v1/game/roll', [
                'room_id' => $this->room->id,
            ]);

        $rollResponse->assertStatus(200);

        // Override Redis state to simulate exact roll = 6 for deterministic step 50 -> 56
        $freshState = $this->stateStore->getState($this->room->id);
        $freshState['dice_value'] = 6;
        $freshState['can_roll'] = false;
        $freshState['must_move'] = true;
        $this->stateStore->saveState($this->room->id, $freshState);

        // 2. Move Token via move endpoint (Yellow token 3 moves 6 steps from 50 -> 56)
        $moveResponse = $this->actingAs($this->yellowUser, 'sanctum')
            ->postJson('/api/v1/game/move', [
                'room_id' => $this->room->id,
                'token_index' => 3,
            ]);

        $moveResponse->assertStatus(200);
        $moveResponse->assertJsonPath('status', 'success');
        $moveResponse->assertJsonPath('data.status', 'completed');

        // Assert balances updated: Red & Yellow each get 200 (50% of 400 pot)
        $this->assertEquals(200, $this->redUser->fresh()->wallet->coins_balance);
        $this->assertEquals(200, $this->yellowUser->fresh()->wallet->coins_balance);
        $this->assertEquals(0, $this->greenUser->fresh()->wallet->coins_balance);
        $this->assertEquals(0, $this->blueUser->fresh()->wallet->coins_balance);

        // Assert Room & Game marked finished
        $this->assertEquals(RoomStatus::FINISHED->value, $this->room->fresh()->status->value);
        $this->assertEquals(GameStatus::COMPLETED->value, $this->game->fresh()->status->value);
    }

    public function test_team_forfeit_splits_pot_50_50_between_opposing_team(): void
    {
        $players = [
            ['seat_position' => 0, 'user_id' => $this->redUser->id, 'username' => 'RedPlayer', 'color' => 'red'],
            ['seat_position' => 1, 'user_id' => $this->greenUser->id, 'username' => 'GreenPlayer', 'color' => 'green'],
            ['seat_position' => 2, 'user_id' => $this->yellowUser->id, 'username' => 'YellowPlayer', 'color' => 'yellow'],
            ['seat_position' => 3, 'user_id' => $this->blueUser->id, 'username' => 'BluePlayer', 'color' => 'blue'],
        ];

        $this->stateStore->initializeState($this->room->id, $this->game->id, $players, 'team');

        // Red (Team 1) forfeits match
        $response = $this->actingAs($this->redUser, 'sanctum')
            ->postJson('/api/v1/game/forfeit', [
                'room_id' => $this->room->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_game_over', true);

        // Assert balances: Opposing Team 2 (Green & Blue) each get 200 coins
        $this->assertEquals(0, $this->redUser->fresh()->wallet->coins_balance);
        $this->assertEquals(0, $this->yellowUser->fresh()->wallet->coins_balance);
        $this->assertEquals(200, $this->greenUser->fresh()->wallet->coins_balance);
        $this->assertEquals(200, $this->blueUser->fresh()->wallet->coins_balance);

        // Assert Transactions created for both Green and Blue
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->greenUser->id,
            'type' => TransactionType::WIN->value,
            'amount' => 200,
            'reference_id' => (string) $this->room->id,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->blueUser->id,
            'type' => TransactionType::WIN->value,
            'amount' => 200,
            'reference_id' => (string) $this->room->id,
        ]);

        // Assert Room & Game status finished
        $this->assertEquals(RoomStatus::FINISHED->value, $this->room->fresh()->status->value);
        $this->assertEquals(GameStatus::COMPLETED->value, $this->game->fresh()->status->value);
    }
}
