<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\GameEnded;
use App\Events\TurnChanged;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\PrivateRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\ProcessTurnTimeout;
use Database\Seeders\LeagueSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GamePayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected PrivateRoomService $roomService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);
        $this->roomService = app(PrivateRoomService::class);
        Event::fake([TurnChanged::class, GameEnded::class]);
    }

    /**
     * Quick match win does NOT credit coins or write WIN transaction (byte-for-byte legacy behavior).
     */
    public function test_quick_match_win_does_not_credit_coins_or_write_transaction(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user1->wallet->update(['coins_balance' => 1000]);
        $user2->wallet->update(['coins_balance' => 1000]);

        $room = Room::create([
            'room_code' => 'QM1234',
            'created_by' => $user1->id,
            'name' => 'Quick Match',
            'type' => RoomType::PUBLIC->value,
            'entry_fee' => 200,
            'status' => RoomStatus::PLAYING->value,
            'max_players' => 2,
        ]);

        $game = Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $stateStore = app(RedisGameStateStore::class);
        $state = [
            'game_id' => $game->id,
            'room_id' => $room->id,
            'status' => 'in_progress',
            'current_turn_seat' => 0,
            'current_turn_user_id' => $user1->id,
            'active_seats' => [0, 1],
            'can_roll' => false,
            'must_move' => true,
            'dice_value' => 1,
            'consecutive_sixes' => 0,
            'game_mode' => 'quick_match',
            'players' => [
                0 => ['user_id' => $user1->id, 'username' => $user1->username, 'color' => 'red', 'is_connected' => true],
                1 => ['user_id' => $user2->id, 'username' => $user2->username, 'color' => 'green', 'is_connected' => true],
            ],
            'token_positions' => [
                'red' => [55, 56, 56, 56],
                'green' => [-1, -1, -1, -1],
            ],
            'last_action_at' => now()->toIso8601String(),
        ];
        $stateStore->saveState($room->id, $state);

        $res = $this->actingAs($user1)->postJson('/api/v1/quick-match/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ]);

        $res->assertStatus(200);
        $this->assertEquals('completed', $res->json('data.status'));

        // Legacy behavior: coins balance unchanged (1000)
        $this->assertEquals(1000, $user1->wallet->fresh()->coins_balance);

        // No WIN transaction written
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user1->id,
            'type' => TransactionType::WIN->value,
        ]);

        // Room marked FINISHED
        $this->assertEquals(RoomStatus::FINISHED, $room->fresh()->status);
    }

    /**
     * Quick match forfeit awards max(400, entry_fee * max_players) with TransactionType::REWARD.
     */
    public function test_quick_match_forfeit_awards_reward_transaction(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user1->wallet->update(['coins_balance' => 1000]);
        $user2->wallet->update(['coins_balance' => 1000]);

        $room = Room::create([
            'room_code' => 'QM5678',
            'created_by' => $user1->id,
            'name' => 'Quick Match',
            'type' => RoomType::PUBLIC->value,
            'entry_fee' => 500,
            'status' => RoomStatus::PLAYING->value,
            'max_players' => 2,
        ]);

        $game = Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $stateStore = app(RedisGameStateStore::class);
        $state = [
            'game_id' => $game->id,
            'room_id' => $room->id,
            'status' => 'in_progress',
            'current_turn_seat' => 0,
            'current_turn_user_id' => $user1->id,
            'active_seats' => [0, 1],
            'players' => [
                0 => ['user_id' => $user1->id, 'username' => $user1->username, 'color' => 'red', 'is_connected' => true],
                1 => ['user_id' => $user2->id, 'username' => $user2->username, 'color' => 'green', 'is_connected' => true],
            ],
            'token_positions' => [
                'red' => [0, -1, -1, -1],
                'green' => [0, -1, -1, -1],
            ],
            'last_action_at' => now()->toIso8601String(),
        ];
        $stateStore->saveState($room->id, $state);

        // User 2 forfeits -> User 1 wins
        $res = $this->actingAs($user2)->postJson('/api/v1/quick-match/forfeit', [
            'room_id' => $room->id,
        ]);

        $res->assertStatus(200);
        $this->assertTrue($res->json('data.is_game_over'));
        $this->assertEquals($user1->id, $res->json('data.winner_id'));

        // 500 * 2 = 1000 coins awarded to User 1
        $this->assertEquals(2000, $user1->wallet->fresh()->coins_balance);

        // Transaction recorded as REWARD (legacy behavior)
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user1->id,
            'type' => TransactionType::REWARD->value,
            'amount' => 1000,
            'reference_id' => (string) $room->id,
        ]);
    }

    /**
     * Zero-fee quick match forfeit awards default 400 coins with REWARD.
     */
    public function test_zero_fee_quick_match_forfeit_awards_400_coins_reward(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user1->wallet->update(['coins_balance' => 500]);
        $user2->wallet->update(['coins_balance' => 500]);

        $room = Room::create([
            'room_code' => 'QM0000',
            'created_by' => $user1->id,
            'name' => 'Zero Fee Match',
            'type' => RoomType::PUBLIC->value,
            'entry_fee' => 0,
            'status' => RoomStatus::PLAYING->value,
            'max_players' => 2,
        ]);

        $game = Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $stateStore = app(RedisGameStateStore::class);
        $state = [
            'game_id' => $game->id,
            'room_id' => $room->id,
            'status' => 'in_progress',
            'current_turn_seat' => 0,
            'current_turn_user_id' => $user1->id,
            'active_seats' => [0, 1],
            'players' => [
                0 => ['user_id' => $user1->id, 'username' => $user1->username, 'color' => 'red', 'is_connected' => true],
                1 => ['user_id' => $user2->id, 'username' => $user2->username, 'color' => 'green', 'is_connected' => true],
            ],
            'token_positions' => [
                'red' => [0, -1, -1, -1],
                'green' => [0, -1, -1, -1],
            ],
            'last_action_at' => now()->toIso8601String(),
        ];
        $stateStore->saveState($room->id, $state);

        $res = $this->actingAs($user2)->postJson('/api/v1/quick-match/forfeit', [
            'room_id' => $room->id,
        ]);

        $res->assertStatus(200);

        // max(400, 0 * 2) = 400
        $this->assertEquals(900, $user1->wallet->fresh()->coins_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user1->id,
            'type' => TransactionType::REWARD->value,
            'amount' => 400,
        ]);
    }

    /**
     * Private room win pays exactly entry_fee * max_players with TransactionType::WIN.
     */
    public function test_private_room_win_pays_exact_pot_with_win_transaction(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 1000]);
        $guest->wallet->update(['coins_balance' => 1000]);

        $room = $this->roomService->create($host, 2, 500);
        $this->roomService->join($guest, $room->room_code);
        $this->roomService->toggleReady($guest, $room, true);
        $this->roomService->start($host, $room);

        // Host and guest were debited 500 each; host balance is now 500
        $this->assertEquals(500, $host->wallet->fresh()->coins_balance);

        $stateStore = app(RedisGameStateStore::class);
        $state = $stateStore->getState($room->id);
        $state['current_turn_seat'] = 0;
        $state['current_turn_user_id'] = $host->id;
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 1;
        $state['token_positions']['red'] = [55, 56, 56, 56];
        $stateStore->saveState($room->id, $state);

        $res = $this->actingAs($host)->postJson('/api/v1/game/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ]);

        $res->assertStatus(200);

        // Pot = 500 * 2 = 1000 coins awarded to host. 500 + 1000 = 1500
        $this->assertEquals(1500, $host->wallet->fresh()->coins_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'type' => TransactionType::WIN->value,
            'amount' => 1000,
            'reference_id' => (string) $room->id,
        ]);

        // State version incremented
        $this->assertGreaterThan(2, $room->fresh()->state_version);
    }

    /**
     * Replaying moveToken or forfeit after match completion is idempotent (never credits twice).
     */
    public function test_replaying_move_or_forfeit_after_match_ended_never_credits_twice(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 1000]);
        $guest->wallet->update(['coins_balance' => 1000]);

        $room = $this->roomService->create($host, 2, 500);
        $this->roomService->join($guest, $room->room_code);
        $this->roomService->toggleReady($guest, $room, true);
        $this->roomService->start($host, $room);

        $stateStore = app(RedisGameStateStore::class);
        $state = $stateStore->getState($room->id);
        $state['current_turn_seat'] = 0;
        $state['current_turn_user_id'] = $host->id;
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 1;
        $state['token_positions']['red'] = [55, 56, 56, 56];
        $stateStore->saveState($room->id, $state);

        // First win
        $res1 = $this->actingAs($host)->postJson('/api/v1/game/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ]);
        $res1->assertStatus(200);
        $this->assertEquals(1500, $host->wallet->fresh()->coins_balance);

        // Replay moveToken
        $res2 = $this->actingAs($host)->postJson('/api/v1/game/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ]);
        $res2->assertStatus(400);
        $this->assertEquals(1500, $host->wallet->fresh()->coins_balance);

        // Attempt forfeit on ended game
        $res3 = $this->actingAs($guest)->postJson('/api/v1/game/forfeit', [
            'room_id' => $room->id,
        ]);
        $res3->assertStatus(400);
        $this->assertEquals(1500, $host->wallet->fresh()->coins_balance);

        // Ensure exactly ONE WIN transaction exists
        $winCount = Transaction::where('user_id', $host->id)
            ->where('type', TransactionType::WIN->value)
            ->count();
        $this->assertEquals(1, $winCount);
    }

    /**
     * Public room win and forfeit match legacy behavior (win credits 0, forfeit awards REWARD).
     */
    public function test_public_room_game_payout_behavior_is_unaffected(): void
    {
        $creator = User::factory()->create();
        $player2 = User::factory()->create();
        $creator->wallet->update(['coins_balance' => 1000]);
        $player2->wallet->update(['coins_balance' => 1000]);

        $room = Room::create([
            'room_code' => 'PUB100',
            'created_by' => $creator->id,
            'name' => 'Public Lounge',
            'type' => RoomType::PUBLIC->value,
            'entry_fee' => 300,
            'status' => RoomStatus::PLAYING->value,
            'max_players' => 2,
        ]);

        $game = Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $stateStore = app(RedisGameStateStore::class);
        $state = [
            'game_id' => $game->id,
            'room_id' => $room->id,
            'status' => 'in_progress',
            'current_turn_seat' => 0,
            'current_turn_user_id' => $creator->id,
            'active_seats' => [0, 1],
            'players' => [
                0 => ['user_id' => $creator->id, 'username' => $creator->username, 'color' => 'red', 'is_connected' => true],
                1 => ['user_id' => $player2->id, 'username' => $player2->username, 'color' => 'yellow', 'is_connected' => true],
            ],
            'token_positions' => [
                'red' => [0, -1, -1, -1],
                'yellow' => [0, -1, -1, -1],
            ],
            'last_action_at' => now()->toIso8601String(),
        ];
        $stateStore->saveState($room->id, $state);

        // Forfeit in public room awards 300 * 2 = 600 with REWARD
        $res = $this->actingAs($player2)->postJson('/api/v1/game/forfeit', [
            'room_id' => $room->id,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(1600, $creator->wallet->fresh()->coins_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $creator->id,
            'type' => TransactionType::REWARD->value,
            'amount' => 600,
        ]);
    }
}
