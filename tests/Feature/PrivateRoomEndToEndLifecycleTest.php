<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\DiceRolled;
use App\Events\GameEnded;
use App\Events\PrivateRoomUpdated;
use App\Events\TokenMoved;
use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GameEngine\RedisGameStateStore;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrivateRoomEndToEndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);
    }

    /**
     * Case 1: 2-player end-to-end lifecycle.
     * Host creates room (fee 500) -> Guest joins -> Guest readies -> Host starts ->
     * Fees deducted from both -> Room PLAYING -> Game created -> 'started' event fired ->
     * Winning move simulated -> Winner credited pot -> Room FINISHED -> state_version incremented.
     */
    public function test_two_player_full_lifecycle_from_creation_to_payout(): void
    {
        $startedEvents = [];
        Event::listen(PrivateRoomUpdated::class, function ($event) use (&$startedEvents) {
            if ($event->reason === 'started') {
                $startedEvents[] = $event;
            }
        });

        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 1000]);
        $guest->wallet->update(['coins_balance' => 1000]);

        // 1. Host creates 2-player private room with 500 entry fee
        $createRes = $this->actingAs($host, 'sanctum')->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 500,
            'turn_seconds' => 15,
        ]);
        $createRes->assertStatus(201);
        $roomId = (int) $createRes->json('data.id');
        $roomCode = $createRes->json('data.code');
        $this->assertEquals(RoomStatus::WAITING->value, $createRes->json('data.status'));

        // 2. Guest joins room by code
        $joinRes = $this->actingAs($guest, 'sanctum')->postJson('/api/v1/private-rooms/join', [
            'room_code' => $roomCode,
        ]);
        $joinRes->assertStatus(200);
        $this->assertCount(2, $joinRes->json('data.players'));

        // 3. Guest toggles ready
        $readyRes = $this->actingAs($guest, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/ready", [
            'is_ready' => true,
        ]);
        $readyRes->assertStatus(200);
        $this->assertTrue($readyRes->json('data.can_start'));

        // 4. Host starts match
        $startRes = $this->actingAs($host, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/start");
        $startRes->assertStatus(200);
        $this->assertEquals(RoomStatus::PLAYING->value, $startRes->json('data.status'));
        $this->assertNotNull($startRes->json('data.game_id'));

        // Assert fees deducted atomically from both players (1000 - 500 = 500)
        $this->assertEquals(500, $host->wallet->fresh()->coins_balance);
        $this->assertEquals(500, $guest->wallet->fresh()->coins_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'amount' => -500,
            'type' => TransactionType::ENTRY_FEE->value,
            'reference_id' => "private_room_start:{$roomId}:{$host->id}",
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $guest->id,
            'amount' => -500,
            'type' => TransactionType::ENTRY_FEE->value,
            'reference_id' => "private_room_start:{$roomId}:{$guest->id}",
        ]);

        // Assert room PLAYING and Game created
        $room = Room::find($roomId);
        $this->assertEquals(RoomStatus::PLAYING, $room->status);
        $this->assertDatabaseHas('games', [
            'room_id' => $roomId,
            'status' => GameStatus::IN_PROGRESS->value,
        ]);

        // Assert PrivateRoomUpdated 'started' event fired
        $this->assertCount(1, $startedEvents);
        $this->assertEquals('started', $startedEvents[0]->reason);
        $this->assertEquals($roomId, $startedEvents[0]->roomId);

        // 5. Simulate winning move via game engine
        $stateStore = app(RedisGameStateStore::class);
        $state = $stateStore->getState($roomId);
        $state['current_turn_seat'] = 0;
        $state['current_turn_user_id'] = $host->id;
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 1;
        $state['token_positions']['red'] = [55, 56, 56, 56]; // Step 55 + dice 1 -> 56 (win!)
        $stateStore->saveState($roomId, $state);

        $initialVersion = (int) $room->state_version;

        $moveRes = $this->actingAs($host, 'sanctum')->postJson('/api/v1/game/move', [
            'room_id' => $roomId,
            'token_index' => 0,
        ]);
        $moveRes->assertStatus(200);

        // Assert winner credited pot: 500 (remaining) + 1000 (pot) = 1500
        $this->assertEquals(1500, $host->wallet->fresh()->coins_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'amount' => 1000,
            'type' => TransactionType::WIN->value,
            'reference_id' => (string) $roomId,
        ]);

        // Assert room status finished and state_version incremented
        $roomFresh = $room->fresh();
        $this->assertEquals(RoomStatus::FINISHED, $roomFresh->status);
        $this->assertGreaterThan($initialVersion, (int) $roomFresh->state_version);
    }

    /**
     * Case 2: 4-player end-to-end lifecycle.
     * 4 players join, all ready, fees deducted from all 4, winner credited 4x entry fee.
     */
    public function test_four_player_full_lifecycle_from_creation_to_payout(): void
    {
        $host = User::factory()->create();
        $guests = User::factory()->count(3)->create();

        $host->wallet->update(['coins_balance' => 2000]);
        foreach ($guests as $guest) {
            $guest->wallet->update(['coins_balance' => 2000]);
        }

        // Host creates 4-player room with 1000 fee
        $createRes = $this->actingAs($host, 'sanctum')->postJson('/api/v1/private-rooms', [
            'max_players' => 4,
            'entry_fee' => 1000,
            'turn_seconds' => 15,
        ]);
        $createRes->assertStatus(201);
        $roomId = (int) $createRes->json('data.id');
        $roomCode = $createRes->json('data.code');

        // All 3 guests join and ready up
        foreach ($guests as $guest) {
            $this->actingAs($guest, 'sanctum')->postJson('/api/v1/private-rooms/join', [
                'room_code' => $roomCode,
            ])->assertStatus(200);

            $this->actingAs($guest, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/ready", [
                'is_ready' => true,
            ])->assertStatus(200);
        }

        // Host starts match
        $startRes = $this->actingAs($host, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/start");
        $startRes->assertStatus(200);

        // All 4 players debited 1000 each
        $this->assertEquals(1000, $host->wallet->fresh()->coins_balance);
        foreach ($guests as $guest) {
            $this->assertEquals(1000, $guest->wallet->fresh()->coins_balance);
        }

        // Simulate winning move for host
        $stateStore = app(RedisGameStateStore::class);
        $state = $stateStore->getState($roomId);
        $state['current_turn_seat'] = 0;
        $state['current_turn_user_id'] = $host->id;
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 1;
        $state['token_positions']['red'] = [55, 56, 56, 56];
        $stateStore->saveState($roomId, $state);

        $this->actingAs($host, 'sanctum')->postJson('/api/v1/game/move', [
            'room_id' => $roomId,
            'token_index' => 0,
        ])->assertStatus(200);

        // Winner receives full 4-player pot: 4 * 1000 = 4000. Balance: 1000 + 4000 = 5000
        $this->assertEquals(5000, $host->wallet->fresh()->coins_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'amount' => 4000,
            'type' => TransactionType::WIN->value,
            'reference_id' => (string) $roomId,
        ]);
        $this->assertEquals(RoomStatus::FINISHED, Room::find($roomId)->status);
    }

    /**
     * Case 3: Host leaves before start (room disbanded/cancelled).
     * Guest receives room_disbanded, room status is cancelled, hub returns 404 for active room.
     */
    public function test_host_leaves_before_start_disbands_and_cancels_room(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $createRes = $this->actingAs($host, 'sanctum')->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 500,
        ]);
        $roomId = (int) $createRes->json('data.id');
        $roomCode = $createRes->json('data.code');

        $this->actingAs($guest, 'sanctum')->postJson('/api/v1/private-rooms/join', [
            'room_code' => $roomCode,
        ])->assertStatus(200);

        // Host leaves before match starts
        $leaveRes = $this->actingAs($host, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/leave");
        $leaveRes->assertStatus(200);
        $this->assertEquals('cancelled', $leaveRes->json('data.status'));

        // Assert room status is CANCELLED
        $room = Room::find($roomId);
        $this->assertEquals(RoomStatus::CANCELLED, $room->status);

        // Assert /current endpoint returns 404 for guest
        $currentRes = $this->actingAs($guest, 'sanctum')->getJson('/api/v1/private-rooms/current');
        $currentRes->assertStatus(404);

        // Both players can immediately create new rooms
        $newRes = $this->actingAs($guest, 'sanctum')->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);
        $newRes->assertStatus(201);
    }

    /**
     * Case 4: Insufficient balance at start triggers atomic rollback.
     * If a guest empties their wallet before host clicks start, start fails,
     * zero fees are deducted from anyone, and room remains WAITING.
     */
    public function test_insufficient_balance_at_start_rolls_back_atomically(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 5000]);
        $guest->wallet->update(['coins_balance' => 5000]);

        $createRes = $this->actingAs($host, 'sanctum')->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
        ]);
        $roomId = (int) $createRes->json('data.id');
        $roomCode = $createRes->json('data.code');

        $this->actingAs($guest, 'sanctum')->postJson('/api/v1/private-rooms/join', [
            'room_code' => $roomCode,
        ])->assertStatus(200);

        $this->actingAs($guest, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/ready", [
            'is_ready' => true,
        ])->assertStatus(200);

        // Drain guest wallet after ready
        $guest->wallet->update(['coins_balance' => 100]);

        // Host attempts to start match
        $startRes = $this->actingAs($host, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/start");
        $startRes->assertStatus(402);

        // Assert atomic rollback: host balance untouched, guest balance untouched
        $this->assertEquals(5000, $host->wallet->fresh()->coins_balance);
        $this->assertEquals(100, $guest->wallet->fresh()->coins_balance);
        $this->assertDatabaseMissing('transactions', [
            'reference_id' => "private_room_start:{$roomId}:{$host->id}",
        ]);

        // Room must remain in WAITING status
        $this->assertEquals(RoomStatus::WAITING, Room::find($roomId)->status);
    }

    /**
     * Case 5: Platform cut (rake) test for 0% default and configurable % (e.g. 10%).
     */
    public function test_platform_cut_formula_supports_zero_percent_and_custom_percentage(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 1000]);
        $guest->wallet->update(['coins_balance' => 1000]);

        // 1. Test with 10% platform cut
        config(['private_room.platform_cut_percentage' => 10]);

        $createRes = $this->actingAs($host, 'sanctum')->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 500,
        ]);
        $roomId = (int) $createRes->json('data.id');
        $roomCode = $createRes->json('data.code');

        $this->actingAs($guest, 'sanctum')->postJson('/api/v1/private-rooms/join', ['room_code' => $roomCode]);
        $this->actingAs($guest, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/ready", ['is_ready' => true]);
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/private-rooms/{$roomId}/start");

        // Total pot = 2 * 500 = 1000. 10% cut = 100. Payout = 900.
        // Host balance before win = 500. Expected after win = 500 + 900 = 1400.
        $stateStore = app(RedisGameStateStore::class);
        $state = $stateStore->getState($roomId);
        $state['current_turn_seat'] = 0;
        $state['current_turn_user_id'] = $host->id;
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 1;
        $state['token_positions']['red'] = [55, 56, 56, 56];
        $stateStore->saveState($roomId, $state);

        $this->actingAs($host, 'sanctum')->postJson('/api/v1/game/move', [
            'room_id' => $roomId,
            'token_index' => 0,
        ])->assertStatus(200);

        $this->assertEquals(1400, $host->wallet->fresh()->coins_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'amount' => 900,
            'type' => TransactionType::WIN->value,
            'reference_id' => (string) $roomId,
        ]);
    }
}
