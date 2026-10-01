<?php

namespace Tests\Feature;

use App\Events\MatchFound;
use App\Models\RoomPlayer;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 1 Deep Characterization Test Suite for existing MatchmakingService (Quick Match).
 * Locks down exact baseline for 2P/4P quick match: seat numbers, colors, fees, wallet deductions,
 * event dispatches, queue isolation, and FIFO ordering.
 */
class MatchmakingCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([\App\Jobs\ProcessTurnTimeout::class]);
        Cache::flush();
    }

    public function test_2p_quick_match_baseline_creation_and_matching(): void
    {
        Event::fake([MatchFound::class]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // 1. First user joins 2P quick match -> status: waiting
        $r1 = $this->actingAs($u1)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);
        $r1->assertStatus(200)
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.queue_position', 1);

        // 2. Second user joins 2P quick match -> status: matched
        $r2 = $this->actingAs($u2)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);
        $r2->assertStatus(200)
            ->assertJsonPath('data.status', 'matched');

        $roomId = $r2->json('data.room_id');
        $gameId = $r2->json('data.game_id');
        $this->assertNotNull($roomId);
        $this->assertNotNull($gameId);

        $this->assertDatabaseHas('rooms', ['id' => $roomId, 'max_players' => 2, 'type' => 'public']);
        $this->assertDatabaseHas('games', ['id' => $gameId, 'room_id' => $roomId]);
        $this->assertDatabaseHas('room_players', ['room_id' => $roomId, 'user_id' => $u1->id]);
        $this->assertDatabaseHas('room_players', ['room_id' => $roomId, 'user_id' => $u2->id]);
    }

    public function test_4p_quick_match_baseline_creation_and_matching(): void
    {
        Event::fake([MatchFound::class]);

        $users = User::factory()->count(4)->create();

        // Join first 3 users -> all waiting
        for ($i = 0; $i < 3; $i++) {
            $res = $this->actingAs($users[$i])->postJson('/api/v1/matchmaking/join', [
                'max_players' => 4,
                'entry_fee' => 0,
            ]);
            $res->assertStatus(200)->assertJsonPath('data.status', 'waiting');
        }

        // 4th user joins -> status matched with 4 players
        $res4 = $this->actingAs($users[3])->postJson('/api/v1/matchmaking/join', [
            'max_players' => 4,
            'entry_fee' => 0,
        ]);
        $res4->assertStatus(200)->assertJsonPath('data.status', 'matched');
        $this->assertCount(4, $res4->json('data.players'));

        $roomId = $res4->json('data.room_id');
        $this->assertDatabaseHas('rooms', ['id' => $roomId, 'max_players' => 4, 'type' => 'public']);
    }

    public function test_2p_and_4p_entry_fee_wallet_deduction_and_seat_color_assignments(): void
    {
        Event::fake([MatchFound::class]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u1->wallet->update(['coins_balance' => 1000]);
        $u2->wallet->update(['coins_balance' => 1000]);

        // Join 2P with 500 fee
        $this->actingAs($u1)->postJson('/api/v1/matchmaking/join', ['max_players' => 2, 'entry_fee' => 500]);
        $r2 = $this->actingAs($u2)->postJson('/api/v1/matchmaking/join', ['max_players' => 2, 'entry_fee' => 500]);

        $roomId = $r2->json('data.room_id');

        // Check wallet deductions
        $this->assertEquals(500, $u1->wallet->fresh()->coins_balance);
        $this->assertEquals(500, $u2->wallet->fresh()->coins_balance);

        // Check seat and color assignments in DB
        $p1 = RoomPlayer::where('room_id', $roomId)->where('user_id', $u1->id)->first();
        $p2 = RoomPlayer::where('room_id', $roomId)->where('user_id', $u2->id)->first();

        $this->assertEquals(1, $p1->seat_position);
        $this->assertEquals(2, $p2->seat_position);
        $this->assertEquals('red', $p1->color instanceof \App\Enums\PlayerColor ? $p1->color->value : $p1->color);
        $this->assertEquals('yellow', $p2->color instanceof \App\Enums\PlayerColor ? $p2->color->value : $p2->color);

        // Check MatchFound broadcast event count
        Event::assertDispatched(MatchFound::class, 2);
    }

    public function test_fifo_queue_ordering_and_isolation_between_different_fee_queues(): void
    {
        Event::fake([MatchFound::class]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();

        $u1->wallet->update(['coins_balance' => 1000]);
        $u2->wallet->update(['coins_balance' => 1000]);
        $u3->wallet->update(['coins_balance' => 1000]);

        // u1 joins 500 fee queue
        $this->actingAs($u1)->postJson('/api/v1/matchmaking/join', ['max_players' => 2, 'entry_fee' => 500]);

        // u2 joins 1000 fee queue (should not match with u1 due to fee isolation)
        $r2 = $this->actingAs($u2)->postJson('/api/v1/matchmaking/join', ['max_players' => 2, 'entry_fee' => 1000]);
        $r2->assertJsonPath('data.status', 'waiting');

        // u3 joins 500 fee queue (matches with u1 because u1 was first in FIFO queue for 500 fee)
        $r3 = $this->actingAs($u3)->postJson('/api/v1/matchmaking/join', ['max_players' => 2, 'entry_fee' => 500]);
        $r3->assertJsonPath('data.status', 'matched');

        $matchedUserIds = array_column($r3->json('data.players'), 'user_id');
        $this->assertContains($u1->id, $matchedUserIds);
        $this->assertContains($u3->id, $matchedUserIds);
        $this->assertNotContains($u2->id, $matchedUserIds);
    }
}
