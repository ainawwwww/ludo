<?php

namespace Tests\Unit;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\TurnChanged;
use App\Exceptions\PrivateRoomException;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GameInitializerService;
use App\Services\PrivateRoomService;
use App\Services\RoomCodeGenerator;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrivateRoomServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PrivateRoomService $service;
    protected WalletService $walletService;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([TurnChanged::class]);

        $this->walletService = new WalletService();
        $this->service = new PrivateRoomService(
            $this->walletService,
            new RoomCodeGenerator(),
            app(GameInitializerService::class)
        );
    }

    public function test_create_validates_whitelist_and_seats_host_at_seat_one(): void
    {
        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 2000]);

        $room = $this->service->create($host, 2, 500, 15, 'My 2P Room');

        $this->assertEquals(RoomType::PRIVATE, $room->type);
        $this->assertEquals(RoomStatus::WAITING, $room->status);
        $this->assertEquals(2, $room->max_players);
        $this->assertEquals(500, $room->entry_fee);
        $this->assertEquals(15, $room->turn_seconds);
        $this->assertEquals($host->id, $room->created_by);
        $this->assertEquals(1, $room->state_version);

        // Host seated at Seat 1 with Red and ready
        $player = RoomPlayer::where('room_id', $room->id)->first();
        $this->assertNotNull($player);
        $this->assertEquals($host->id, $player->user_id);
        $this->assertEquals(1, $player->seat_position);
        $this->assertEquals(PlayerColor::RED, $player->color);
        $this->assertTrue((bool) $player->is_ready);
    }

    public function test_create_rejects_non_whitelisted_settings(): void
    {
        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 5000]);

        // Invalid max_players (e.g. 3)
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($host, 3, 500);
    }

    public function test_join_handles_race_for_last_seat_and_rejects_when_full(): void
    {
        $host = User::factory()->create();
        $room = $this->service->create($host, 2, 0); // 2-player room

        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // User 2 joins -> succeeds and takes Seat 2 (Yellow)
        $player2 = $this->service->join($user2, $room->room_code);
        $this->assertEquals(2, $player2->seat_position);
        $this->assertEquals(PlayerColor::YELLOW, $player2->color);
        $this->assertFalse((bool) $player2->is_ready);

        // User 3 attempts to join 2-player room -> throws ROOM_FULL
        try {
            $this->service->join($user3, $room->room_code);
            $this->fail('Expected PrivateRoomException with ROOM_FULL');
        } catch (PrivateRoomException $e) {
            $this->assertEquals('ROOM_FULL', $e->getErrorCode());
        }
    }

    public function test_host_leave_disbands_room_and_cancels_without_deleting_players(): void
    {
        $host = User::factory()->create();
        $room = $this->service->create($host, 2, 0);

        $guest = User::factory()->create();
        $this->service->join($guest, $room->room_code);

        $this->assertEquals(2, $room->players()->count());

        // Host leaves
        $result = $this->service->leave($host, $room);

        $this->assertEquals('room_disbanded', $result['action']);
        $freshRoom = Room::findOrFail($room->id);
        $this->assertEquals(RoomStatus::CANCELLED, $freshRoom->status);
        // Player rows must NOT be deleted so events and snapshots can still be produced
        $this->assertEquals(2, $freshRoom->players()->count());
    }

    public function test_leave_during_playing_status_throws_exception(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $room = $this->service->create($host, 2, 0);
        $this->service->join($guest, $room->room_code);
        $this->service->toggleReady($guest, $room, true);
        $this->service->start($host, $room);

        // Try to leave while room is PLAYING
        $this->expectException(PrivateRoomException::class);
        $this->service->leave($guest, $room);
    }

    public function test_non_host_leave_removes_player_and_keeps_room_waiting(): void
    {
        $host = User::factory()->create();
        $room = $this->service->create($host, 2, 0);

        $guest = User::factory()->create();
        $this->service->join($guest, $room->room_code);

        // Guest leaves
        $result = $this->service->leave($guest, $room);

        $this->assertEquals('player_left', $result['action']);
        $freshRoom = Room::findOrFail($room->id);
        $this->assertEquals(RoomStatus::WAITING, $freshRoom->status);
        $this->assertEquals(1, $freshRoom->players()->count());
    }

    public function test_user_cannot_create_or_join_if_already_in_active_room(): void
    {
        $user = User::factory()->create();
        $room1 = $this->service->create($user, 2, 0);

        // Try creating second active room
        try {
            $this->service->create($user, 2, 0);
            $this->fail('Expected ALREADY_IN_ROOM exception');
        } catch (PrivateRoomException $e) {
            $this->assertEquals('ALREADY_IN_ROOM', $e->getErrorCode());
            $this->assertEquals($room1->id, $e->roomId);
        }

        // Try joining another room
        $otherHost = User::factory()->create();
        $room2 = $this->service->create($otherHost, 2, 0);

        try {
            $this->service->join($user, $room2->room_code);
            $this->fail('Expected ALREADY_IN_ROOM exception');
        } catch (PrivateRoomException $e) {
            $this->assertEquals('ALREADY_IN_ROOM', $e->getErrorCode());
            $this->assertEquals($room1->id, $e->roomId);
        }
    }

    public function test_start_by_non_host_throws_not_host_exception(): void
    {
        $host = User::factory()->create();
        $room = $this->service->create($host, 2, 0);

        $guest = User::factory()->create();
        $this->service->join($guest, $room->room_code);
        $this->service->toggleReady($guest, $room, true);

        $this->expectException(PrivateRoomException::class);
        try {
            $this->service->start($guest, $room);
        } catch (PrivateRoomException $e) {
            $this->assertEquals('NOT_HOST', $e->getErrorCode());
            throw $e;
        }
    }

    public function test_start_with_low_balance_rolls_back_all_deductions(): void
    {
        $entryFee = 1000;

        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 2000]);

        $room = $this->service->create($host, 2, $entryFee);

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 100]);

        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $guest->id,
            'seat_position' => 2,
            'color' => PlayerColor::YELLOW->value,
            'is_ready' => true,
        ]);

        $initialHostBalance = $this->walletService->getBalance($host, 'coins');
        $initialGuestBalance = $this->walletService->getBalance($guest, 'coins');

        try {
            $this->service->start($host, $room);
            $this->fail('Expected InsufficientBalanceException or PrivateRoomException');
        } catch (\App\Exceptions\InsufficientBalanceException | \App\Exceptions\PrivateRoomException $e) {
            // Success: caught
        }

        // Host coins MUST NOT be debited because the transaction rolled back!
        $this->assertEquals($initialHostBalance, $this->walletService->getBalance($host, 'coins'));
        $this->assertEquals($initialGuestBalance, $this->walletService->getBalance($guest, 'coins'));

        // No transaction records written
        $this->assertDatabaseMissing('transactions', ['reference_id' => "private_room_start:{$room->id}:{$host->id}"]);

        // Room status must remain WAITING
        $this->assertEquals(RoomStatus::WAITING, $room->fresh()->status);
    }

    public function test_start_succeeds_when_all_ready_and_balances_sufficient(): void
    {
        $entryFee = 500;

        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 2000]);

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 2000]);

        $room = $this->service->create($host, 2, $entryFee, 30);
        $this->service->join($guest, $room->room_code);
        $this->service->toggleReady($guest, $room, true);

        $startResult = $this->service->start($host, $room);

        $this->assertEquals(RoomStatus::PLAYING, $startResult['room']->status);
        $this->assertNotNull($startResult['game']);
        $this->assertEquals($room->id, $startResult['game']->room_id);
        $this->assertEquals(30, $startResult['game_state']['turn_seconds']);

        // Both players debited
        $this->assertEquals(1500, $this->walletService->getBalance($host, 'coins'));
        $this->assertEquals(1500, $this->walletService->getBalance($guest, 'coins'));

        // Deterministic transaction reference ids
        $this->assertDatabaseHas('transactions', [
            'user_id' => $host->id,
            'reference_id' => "private_room_start:{$room->id}:{$host->id}",
            'amount' => -500,
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $guest->id,
            'reference_id' => "private_room_start:{$room->id}:{$guest->id}",
            'amount' => -500,
        ]);

        // Second start call returns ROOM_ALREADY_STARTED and charges nothing
        try {
            $this->service->start($host, $room);
            $this->fail('Expected ROOM_ALREADY_STARTED');
        } catch (PrivateRoomException $e) {
            $this->assertEquals('ROOM_ALREADY_STARTED', $e->getErrorCode());
        }

        // Host balance remains 1500 (no double charge)
        $this->assertEquals(1500, $this->walletService->getBalance($host, 'coins'));
    }

    public function test_snapshot_returns_unified_shape_and_can_start(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $room = $this->service->create($host, 2, 0, 15);

        // Before guest joins: can_start is false
        $snap1 = $this->service->snapshot($room, $host);
        $this->assertFalse($snap1['can_start']);
        $this->assertTrue($snap1['is_host']);
        $this->assertEquals(1, $snap1['my_seat']);
        $this->assertEquals(1, count($snap1['players']));
        $this->assertNull($snap1['game_id']);

        // Guest joins but is not ready: can_start is false
        $this->service->join($guest, $room->room_code);
        $snap2 = $this->service->snapshot($room, $host);
        $this->assertFalse($snap2['can_start']);
        $this->assertEquals(2, count($snap2['players']));

        // Guest toggles ready: can_start becomes true!
        $this->service->toggleReady($guest, $room, true);
        $snap3 = $this->service->snapshot($room, $host);
        $this->assertTrue($snap3['can_start']);
    }
}
