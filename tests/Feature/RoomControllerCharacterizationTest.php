<?php

namespace Tests\Feature;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\MatchFound;
use App\Events\RoomUpdated;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomVisit;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoomControllerCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);
        Cache::flush();
    }

    /**
     * Characterization: Quick match creates Room and RoomPlayers with type=public, status=playing, and debits wallets.
     */
    public function test_quick_match_creates_public_playing_room_and_debits_wallets(): void
    {
        Event::fake([MatchFound::class, \App\Events\TurnChanged::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user1->load('wallet');
        $user2->load('wallet');

        $initialBalance1 = $user1->wallet->coins_balance;
        $initialBalance2 = $user2->wallet->coins_balance;
        $entryFee = 150;

        // User 1 joins
        $res1 = $this->actingAs($user1)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 2,
            'entry_fee' => $entryFee,
        ]);
        $res1->assertStatus(200)->assertJsonPath('data.status', 'waiting');

        // User 2 joins -> match created
        $res2 = $this->actingAs($user2)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 2,
            'entry_fee' => $entryFee,
        ]);
        $res2->assertStatus(200)->assertJsonPath('data.status', 'matched');

        $roomId = $res2->json('data.room_id');
        $this->assertNotNull($roomId);

        // Verify Room attributes
        $room = Room::findOrFail($roomId);
        $this->assertEquals(RoomType::PUBLIC, $room->type);
        $this->assertEquals(RoomStatus::PLAYING, $room->status);
        $this->assertEquals(2, $room->max_players);
        $this->assertEquals($entryFee, $room->entry_fee);

        // Verify RoomPlayer rows
        $players = RoomPlayer::where('room_id', $roomId)->orderBy('seat_position')->get();
        $this->assertCount(2, $players);
        $this->assertEquals($user1->id, $players[0]->user_id);
        $this->assertEquals(1, $players[0]->seat_position);
        $this->assertEquals(PlayerColor::RED, $players[0]->color);
        $this->assertTrue((bool)$players[0]->is_ready);

        $this->assertEquals($user2->id, $players[1]->user_id);
        $this->assertEquals(2, $players[1]->seat_position);
        $this->assertEquals(PlayerColor::YELLOW, $players[1]->color);
        $this->assertTrue((bool)$players[1]->is_ready);

        // Verify wallet debits and transactions
        $this->assertEquals($initialBalance1 - $entryFee, $user1->wallet->fresh()->coins_balance);
        $this->assertEquals($initialBalance2 - $entryFee, $user2->wallet->fresh()->coins_balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user1->id,
            'type' => TransactionType::ENTRY_FEE->value,
            'amount' => -$entryFee,
            'reference_id' => (string) $roomId,
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user2->id,
            'type' => TransactionType::ENTRY_FEE->value,
            'amount' => -$entryFee,
            'reference_id' => (string) $roomId,
        ]);

        // Verify Game row created
        $game = \App\Models\Game::where('room_id', $roomId)->first();
        $this->assertNotNull($game);
        $this->assertEquals(\App\Enums\GameStatus::IN_PROGRESS, $game->status);
        $this->assertNotNull($game->started_at);

        // Verify Redis game state
        $stateStore = app(\App\Services\GameEngine\RedisGameStateStore::class);
        $state = $stateStore->getState($roomId);
        $this->assertNotNull($state);
        $this->assertEquals('in_progress', $state['status']);
        $this->assertEquals(0, $state['current_turn_seat']);
        $this->assertEquals($user1->id, $state['current_turn_user_id']);
        $this->assertCount(2, $state['players']);

        // Verify events broadcasted
        Event::assertDispatched(MatchFound::class, 2);
        Event::assertDispatched(\App\Events\TurnChanged::class, function ($event) use ($roomId, $user1) {
            return $event->roomId === $roomId && $event->currentTurnUserId === $user1->id && $event->currentTurnSeat === 0;
        });

        // Verify ProcessTurnTimeout queued with 15s delay
        Queue::assertPushed(ProcessTurnTimeout::class, function ($job) use ($roomId) {
            $actualTimestamp = $job->delay instanceof \DateTimeInterface ? $job->delay->getTimestamp() : (int) $job->delay;
            $expectedTimestamp = now()->addSeconds(15)->timestamp;
            return $job->roomId === $roomId && abs($actualTimestamp - $expectedTimestamp) <= 2;
        });
    }

    /**
     * Characterization: POST /api/v1/rooms (store) creates room, seats creator at seat 1, and decrements wallet balance.
     */
    public function test_room_store_creates_room_seats_creator_and_decrements_wallet(): void
    {
        $creator = User::factory()->create();
        $creator->load('wallet');
        $initialBalance = $creator->wallet->coins_balance;
        $entryFee = 200;

        $res = $this->actingAs($creator)->postJson('/api/v1/rooms', [
            'title' => 'Test Lounge',
            'max_players' => 4,
            'entry_fee' => $entryFee,
            'type' => RoomType::PUBLIC->value,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.title', 'Test Lounge')
            ->assertJsonPath('data.type', RoomType::PUBLIC->value)
            ->assertJsonPath('data.status', RoomStatus::WAITING->value);

        $roomId = $res->json('data.id');
        $this->assertNotNull($roomId);

        // Check wallet decremented
        $this->assertEquals($initialBalance - $entryFee, $creator->wallet->fresh()->coins_balance);

        // Creator seated at Seat 1 with Red
        $this->assertDatabaseHas('room_players', [
            'room_id' => $roomId,
            'user_id' => $creator->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
            'is_ready' => 1,
        ]);
    }

    /**
     * Characterization: GET /api/v1/rooms/{id_or_code} resolves by both numeric ID and room_code.
     */
    public function test_room_show_resolves_by_numeric_id_and_room_code(): void
    {
        $creator = User::factory()->create();
        $room = Room::create([
            'room_code' => 'ABC123',
            'title' => 'Show Room',
            'type' => RoomType::PUBLIC->value,
            'max_players' => 4,
            'entry_fee' => 0,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $creator->id,
        ]);

        // Access by numeric ID
        $resId = $this->actingAs($creator)->getJson("/api/v1/rooms/{$room->id}");
        $resId->assertStatus(200)->assertJsonPath('data.room_code', 'ABC123');

        // Access by room code
        $resCode = $this->actingAs($creator)->getJson('/api/v1/rooms/ABC123');
        $resCode->assertStatus(200)->assertJsonPath('data.id', $room->id);
    }

    /**
     * Characterization: POST /api/v1/rooms/join joins room via room_code, validates coins, and assigns seat.
     */
    public function test_room_join_by_room_code(): void
    {
        Event::fake([RoomUpdated::class]);

        $creator = User::factory()->create();
        $room = Room::create([
            'room_code' => 'JOIN01',
            'title' => 'Join Room',
            'type' => RoomType::PUBLIC->value,
            'max_players' => 4,
            'entry_fee' => 50,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $creator->id,
        ]);
        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
            'is_ready' => true,
        ]);

        $joiner = User::factory()->create();
        $joiner->load('wallet');

        $res = $this->actingAs($joiner)->postJson('/api/v1/rooms/join', [
            'room_code' => 'JOIN01',
        ]);

        $res->assertStatus(200)->assertJsonPath('status', 'success');

        // Verify joiner is assigned seat 2
        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $joiner->id,
            'seat_position' => 2,
        ]);
    }

    /**
     * Characterization: POST /api/v1/rooms/{room}/join (joinAsListener) auto-seats unseated user, decrements wallet, and logs RoomVisit.
     */
    public function test_room_join_as_listener_auto_seats_and_decrements_wallet(): void
    {
        Event::fake([RoomUpdated::class]);

        $creator = User::factory()->create();
        $room = Room::create([
            'room_code' => 'LIST01',
            'title' => 'Listener Room',
            'type' => RoomType::PUBLIC->value,
            'max_players' => 4,
            'entry_fee' => 100,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $creator->id,
        ]);

        $visitor = User::factory()->create();
        $visitor->load('wallet');
        $initialBalance = $visitor->wallet->coins_balance;

        $res = $this->actingAs($visitor)->postJson("/api/v1/rooms/{$room->id}/join");
        $res->assertStatus(200)->assertJsonPath('status', 'success');

        // Check wallet decremented
        $this->assertEquals($initialBalance - 100, $visitor->wallet->fresh()->coins_balance);

        // Check visitor auto-seated
        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $visitor->id,
        ]);

        // Check RoomVisit recorded
        $this->assertDatabaseHas('room_visits', [
            'room_id' => $room->id,
            'user_id' => $visitor->id,
        ]);
    }

    /**
     * Characterization: POST /api/v1/rooms/{room}/seat and leave-seat.
     */
    public function test_room_take_seat_and_leave_seat(): void
    {
        Event::fake([RoomUpdated::class]);

        $creator = User::factory()->create();
        $room = Room::create([
            'room_code' => 'SEAT01',
            'title' => 'Seat Room',
            'type' => RoomType::PUBLIC->value,
            'max_players' => 4,
            'entry_fee' => 0,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $creator->id,
        ]);
        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
            'is_ready' => true,
        ]);

        $player2 = User::factory()->create();

        // Player 2 takes Seat 3
        $resTake = $this->actingAs($player2)->postJson("/api/v1/rooms/{$room->id}/seat", [
            'seat_position' => 3,
        ]);
        $resTake->assertStatus(200);
        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $player2->id,
            'seat_position' => 3,
        ]);

        // Player 2 leaves seat
        $resLeave = $this->actingAs($player2)->postJson("/api/v1/rooms/{$room->id}/leave-seat");
        $resLeave->assertStatus(200);
        $this->assertDatabaseMissing('room_players', [
            'room_id' => $room->id,
            'user_id' => $player2->id,
        ]);

        // Host in Seat 1 attempts to leave-seat: host cannot vacate Seat 1
        $resHostLeave = $this->actingAs($creator)->postJson("/api/v1/rooms/{$room->id}/leave-seat");
        $resHostLeave->assertStatus(200);
        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'seat_position' => 1,
        ]);
    }
}
