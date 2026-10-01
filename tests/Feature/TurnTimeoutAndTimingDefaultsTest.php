<?php

namespace Tests\Feature;

use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Room;
use App\Models\User;
use App\Services\GameEngine\DiceService;
use App\Services\GameEngine\MoveValidator;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\GameEngine\TurnManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TurnTimeoutAndTimingDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_turn_timeout_redispatches_on_lock_timeout_up_to_max_retries(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);

        $mockLock = $this->createMock(Lock::class);
        $mockLock->expects($this->once())
            ->method('block')
            ->willThrowException(new LockTimeoutException());

        $mockStore = $this->mock(\Illuminate\Contracts\Cache\Repository::class);
        $mockStore->shouldReceive('lock')
            ->with('ludo:lock:game:999', 5)
            ->andReturn($mockLock);
        Cache::swap($mockStore);

        $job = new ProcessTurnTimeout(999, 0, '2026-09-28T10:00:00Z', 0);
        $job->handle(
            app(RedisGameStateStore::class),
            app(DiceService::class),
            app(MoveValidator::class),
            app(TurnManager::class)
        );

        Queue::assertPushed(ProcessTurnTimeout::class, function ($pushedJob) {
            return $pushedJob->roomId === 999
                && $pushedJob->turnSeat === 0
                && $pushedJob->retryCount === 1
                && ($pushedJob->delay instanceof \Carbon\CarbonInterface || is_numeric($pushedJob->delay));
        });
    }

    public function test_turn_timeout_does_not_redispatch_exceeding_max_retries(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);

        $mockLock = $this->createMock(Lock::class);
        $mockLock->expects($this->once())
            ->method('block')
            ->willThrowException(new LockTimeoutException());

        $mockStore = $this->mock(\Illuminate\Contracts\Cache\Repository::class);
        $mockStore->shouldReceive('lock')
            ->with('ludo:lock:game:999', 5)
            ->andReturn($mockLock);
        Cache::swap($mockStore);

        $job = new ProcessTurnTimeout(999, 0, '2026-09-28T10:00:00Z', ProcessTurnTimeout::MAX_LOCK_RETRIES);
        $job->handle(
            app(RedisGameStateStore::class),
            app(DiceService::class),
            app(MoveValidator::class),
            app(TurnManager::class)
        );

        Queue::assertNotPushed(ProcessTurnTimeout::class);
    }

    public function test_legacy_defaults_are_used_when_turn_seconds_is_null(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([TurnChanged::class]);

        $stateStore = new RedisGameStateStore();
        $roomId = 777;

        $players = [
            ['seat_position' => 0, 'user_id' => 1, 'username' => 'P1', 'color' => 'red'],
            ['seat_position' => 1, 'user_id' => 2, 'username' => 'P2', 'color' => 'green'],
        ];

        $state = $stateStore->initializeState($roomId, 10, $players);
        $state['turn_seconds'] = null; // explicit null for legacy room
        $stateStore->saveState($roomId, $state);

        $job = new ProcessTurnTimeout($roomId, 0, $state['last_action_at']);
        $job->handle(
            $stateStore,
            app(DiceService::class),
            app(MoveValidator::class),
            app(TurnManager::class)
        );

        // When can_roll is true and turn_seconds is null, default delay is 15s
        Queue::assertPushed(ProcessTurnTimeout::class, function ($pushedJob) {
            $expectedDelay = now()->addSeconds(15)->timestamp;
            $actualDelay = $pushedJob->delay instanceof \Carbon\CarbonInterface ? $pushedJob->delay->timestamp : (int) $pushedJob->delay;
            return $pushedJob->roomId === 777 && abs($actualDelay - $expectedDelay) <= 1;
        });
    }

    public function test_custom_turn_seconds_uses_turn_seconds_plus_two(): void
    {
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([TurnChanged::class]);

        $stateStore = new RedisGameStateStore();
        $roomId = 888;

        $players = [
            ['seat_position' => 0, 'user_id' => 1, 'username' => 'P1', 'color' => 'red'],
            ['seat_position' => 1, 'user_id' => 2, 'username' => 'P2', 'color' => 'green'],
        ];

        $state = $stateStore->initializeState($roomId, 10, $players);
        $state['turn_seconds'] = 30; // custom private room setting
        $stateStore->saveState($roomId, $state);

        $job = new ProcessTurnTimeout($roomId, 0, $state['last_action_at']);
        $job->handle(
            $stateStore,
            app(DiceService::class),
            app(MoveValidator::class),
            app(TurnManager::class)
        );

        // When turn_seconds is 30, delay is 30 + 2 = 32s
        Queue::assertPushed(ProcessTurnTimeout::class, function ($pushedJob) {
            $expectedDelay = now()->addSeconds(32)->timestamp;
            $actualDelay = $pushedJob->delay instanceof \Carbon\CarbonInterface ? $pushedJob->delay->timestamp : (int) $pushedJob->delay;
            return $pushedJob->roomId === 888 && abs($actualDelay - $expectedDelay) <= 1;
        });
    }
}
