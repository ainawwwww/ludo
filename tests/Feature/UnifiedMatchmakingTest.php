<?php

namespace Tests\Feature;

use App\Events\MatchFound;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Support\TeamAssignment;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnifiedMatchmakingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([\App\Jobs\ProcessTurnTimeout::class]);
        Cache::flush();
    }

    public function test_solo_vs_solo_four_players_form_2v2_match(): void
    {
        Event::fake([MatchFound::class]);

        $users = User::factory()->count(4)->create();
        foreach ($users as $u) {
            $u->wallet->update(['coins_balance' => 1000]);
        }

        // First 3 solos join -> all waiting
        for ($i = 0; $i < 3; $i++) {
            $res = $this->actingAs($users[$i])->postJson('/api/v1/team-rooms/join-solo', ['entry_fee' => 500]);
            $res->assertStatus(200)->assertJsonPath('data.status', 'waiting');
        }

        // 4th solo joins -> status matched
        $res4 = $this->actingAs($users[3])->postJson('/api/v1/team-rooms/join-solo', ['entry_fee' => 500]);
        $res4->assertStatus(200)->assertJsonPath('data.status', 'matched');

        $roomId = $res4->json('data.room_id');
        $this->assertNotNull($roomId);

        // Verify wallets deducted 500 coins each
        foreach ($users as $u) {
            $this->assertEquals(500, $u->wallet->fresh()->coins_balance);
        }

        // Verify Team seat assignments:
        // Seat 1 = Red (Team 1), Seat 2 = Green (Team 2), Seat 3 = Yellow (Team 1), Seat 4 = Blue (Team 2)
        $players = RoomPlayer::where('room_id', $roomId)->orderBy('seat_position')->get();
        $this->assertCount(4, $players);

        $this->assertEquals('red', $players[0]->color instanceof \App\Enums\PlayerColor ? $players[0]->color->value : $players[0]->color);
        $this->assertEquals('green', $players[1]->color instanceof \App\Enums\PlayerColor ? $players[1]->color->value : $players[1]->color);
        $this->assertEquals('yellow', $players[2]->color instanceof \App\Enums\PlayerColor ? $players[2]->color->value : $players[2]->color);
        $this->assertEquals('blue', $players[3]->color instanceof \App\Enums\PlayerColor ? $players[3]->color->value : $players[3]->color);

        // Verify seat pairings match TeamAssignment
        $this->assertTrue(TeamAssignment::areTeammates(1, 3));
        $this->assertTrue(TeamAssignment::areTeammates(2, 4));
        $this->assertFalse(TeamAssignment::areTeammates(1, 2));

        Event::assertDispatched(MatchFound::class, 4);
    }

    public function test_pair_vs_pair_matchmaking(): void
    {
        Event::fake([MatchFound::class]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();
        $u4 = User::factory()->create();

        foreach ([$u1, $u2, $u3, $u4] as $u) {
            $u->wallet->update(['coins_balance' => 1000]);
        }

        // Pair 1 (u1 + u2) creates and joins team lobby
        $l1 = $this->actingAs($u1)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 500])->json('data.room');
        $this->actingAs($u2)->postJson('/api/v1/team-rooms/join', ['code' => $l1['room_code']]);
        $this->actingAs($u2)->postJson('/api/v1/team-rooms/ready', ['is_ready' => true]);

        // Pair 1 host queues for match -> waiting
        $q1 = $this->actingAs($u1)->postJson('/api/v1/team-rooms/ready-for-match');
        $q1->assertStatus(200)->assertJsonPath('data.status', 'waiting');

        // Balances NOT deducted during lobby/ready phase
        $this->assertEquals(1000, $u1->wallet->fresh()->coins_balance);
        $this->assertEquals(1000, $u2->wallet->fresh()->coins_balance);

        // Pair 2 (u3 + u4) creates and joins team lobby
        $l2 = $this->actingAs($u3)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 500])->json('data.room');
        $this->actingAs($u4)->postJson('/api/v1/team-rooms/join', ['code' => $l2['room_code']]);
        $this->actingAs($u4)->postJson('/api/v1/team-rooms/ready', ['is_ready' => true]);

        // Pair 2 host queues for match -> status matched with Pair 1!
        $q2 = $this->actingAs($u3)->postJson('/api/v1/team-rooms/ready-for-match');
        $q2->assertStatus(200)->assertJsonPath('data.status', 'matched');

        // Now entry fee IS deducted
        foreach ([$u1, $u2, $u3, $u4] as $u) {
            $this->assertEquals(500, $u->wallet->fresh()->coins_balance);
        }
    }

    public function test_pair_vs_solo_matchmaking(): void
    {
        Event::fake([MatchFound::class]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $s1 = User::factory()->create();
        $s2 = User::factory()->create();

        foreach ([$u1, $u2, $s1, $s2] as $u) {
            $u->wallet->update(['coins_balance' => 1000]);
        }

        // Pair 1 (u1 + u2) queues
        $l1 = $this->actingAs($u1)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 500])->json('data.room');
        $this->actingAs($u2)->postJson('/api/v1/team-rooms/join', ['code' => $l1['room_code']]);
        $this->actingAs($u2)->postJson('/api/v1/team-rooms/ready', ['is_ready' => true]);
        $this->actingAs($u1)->postJson('/api/v1/team-rooms/ready-for-match');

        // Solo 1 joins -> waiting
        $this->actingAs($s1)->postJson('/api/v1/team-rooms/join-solo', ['entry_fee' => 500]);

        // Solo 2 joins -> triggers match between Pair 1 and (Solo 1 + Solo 2)
        $mRes = $this->actingAs($s2)->postJson('/api/v1/team-rooms/join-solo', ['entry_fee' => 500]);
        $mRes->assertStatus(200)->assertJsonPath('data.status', 'matched');

        $matchedUserIds = array_column($mRes->json('data.players'), 'user_id');
        $this->assertContains($u1->id, $matchedUserIds);
        $this->assertContains($u2->id, $matchedUserIds);
        $this->assertContains($s1->id, $matchedUserIds);
        $this->assertContains($s2->id, $matchedUserIds);
    }

    public function test_fifo_ordering_ready_pair_not_starved_by_newer_solos(): void
    {
        Event::fake([MatchFound::class]);

        $p1 = User::factory()->create();
        $p2 = User::factory()->create();
        $s1 = User::factory()->create();
        $s2 = User::factory()->create();

        // Pair 1 arrives FIRST in FIFO queue
        $l1 = $this->actingAs($p1)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 0])->json('data.room');
        $this->actingAs($p2)->postJson('/api/v1/team-rooms/join', ['code' => $l1['room_code']]);
        $this->actingAs($p2)->postJson('/api/v1/team-rooms/ready', ['is_ready' => true]);
        $this->actingAs($p1)->postJson('/api/v1/team-rooms/ready-for-match');

        // Solo 1 arrives SECOND in FIFO queue
        $this->actingAs($s1)->postJson('/api/v1/team-rooms/join-solo', ['entry_fee' => 0]);

        // Solo 2 arrives THIRD in FIFO queue -> matches with Pair 1 because Pair 1 was waiting first!
        $res = $this->actingAs($s2)->postJson('/api/v1/team-rooms/join-solo', ['entry_fee' => 0]);
        $res->assertStatus(200)->assertJsonPath('data.status', 'matched');

        $matchedUserIds = array_column($res->json('data.players'), 'user_id');
        $this->assertContains($p1->id, $matchedUserIds);
        $this->assertContains($p2->id, $matchedUserIds);
        $this->assertContains($s1->id, $matchedUserIds);
        $this->assertContains($s2->id, $matchedUserIds);
    }
}
