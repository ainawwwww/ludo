<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\GameEnded;
use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\GameInitializerService;
use App\Services\PrivateRoomService;
use App\Services\RoomCodeGenerator;
use App\Services\WalletService;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrivateRoomPreChecksTest extends TestCase
{
    use RefreshDatabase;

    protected PrivateRoomService $roomService;
    protected WalletService $walletService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([TurnChanged::class, GameEnded::class]);

        $this->walletService = new WalletService();
        $this->roomService = new PrivateRoomService(
            $this->walletService,
            new RoomCodeGenerator(),
            app(GameInitializerService::class),
            app(\App\Services\SubscriptionService::class)
        );
    }

    /**
     * Pre-Check 2: Prove WalletService debits the exact same table and column as WalletController and matches QuickMatch transaction format.
     */
    public function test_wallet_service_single_source_of_truth_matches_balance_endpoint(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000]);

        // Debit via WalletService
        $tx = $this->walletService->debit($user, 350, 'room_ref_99', TransactionType::ENTRY_FEE, 'coins');

        // Confirm Transaction row format matches what quick match writes
        $this->assertEquals(-350, $tx->amount);
        $this->assertEquals(TransactionType::ENTRY_FEE, $tx->type);
        $this->assertEquals('coins', $tx->currency_type);
        $this->assertEquals('room_ref_99', $tx->reference_id);

        // Fetch via GET /api/v1/wallet/balance endpoint
        $res = $this->actingAs($user->fresh())->getJson('/api/v1/wallet/balance');
        $res->assertStatus(200);
        $this->assertEquals(650, $res->json('data.coins_balance'));
    }

    /**
     * Pre-Check 3: Win payout works for private room started via PrivateRoomService::start.
     */
    public function test_game_payout_on_move_token_win_for_private_room(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 2000]);
        $guest->wallet->update(['coins_balance' => 2000]);

        $entryFee = 500;
        $room = $this->roomService->create($host, 2, $entryFee);
        $this->roomService->join($guest, $room->room_code);
        $this->roomService->toggleReady($guest, $room, true);

        // Start private room
        $startResult = $this->roomService->start($host, $room);
        $gameId = $startResult['game']->id;

        // Both debited entryFee
        $this->assertEquals(1500, $host->wallet->fresh()->coins_balance);
        $this->assertEquals(1500, $guest->wallet->fresh()->coins_balance);

        // Prepare winning move state: host (seat 0) has rolled 1 with token 0 at step 55 (reaches 56 to win)
        $stateStore = app(RedisGameStateStore::class);
        $state = $stateStore->getState($room->id);
        $state['current_turn_seat'] = 0;
        $state['current_turn_user_id'] = $host->id;
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 1;
        $state['token_positions']['red'] = [55, 56, 56, 56]; // 3 tokens already home, 4th is at 55
        $state['token_positions']['yellow'] = [-1, -1, -1, -1];
        $stateStore->saveState($room->id, $state);

        // Host moves token 0 to win
        $res = $this->actingAs($host)->postJson('/api/v1/quick-match/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ]);

        $res->assertStatus(200);
        $this->assertEquals('completed', $res->json('data.status'));
        $this->assertEquals($host->id, $res->json('data.winner_id'));

        // Prize calculation: entry_fee (500) * max_players (2) = 1000 coins pot
        // Host balance: 1500 + 1000 = 2500!
        $this->assertEquals(2500, $host->wallet->fresh()->coins_balance);

        // Win transaction recorded
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'type' => TransactionType::WIN->value,
            'amount' => 1000,
            'reference_id' => (string) $room->id,
        ]);

        // Room status updated to finished
        $this->assertEquals(RoomStatus::FINISHED, $room->fresh()->status);
    }

    /**
     * Pre-Check 3: Forfeit payout works for private room started via PrivateRoomService::start.
     */
    public function test_game_payout_on_forfeit_for_private_room(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 2000]);
        $guest->wallet->update(['coins_balance' => 2000]);

        $entryFee = 1000;
        $room = $this->roomService->create($host, 2, $entryFee);
        $this->roomService->join($guest, $room->room_code);
        $this->roomService->toggleReady($guest, $room, true);

        // Start private room
        $this->roomService->start($host, $room);

        // Guest forfeits
        $res = $this->actingAs($guest)->postJson('/api/v1/quick-match/forfeit', [
            'room_id' => $room->id,
        ]);

        $res->assertStatus(200);
        $this->assertTrue($res->json('data.is_game_over'));
        $this->assertEquals($host->id, $res->json('data.winner_id'));

        // Total pot = 1000 * 2 = 2000 coins awarded to host
        // Host balance: 1000 (after debit) + 2000 = 3000!
        $this->assertEquals(3000, $host->wallet->fresh()->coins_balance);

        // Win transaction recorded
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'type' => TransactionType::WIN->value,
            'amount' => 2000,
            'reference_id' => (string) $room->id,
        ]);

        // Room finished
        $this->assertEquals(RoomStatus::FINISHED, $room->fresh()->status);
    }

    /**
     * Expiration command cancels stale waiting private rooms.
     */
    public function test_private_rooms_expire_command_cancels_stale_waiting_rooms(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Stale room: created 35 minutes ago, idle (updated 35 minutes ago)
        $staleRoom = Room::create([
            'room_code' => 'STALE1',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::WAITING->value,
            'state_version' => 1,
            'created_by' => $user1->id,
            'created_at' => now()->subMinutes(35),
        ]);
        Room::where('id', $staleRoom->id)->update(['updated_at' => now()->subMinutes(35)]);

        // Active room created 31 minutes ago but updated 5 minutes ago (active)
        $activeRoom = Room::create([
            'room_code' => 'ACTIV1',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::WAITING->value,
            'state_version' => 2,
            'created_by' => $user1->id,
            'created_at' => now()->subMinutes(31),
        ]);
        Room::where('id', $activeRoom->id)->update(['updated_at' => now()->subMinutes(5)]);

        // Fresh room: created 5 minutes ago
        $freshRoom = Room::create([
            'room_code' => 'FRESH1',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::WAITING->value,
            'state_version' => 1,
            'created_by' => $user2->id,
            'created_at' => now()->subMinutes(5),
        ]);
        Room::where('id', $freshRoom->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('private-rooms:expire')
            ->assertSuccessful();

        // Idle stale room is cancelled
        $this->assertEquals(RoomStatus::CANCELLED, $staleRoom->fresh()->status);
        $this->assertEquals(2, $staleRoom->fresh()->state_version);

        // Room created 31 minutes ago with recent activity is NOT cancelled
        $this->assertEquals(RoomStatus::WAITING, $activeRoom->fresh()->status);
        $this->assertEquals(2, $activeRoom->fresh()->state_version);

        // Fresh room is NOT cancelled
        $this->assertEquals(RoomStatus::WAITING, $freshRoom->fresh()->status);
        $this->assertEquals(1, $freshRoom->fresh()->state_version);
    }
}
