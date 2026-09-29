<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\GameEnded;
use App\Events\PrivateRoomUpdated;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PrivateRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VipRoomRealtimeAndPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private User $guest;
    private PrivateRoomService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->create();
        $this->host->wallet->update(['coins_balance' => 50000]);
        \App\Models\Subscription::create([
            'user_id' => $this->host->id,
            'tier' => \App\Enums\SubscriptionTier::KNIGHT,
            'status' => \App\Enums\SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
        ]);
        $this->guest = User::factory()->create();
        $this->guest->wallet->update(['coins_balance' => 50000]);

        $this->service = app(PrivateRoomService::class);
    }

    public function test_private_room_updated_event_dispatches_for_vip_room_lifecycle(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([PrivateRoomUpdated::class]);

        // 1. Create VIP room
        $room = $this->service->create($this->host, 2, 5000, 15, 'Royal VIP', RoomType::VIP);
        Event::assertNotDispatched(PrivateRoomUpdated::class);

        // 2. Guest joins -> reason 'joined', version 2
        $this->service->join($this->guest, $room->room_code, RoomType::VIP);
        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room) {
            return $e->roomId === $room->id
                && $e->reason === 'joined'
                && $e->actorUserId === $this->guest->id
                && $e->version === 2
                && !array_key_exists('my_seat', $e->snapshot)
                && !array_key_exists('is_host', $e->snapshot);
        });

        // 3. Guest ready -> reason 'ready', version 3
        $this->service->toggleReady($this->guest, $room->id, true, RoomType::VIP);
        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room) {
            return $e->roomId === $room->id
                && $e->reason === 'ready'
                && $e->actorUserId === $this->guest->id
                && $e->version === 3;
        });

        // 4. Start match -> reason 'started', version 4
        $result = $this->service->start($this->host, $room->id, RoomType::VIP);
        Event::assertDispatched(PrivateRoomUpdated::class, function ($e) use ($room, $result) {
            return $e->roomId === $room->id
                && $e->reason === 'started'
                && $e->actorUserId === $this->host->id
                && $e->version === 4
                && $e->snapshot['status'] === RoomStatus::PLAYING->value
                && $e->snapshot['game_id'] === $result['game']->id;
        });
    }

    public function test_vip_game_win_credits_winner_correct_pot_once(): void
    {
        Event::fake([GameEnded::class]);

        // Create VIP room with 25000 entry fee and 2 players -> Total pot = 50000
        $room = Room::create([
            'room_code' => 'VIPWIN',
            'title' => 'VIP High Stakes',
            'type' => RoomType::VIP->value,
            'max_players' => 2,
            'entry_fee' => 25000,
            'turn_seconds' => 15,
            'status' => RoomStatus::PLAYING->value,
            'created_by' => $this->host->id,
        ]);

        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $this->host->id,
            'seat_position' => 1,
            'color' => 'red',
            'is_ready' => true,
        ]);

        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $this->guest->id,
            'seat_position' => 2,
            'color' => 'yellow',
            'is_ready' => true,
        ]);

        $game = Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        // Initialize Redis state for game
        $redisStore = app(\App\Services\GameEngine\RedisGameStateStore::class);
        $state = $redisStore->initializeState($room->id, $game->id, [
            ['seat_position' => 0, 'user_id' => $this->host->id, 'username' => 'Host', 'color' => 'red'],
            ['seat_position' => 1, 'user_id' => $this->guest->id, 'username' => 'Guest', 'color' => 'yellow'],
        ]);

        // Host makes winning move with 1 remaining step to home
        $state['dice_value'] = 1;
        $state['must_move'] = true;
        $state['can_roll'] = false;
        $state['current_turn_seat'] = 0;
        $state['current_turn_user_id'] = $this->host->id;
        $state['token_positions']['red'] = [55, 56, 56, 56]; // 3 at home (56), 1 at 55 -> reaches home on 1
        $redisStore->saveState($room->id, $state);

        $initialBalance = $this->host->wallet->coins_balance; // 50000

        $response = $this->actingAs($this->host)->postJson('/api/v1/game/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        // Winner receives full 50,000 pot (25000 * 2)
        $this->assertEquals($initialBalance + 50000, $this->host->wallet->fresh()->coins_balance);

        // Transaction log created with type 'win'
        $transaction = Transaction::where('user_id', $this->host->id)
            ->where('reference_id', (string) $room->id)
            ->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(TransactionType::WIN->value, $transaction->type instanceof \BackedEnum ? $transaction->type->value : $transaction->type);
        $this->assertEquals(50000, $transaction->amount);

        // Room status updated to finished
        $this->assertEquals(RoomStatus::FINISHED->value, $room->fresh()->status->value);
    }
}
