<?php

namespace Tests\Feature;

use App\Events\BattleStartedEvent;
use App\Events\DiceRolledEvent;
use App\Events\GameWonEvent;
use App\Events\GiftSentEvent;
use App\Listeners\UpdateDailyTaskProgressListener;
use App\Models\User;
use App\Models\UserDailyTask;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_daily_tasks_seeds_default_tasks(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/events/daily-tasks');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $tasks = $response->json('data.tasks');
        $this->assertCount(4, $tasks);
        $this->assertDatabaseCount('user_daily_tasks', 4);
    }

    public function test_event_listener_increments_task_progress(): void
    {
        $user = User::factory()->create();

        // Seed tasks
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/events/daily-tasks');

        $listener = new UpdateDailyTaskProgressListener();

        // Fire GameWonEvent
        $listener->handle(new GameWonEvent($user->id));
        $listener->handle(new GameWonEvent($user->id));

        $winTask = UserDailyTask::where('user_id', $user->id)->where('task_key', 'win_matches')->first();
        $this->assertEquals(2, $winTask->current_progress);
        $this->assertTrue($winTask->is_completed);

        // Fire DiceRolledEvent
        $listener->handle(new DiceRolledEvent($user->id, 6));
        $listener->handle(new DiceRolledEvent($user->id, 6));
        $listener->handle(new DiceRolledEvent($user->id, 6));

        $sixTask = UserDailyTask::where('user_id', $user->id)->where('task_key', 'roll_sixes')->first();
        $this->assertEquals(3, $sixTask->current_progress);
        $this->assertTrue($sixTask->is_completed);
    }

    public function test_claim_daily_task_credits_wallet_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
        $initialCoins = $wallet->coins_balance;

        // Seed tasks
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/events/daily-tasks');

        // Force complete win_matches task
        $task = UserDailyTask::where('user_id', $user->id)->where('task_key', 'win_matches')->first();
        $task->current_progress = 2;
        $task->save();

        // 1. Claim task
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/events/daily-tasks/{$task->id}/claim", [
                'request_id' => 'req_test_123',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.coins', $initialCoins + 500);

        $task->refresh();
        $this->assertTrue($task->is_claimed);

        // 2. Claim again with same request_id (idempotency check)
        $responseRepeat = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/events/daily-tasks/{$task->id}/claim", [
                'request_id' => 'req_test_123',
            ]);

        $responseRepeat->assertStatus(200)
            ->assertJsonPath('data.status', 'already_claimed');
    }

    public function test_arrival_chest_claim_and_cooldown(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
        $initialCoins = $wallet->coins_balance;
        $initialDiamonds = $wallet->diamonds_balance;

        // 1. Claim initial chest
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/events/arrival-chest/claim', [
                'request_id' => 'chest_req_1',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reward_coins', 1000)
            ->assertJsonPath('data.reward_diamonds', 5)
            ->assertJsonPath('data.coins', $initialCoins + 1000)
            ->assertJsonPath('data.diamonds', $initialDiamonds + 5);

        // 2. Try to claim again immediately -> 400 error
        $responseImmediate = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/events/arrival-chest/claim', [
                'request_id' => 'chest_req_2',
            ]);

        $responseImmediate->assertStatus(400)
            ->assertJsonPath('message', 'Arrival chest is not ready to be claimed yet.');
    }
}
