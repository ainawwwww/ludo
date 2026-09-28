<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\DiceRolled;
use App\Events\GameEnded;
use App\Events\PrivateRoomUpdated;
use App\Events\TokenMoved;
use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\PrivateRoomService;
use App\Services\WalletService;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrivateRoomRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private User $guest;
    private User $outsider;
    private PrivateRoomService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
        $this->host->wallet->update(['coins_balance' => 5000]);
        $this->guest = User::factory()->create();
        $this->guest->wallet->update(['coins_balance' => 5000]);
        $this->outsider = User::factory()->create();
        $this->outsider->wallet->update(['coins_balance' => 5000]);

        $this->service = app(PrivateRoomService::class);
    }

    private function enableBroadcastAuth(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'fake_key',
            'broadcasting.connections.pusher.secret' => 'fake_secret',
            'broadcasting.connections.pusher.app_id' => '12345',
        ]);
        require base_path('routes/channels.php');
    }

    /**
     * Test B: Channel authorization for private lobby and game channels.
     */
    public function test_lobby_channel_authorization(): void
    {
        $this->enableBroadcastAuth();
        Event::fake([PrivateRoomUpdated::class]);

        $room = $this->service->create($this->host, 2, 500, 15);
        $this->service->join($this->guest, $room->room_code);

        // Member (guest) allowed on room-lobby.{roomId}
        $response = $this->actingAs($this->guest, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-room-lobby.' . $room->id,
            'socket_id' => '1234.5678',
        ]);
        $response->assertStatus(200);

        // Non-member (outsider) denied (403)
        $response = $this->actingAs($this->outsider, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-room-lobby.' . $room->id,
            'socket_id' => '1234.5678',
        ]);
        $response->assertStatus(403);

        // Cancelled room members still allowed to receive cancellation
        $this->service->leave($this->host, $room); // Host leaving cancels room
        $this->assertEquals(RoomStatus::CANCELLED, $room->fresh()->status);

        $response = $this->actingAs($this->guest, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-room-lobby.' . $room->id,
            'socket_id' => '1234.5678',
        ]);
        $response->assertStatus(200);

        // Lobby denied for public room
        $publicRoom = Room::create([
            'room_code' => 'PUB101',
            'type' => RoomType::PUBLIC,
            'status' => RoomStatus::WAITING,
            'entry_fee' => 0,
            'created_by' => $this->host->id,
            'max_players' => 2,
        ]);

        $response = $this->actingAs($this->host, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-room-lobby.' . $publicRoom->id,
            'socket_id' => '1234.5678',
        ]);
        $response->assertStatus(403);
    }

    public function test_game_channel_authorization_is_type_aware(): void
    {
        $this->enableBroadcastAuth();

        // 1. Private room: member allowed, outsider denied
        $privateRoom = $this->service->create($this->host, 2, 0, 15);

        $response = $this->actingAs($this->host, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-room.' . $privateRoom->id,
            'socket_id' => '1234.5678',
        ]);
        $response->assertStatus(200);

        $response = $this->actingAs($this->outsider, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-room.' . $privateRoom->id,
            'socket_id' => '1234.5678',
        ]);
        $response->assertStatus(403);

        // 2. Public room: any authenticated user allowed (unchanged legacy behavior)
        $publicRoom = Room::create([
            'room_code' => 'PUB202',
            'type' => RoomType::PUBLIC,
            'status' => RoomStatus::WAITING,
            'entry_fee' => 0,
            'created_by' => $this->host->id,
            'max_players' => 2,
        ]);

        $response = $this->actingAs($this->outsider, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-room.' . $publicRoom->id,
            'socket_id' => '1234.5678',
        ]);
        $response->assertStatus(200);
    }

    /**
     * Test C: PrivateRoomUpdated event dispatched with correct reasons, payloads, and user-agnostic snapshot.
     */
    public function test_events_dispatched_with_correct_reasons_and_payloads(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([PrivateRoomUpdated::class]);

        // 1. Create room (no event needed for creator, creator gets API response)
        $room = $this->service->create($this->host, 2, 500, 15);
        Event::assertNotDispatched(PrivateRoomUpdated::class);

        // 2. Guest joins -> reason 'joined'
        $this->service->join($this->guest, $room->room_code);
        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room) {
            return $e->roomId === $room->id
                && $e->reason === 'joined'
                && $e->actorUserId === $this->guest->id
                && $e->version === 2
                && !array_key_exists('my_seat', $e->snapshot)
                && !array_key_exists('is_host', $e->snapshot);
        });

        // 3. Guest ready -> reason 'ready'
        $this->service->toggleReady($this->guest, $room->id, true);
        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room) {
            return $e->roomId === $room->id
                && $e->reason === 'ready'
                && $e->actorUserId === $this->guest->id
                && $e->version === 3;
        });

        // 4. Start match -> reason 'started', status playing, game_id set
        $result = $this->service->start($this->host, $room->id);
        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room, $result) {
            return $e->roomId === $room->id
                && $e->reason === 'started'
                && $e->actorUserId === $this->host->id
                && $e->version === 4
                && $e->snapshot['status'] === RoomStatus::PLAYING->value
                && $e->snapshot['game_id'] === $result['game']->id;
        });
    }

    public function test_guest_leave_and_host_cancel_events(): void
    {
        Event::fake([PrivateRoomUpdated::class]);

        $room = $this->service->create($this->host, 2, 0, 15);
        $this->service->join($this->guest, $room->room_code);

        // Guest leaves -> reason 'left'
        $this->service->leave($this->guest, $room->id);
        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room) {
            return $e->roomId === $room->id
                && $e->reason === 'left'
                && $e->actorUserId === $this->guest->id;
        });

        // Guest rejoins and host leaves -> reason 'cancelled'
        $this->service->join($this->guest, $room->room_code);
        $this->service->leave($this->host, $room->id);

        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room) {
            return $e->roomId === $room->id
                && $e->reason === 'cancelled'
                && $e->actorUserId === $this->host->id
                && $e->snapshot['status'] === RoomStatus::CANCELLED->value;
        });
    }

    /**
     * Test C: Atomic rollback safety - NO event broadcast if start fails due to insufficient balance.
     */
    public function test_no_broadcast_event_on_transaction_rollback(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([PrivateRoomUpdated::class]);

        $poorGuest = User::factory()->create();
        $poorGuest->wallet->update(['coins_balance' => 500]);
        $room = $this->service->create($this->host, 2, 500, 15);
        $this->service->join($poorGuest, $room->room_code);
        $this->service->toggleReady($poorGuest, $room->id, true);

        // Guest balance drops to 10 before host starts match
        $poorGuest->wallet->update(['coins_balance' => 10]);

        // Clear join & ready events from fake
        Event::fake([PrivateRoomUpdated::class]);

        try {
            $this->service->start($this->host, $room->id);
            $this->fail('Expected exception for insufficient balance');
        } catch (\App\Exceptions\PrivateRoomException $e) {
            // Expected
        }

        // Room must still be waiting, version must NOT have incremented, NO started event dispatched
        $room->refresh();
        $this->assertEquals(RoomStatus::WAITING, $room->status);
        Event::assertNotDispatched(PrivateRoomUpdated::class);
    }

    /**
     * Test C: Version strictly increasing across sequence of actions.
     */
    public function test_version_strictly_increasing(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);
        $dispatchedVersions = [];

        Event::listen(PrivateRoomUpdated::class, function ($event) use (&$dispatchedVersions) {
            $dispatchedVersions[] = $event->version;
        });

        $room = $this->service->create($this->host, 2, 0, 15); // v1

        $this->service->join($this->guest, $room->room_code); // v2
        $this->service->toggleReady($this->guest, $room->id, true); // v3
        $this->service->start($this->host, $room->id); // v4

        $this->assertEquals([2, 3, 4], $dispatchedVersions);
    }

    /**
     * Test C & D: Expire commands broadcast PrivateRoomUpdated with reason 'expired'.
     */
    public function test_expire_commands_broadcast(): void
    {
        Event::fake([PrivateRoomUpdated::class]);

        // Stale waiting room
        $staleRoom = Room::create([
            'room_code' => 'STALE1',
            'type' => RoomType::PRIVATE,
            'status' => RoomStatus::WAITING,
            'entry_fee' => 0,
            'turn_seconds' => 15,
            'created_by' => $this->host->id,
            'max_players' => 2,
            'state_version' => 1,
            'updated_at' => now()->subMinutes(35),
            'created_at' => now()->subMinutes(35),
        ]);
        RoomPlayer::create([
            'room_id' => $staleRoom->id,
            'user_id' => $this->host->id,
            'seat_position' => 1,
            'color' => 'red',
            'is_ready' => true,
        ]);

        $this->artisan('private-rooms:expire')->assertSuccessful();

        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($staleRoom) {
            return $e->roomId === $staleRoom->id
                && $e->reason === 'expired'
                && $e->snapshot['status'] === RoomStatus::CANCELLED->value
                && $e->version === 2;
        });

        // Stuck playing room with finished game
        $stuckRoom = Room::create([
            'room_code' => 'STUCK1',
            'type' => RoomType::PRIVATE,
            'status' => RoomStatus::PLAYING,
            'entry_fee' => 0,
            'turn_seconds' => 15,
            'created_by' => $this->host->id,
            'max_players' => 2,
            'state_version' => 5,
            'updated_at' => now()->subHours(4),
            'created_at' => now()->subHours(4),
        ]);
        RoomPlayer::create([
            'room_id' => $stuckRoom->id,
            'user_id' => $this->host->id,
            'seat_position' => 1,
            'color' => 'red',
            'is_ready' => true,
        ]);

        $this->artisan('private-rooms:expire-stuck')->assertSuccessful();

        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($stuckRoom) {
            return $e->roomId === $stuckRoom->id
                && $e->reason === 'expired'
                && $e->snapshot['status'] === RoomStatus::FINISHED->value
                && $e->version === 6;
        });
    }

    /**
     * Test E: Regression test: Quick match and public rooms broadcast legacy events on room.{roomId} (DUAL channels).
     * Private rooms broadcast on PrivateChannel ONLY (1 channel, no unauthenticated public channel).
     */
    public function test_quick_match_and_public_room_broadcasts_unchanged(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([DiceRolled::class, TokenMoved::class, TurnChanged::class, GameEnded::class]);

        // ---- Public / quick-match: 2 channels ----
        $diceEvent = new DiceRolled(999, 0, 101, 5, [1, 2], false);
        $channels = $diceEvent->broadcastOn();
        $this->assertCount(2, $channels);
        $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-room.999', $channels[0]->name);
        $this->assertInstanceOf(\Illuminate\Broadcasting\Channel::class, $channels[1]);
        $this->assertEquals('room.999', $channels[1]->name);
        $this->assertEquals('dice.rolled', $diceEvent->broadcastAs());

        $tokenEvent = new TokenMoved(999, 0, 101, 'red', 0, 0, 5, ['x' => 1, 'y' => 6], false, [], false, false);
        $channels = $tokenEvent->broadcastOn();
        $this->assertCount(2, $channels);
        $this->assertEquals('private-room.999', $channels[0]->name);
        $this->assertEquals('room.999', $channels[1]->name);
        $this->assertEquals('token.moved', $tokenEvent->broadcastAs());

        $turnEvent = new TurnChanged(999, 1, 102, false, false);
        $channels = $turnEvent->broadcastOn();
        $this->assertCount(2, $channels);
        $this->assertEquals('private-room.999', $channels[0]->name);
        $this->assertEquals('room.999', $channels[1]->name);
        $this->assertEquals('turn.changed', $turnEvent->broadcastAs());

        $gameEndedEvent = new GameEnded(999, 42, 101, 'Winner', 400, false);
        $channels = $gameEndedEvent->broadcastOn();
        $this->assertCount(2, $channels);
        $this->assertEquals('private-room.999', $channels[0]->name);
        $this->assertEquals('room.999', $channels[1]->name);
        $this->assertEquals('game.ended', $gameEndedEvent->broadcastAs());

        // ---- Private room: 1 channel (PrivateChannel ONLY) ----
        $dicePrivate = new DiceRolled(42, 0, 201, 3, [], true);
        $privateChannels = $dicePrivate->broadcastOn();
        $this->assertCount(1, $privateChannels);
        $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $privateChannels[0]);
        $this->assertEquals('private-room.42', $privateChannels[0]->name);

        $tokenPrivate = new TokenMoved(42, 0, 201, 'red', 0, 0, 5, ['x' => 1, 'y' => 6], false, [], false, true);
        $this->assertCount(1, $tokenPrivate->broadcastOn());

        $turnPrivate = new TurnChanged(42, 1, 202, false, true);
        $this->assertCount(1, $turnPrivate->broadcastOn());

        $endedPrivate = new GameEnded(42, 10, 201, 'Host', 1000, true);
        $this->assertCount(1, $endedPrivate->broadcastOn());
    }

    /**
     * Test F (Item 6): Real-broadcaster test without Event::fake.
     * Uses the 'log' driver so events are truly dispatched through the broadcasting pipeline.
     * Asserts:
     *  a) join/ready/start each dispatch exactly ONE PrivateRoomUpdated (via Event::listen).
     *  b) A failed start (insufficient balance on a guest) dispatches ZERO events and leaves the room in WAITING.
     */
    public function test_real_broadcaster_dispatches_only_after_commit_and_failed_start_sends_nothing(): void
    {
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);

        // Configure log broadcaster so we exercise the real broadcast pipeline without Pusher.
        config([
            'broadcasting.default' => 'log',
        ]);

        // ---- Happy path: join -> ready -> start dispatches 3 events ----
        $host  = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance'  => 5000]);
        $guest->wallet->update(['coins_balance' => 5000]);

        $dispatched = [];
        Event::listen(PrivateRoomUpdated::class, function ($e) use (&$dispatched) {
            $dispatched[] = [$e->reason, $e->version];
        });

        $room = $this->service->create($host, 2, 500, 15);         // no event on create
        $this->assertCount(0, $dispatched);

        $this->service->join($guest, $room->room_code);             // reason=joined, v2
        $this->assertCount(1, $dispatched);
        $this->assertEquals('joined', $dispatched[0][0]);
        $this->assertEquals(2, $dispatched[0][1]);

        $this->service->toggleReady($guest, $room->id, true);      // reason=ready, v3
        $this->assertCount(2, $dispatched);
        $this->assertEquals('ready', $dispatched[1][0]);

        $this->service->start($host, $room->id);                   // reason=started, v4
        $this->assertCount(3, $dispatched);
        $this->assertEquals('started', $dispatched[2][0]);
        $this->assertEquals(4, $dispatched[2][1]);

        // ---- Failed start: insufficient balance -> zero events, room stays WAITING ----
        $host2  = User::factory()->create();
        $broke  = User::factory()->create();
        $host2->wallet->update(['coins_balance' => 5000]);
        $broke->wallet->update(['coins_balance' => 5000]); // enough to pass join pre-check

        $dispatched2 = [];
        Event::listen(PrivateRoomUpdated::class, function ($e) use (&$dispatched2) {
            $dispatched2[] = $e->reason;
        });

        $room2 = $this->service->create($host2, 2, 500, 15);
        $this->service->join($broke, $room2->room_code);   // join succeeds
        $this->service->toggleReady($broke, $room2->id, true);
        $dispatchedBeforeFailedStart = count($dispatched2);

        // Drain broke's wallet AFTER join/ready so start debit fails
        $broke->wallet->update(['coins_balance' => 10]);

        try {
            $this->service->start($host2, $room2->id);
            $this->fail('Expected PrivateRoomException for insufficient balance');
        } catch (\App\Exceptions\PrivateRoomException $e) {
            // Expected: broke guest has only 10 coins, entry fee is 500
        }

        // No additional 'started' event dispatched
        $this->assertCount($dispatchedBeforeFailedStart, $dispatched2);
        $this->assertNotContains('started', $dispatched2);

        // Room must still be WAITING (transaction rolled back)
        $this->assertEquals(RoomStatus::WAITING, $room2->fresh()->status);
    }
}
