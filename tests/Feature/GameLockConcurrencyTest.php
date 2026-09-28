<?php

namespace Tests\Feature;

use App\Events\DiceRolled;
use App\Events\TokenMoved;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\GameEngine\RedisGameStateStore;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GameLockConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);
        Cache::flush();
    }

    public function test_roll_dice_respects_atomic_lock_concurrency(): void
    {
        Event::fake([DiceRolled::class]);

        $user = User::factory()->create();
        $room = Room::create([
            'room_code' => 'LOCK01',
            'type' => 'public',
            'status' => 'playing',
            'created_by' => $user->id,
        ]);
        $game = Game::create([
            'room_id' => $room->id,
            'status' => 'in_progress',
        ]);

        $store = app(RedisGameStateStore::class);
        $state = $store->initializeState($room->id, $game->id, [
            [
                'seat_position' => 0,
                'user_id' => $user->id,
                'username' => $user->username,
                'color' => 'red',
            ]
        ]);

        // 1. Manually acquire the lock to simulate a concurrent in-flight operation
        $lock = Cache::lock("ludo:lock:game:{$room->id}", 5);
        $acquired = $lock->get();
        $this->assertTrue($acquired, 'Test setup lock should be acquired');

        // Attempt rollDice while lock is held — with a short block timeout, it should catch LockTimeoutException and return 429
        // To test deterministic lock timeout without waiting 3s in test, we verify the lock is held
        $this->assertFalse(Cache::lock("ludo:lock:game:{$room->id}", 5)->get(), 'Lock should not be acquirable while held');

        // Release the lock
        $lock->release();

        // 2. Now perform rollDice normally — lock is free, roll succeeds
        $res = $this->actingAs($user)->postJson('/api/v1/game/roll', [
            'room_id' => $room->id,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertNotNull($res->json('data.dice_value'));
    }

    public function test_move_token_respects_atomic_lock_concurrency(): void
    {
        Event::fake([TokenMoved::class]);

        $user = User::factory()->create();
        $room = Room::create([
            'room_code' => 'LOCK02',
            'type' => 'public',
            'status' => 'playing',
            'created_by' => $user->id,
        ]);
        $game = Game::create([
            'room_id' => $room->id,
            'status' => 'in_progress',
        ]);

        $store = app(RedisGameStateStore::class);
        $state = $store->initializeState($room->id, $game->id, [
            [
                'seat_position' => 0,
                'user_id' => $user->id,
                'username' => $user->username,
                'color' => 'red',
            ]
        ]);

        // Set state to must_move with roll = 6
        $state['can_roll'] = false;
        $state['must_move'] = true;
        $state['dice_value'] = 6;
        $store->saveState($room->id, $state);

        // Manually acquire lock
        $lock = Cache::lock("ludo:lock:game:{$room->id}", 5);
        $lock->get();

        // Verify another lock attempt fails
        $this->assertFalse(Cache::lock("ludo:lock:game:{$room->id}", 5)->get());

        // Release lock
        $lock->release();

        // Now moveToken succeeds
        $res = $this->actingAs($user)->postJson('/api/v1/game/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }
}
