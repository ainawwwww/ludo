<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VipSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([\App\Jobs\ProcessTurnTimeout::class]);
        Event::fake([\App\Events\TurnChanged::class, \App\Events\GameEnded::class]);
    }

    public function test_checkout_creates_active_knight_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'tier' => 'knight',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.tier', 'knight');
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.auto_renew', true);
        $response->assertJsonPath('data.can_claim_daily_reward', true);

        $subscription = Subscription::where('user_id', $user->id)->first();
        $this->assertNotNull($subscription);
        $this->assertEquals(SubscriptionTier::KNIGHT, $subscription->tier);
        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->auto_renew);
        $this->assertNull($subscription->last_daily_reward_at);

        $transaction = PaymentTransaction::where('user_id', $user->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('4.99', (string) $transaction->amount);
        $this->assertEquals('success', $transaction->status);
    }

    public function test_checkout_creates_active_baron_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'tier' => 'baron',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.tier', 'baron');
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.auto_renew', true);

        $subscription = Subscription::where('user_id', $user->id)->first();
        $this->assertNotNull($subscription);
        $this->assertEquals(SubscriptionTier::BARON, $subscription->tier);
        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);

        $transaction = PaymentTransaction::where('user_id', $user->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('14.99', (string) $transaction->amount);
        $this->assertEquals('success', $transaction->status);
    }

    public function test_checkout_fails_if_already_subscribed(): void
    {
        $user = User::factory()->create();

        Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'tier' => 'baron',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'ALREADY_SUBSCRIBED');
    }

    public function test_checkout_fails_on_forced_dummy_gateway_failure(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withHeaders([
            'X-Dummy-Payment-Force-Failure' => '1',
        ])->postJson('/api/v1/subscription/checkout', [
            'tier' => 'knight',
        ]);

        $response->assertStatus(402);
        $response->assertJsonPath('error_code', 'PAYMENT_FAILED');

        $this->assertDatabaseMissing('subscriptions', ['user_id' => $user->id]);

        $transaction = PaymentTransaction::where('user_id', $user->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('failed', $transaction->status);
    }

    public function test_checkout_concurrency_lock_prevents_double_checkout(): void
    {
        $user = User::factory()->create();

        // Lock the key manually to simulate a concurrent request in progress
        $lockKey = "checkout_lock_{$user->id}";
        $lock = Cache::lock($lockKey, 10);
        $this->assertTrue($lock->get());

        try {
            $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
                'tier' => 'knight',
            ]);

            $response->assertStatus(409);
            $response->assertJsonPath('error_code', 'ALREADY_SUBSCRIBED');
        } finally {
            $lock->release();
        }
    }

    public function test_claim_daily_reward_credits_coins_and_diamonds(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000, 'diamonds_balance' => 50]);

        Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
            'last_daily_reward_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/claim-daily-reward');

        $response->assertStatus(200);
        $response->assertJsonPath('data.coins_claimed', 200);
        $response->assertJsonPath('data.diamonds_claimed', 5);

        $wallet = $user->wallet->fresh();
        $this->assertEquals(1200, $wallet->coins_balance);
        $this->assertEquals(55, $wallet->diamonds_balance);
    }

    public function test_claim_daily_reward_idempotency_prevents_double_claim_same_day(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000, 'diamonds_balance' => 50]);

        Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::BARON,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
            'last_daily_reward_at' => null,
        ]);

        $firstClaim = $this->actingAs($user)->postJson('/api/v1/subscription/claim-daily-reward');
        $firstClaim->assertStatus(200);

        $secondClaim = $this->actingAs($user)->postJson('/api/v1/subscription/claim-daily-reward');
        $secondClaim->assertStatus(409);
        $secondClaim->assertJsonPath('error_code', 'REWARD_ALREADY_CLAIMED');

        $wallet = $user->wallet->fresh();
        $this->assertEquals(1500, $wallet->coins_balance);
        $this->assertEquals(65, $wallet->diamonds_balance);
    }

    public function test_credit_daily_rewards_command_credits_all_unclaimed_active_subscriptions(): void
    {
        $user1 = User::factory()->create();
        $user1->wallet->update(['coins_balance' => 0, 'diamonds_balance' => 0]);

        $user2 = User::factory()->create();
        $user2->wallet->update(['coins_balance' => 0, 'diamonds_balance' => 0]);

        Subscription::create([
            'user_id' => $user1->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
            'last_daily_reward_at' => null,
        ]);

        Subscription::create([
            'user_id' => $user2->id,
            'tier' => SubscriptionTier::BARON,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
            'last_daily_reward_at' => null,
        ]);

        $this->artisan('vip:credit-daily-rewards')->assertExitCode(0);

        $this->assertEquals(200, $user1->wallet->fresh()->coins_balance);
        $this->assertEquals(5, $user1->wallet->fresh()->diamonds_balance);

        $this->assertEquals(500, $user2->wallet->fresh()->coins_balance);
        $this->assertEquals(15, $user2->wallet->fresh()->diamonds_balance);
    }

    public function test_cancel_subscription_sets_auto_renew_false(): void
    {
        $user = User::factory()->create();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/cancel');

        $response->assertStatus(200);
        $response->assertJsonPath('data.auto_renew', false);
        $response->assertJsonPath('data.status', 'cancelled');

        $this->assertFalse($sub->fresh()->auto_renew);
        $this->assertNotNull($sub->fresh()->cancelled_at);
        $this->assertEquals(SubscriptionStatus::CANCELLED, $sub->fresh()->status);
    }

    public function test_process_renewals_command_auto_renews_eligible_subscriptions(): void
    {
        $user = User::factory()->create();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now()->subDays(31),
            'current_period_end' => now()->subHour(),
            'auto_renew' => true,
        ]);

        $oldEnd = $sub->current_period_end;

        $this->artisan('vip:process-renewals')->assertExitCode(0);

        $sub->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE, $sub->status);
        $this->assertTrue($sub->current_period_end->gt($oldEnd));

        $tx = PaymentTransaction::where('user_id', $user->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('success', $tx->status);
    }

    public function test_process_renewals_command_expires_cancelled_subscriptions(): void
    {
        $user = User::factory()->create();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::BARON,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now()->subDays(31),
            'current_period_end' => now()->subHour(),
            'auto_renew' => false,
            'cancelled_at' => now()->subDays(5),
        ]);

        $this->artisan('vip:process-renewals')->assertExitCode(0);

        $sub->refresh();
        $this->assertEquals(SubscriptionStatus::EXPIRED, $sub->status);
    }

    public function test_vip_room_creation_blocked_without_active_vip_subscription(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 50000]);

        $response = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
            'turn_seconds' => 15,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'VIP_SUBSCRIPTION_REQUIRED');
    }

    public function test_vip_room_creation_allowed_with_active_vip_subscription(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 50000]);

        Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
            'turn_seconds' => 15,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.max_players', 2);
    }

    public function test_vip_room_join_allowed_without_vip_subscription(): void
    {
        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 50000]);

        Subscription::create([
            'user_id' => $host->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'auto_renew' => true,
        ]);

        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
            'turn_seconds' => 15,
        ]);
        $code = $createRes->json('data.code');

        $guestWithoutVip = User::factory()->create();
        $guestWithoutVip->wallet->update(['coins_balance' => 50000]);

        $joinRes = $this->actingAs($guestWithoutVip)->postJson('/api/v1/vip-rooms/join', [
            'room_code' => $code,
        ]);

        $joinRes->assertStatus(200);
        $joinRes->assertJsonPath('data.my_seat', 2);
    }
}
