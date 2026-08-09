<?php

namespace Tests\Feature;

use App\Events\MatchFound;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EntryFeeDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Cache::flush();
    }

    public function test_match_creation_deducts_entry_fee_and_logs_transaction(): void
    {
        Event::fake([MatchFound::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user1->load('wallet');
        $user2->load('wallet');

        $initialBalance1 = $user1->wallet->coins_balance;
        $initialBalance2 = $user2->wallet->coins_balance;
        $entryFee = 100;

        // User 1 joins queue with entry fee
        $res1 = $this->actingAs($user1)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => $entryFee,
        ]);
        $res1->assertJsonPath('data.status', 'waiting');

        // User 2 joins queue with entry fee -> match created
        $res2 = $this->actingAs($user2)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => $entryFee,
        ]);
        $res2->assertJsonPath('data.status', 'matched');

        $roomId = $res2->json('data.room_id');

        // Confirm both wallets were decremented by entry fee
        $this->assertEquals($initialBalance1 - $entryFee, $user1->wallet->fresh()->coins_balance);
        $this->assertEquals($initialBalance2 - $entryFee, $user2->wallet->fresh()->coins_balance);

        // Confirm two transaction records exist
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user1->id,
            'type' => 'entry_fee',
            'currency_type' => 'coins',
            'amount' => -$entryFee,
            'reference_id' => (string) $roomId,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user2->id,
            'type' => 'entry_fee',
            'currency_type' => 'coins',
            'amount' => -$entryFee,
            'reference_id' => (string) $roomId,
        ]);
    }

    public function test_insufficient_balance_does_not_create_negative_balance(): void
    {
        Event::fake([MatchFound::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user1->load('wallet');
        $user2->load('wallet');

        // Set user2 balance to 0 after user1 joins, simulating a race condition
        $this->actingAs($user1)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 100,
        ]);

        // Reduce user1 balance to 0 before match finalization
        $user1->wallet->update(['coins_balance' => 0]);

        $res2 = $this->actingAs($user2)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 100,
        ]);

        // Balance should remain 0 and never go negative
        $this->assertEquals(0, $user1->wallet->fresh()->coins_balance);
        $this->assertGreaterThanOrEqual(0, $user1->wallet->fresh()->coins_balance);
        $this->assertGreaterThanOrEqual(0, $user2->wallet->fresh()->coins_balance);
    }
}
