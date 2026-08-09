<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use App\Events\MatchFound;
use Tests\TestCase;

class MatchmakingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);

        // Clear any matchmaking cache keys
        Cache::flush();
    }

    public function test_two_users_get_matched_in_same_queue(): void
    {
        Event::fake([MatchFound::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // User 1 joins — should be waiting
        $res1 = $this->actingAs($user1)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        $res1->assertStatus(200)
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.queue_position', 1);

        // User 2 joins — should trigger match
        $res2 = $this->actingAs($user2)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        $res2->assertStatus(200)
            ->assertJsonPath('data.status', 'matched');

        // Verify room and game were created
        $roomId = $res2->json('data.room_id');
        $gameId = $res2->json('data.game_id');
        $this->assertNotNull($roomId);
        $this->assertNotNull($gameId);

        // Verify players array has both users
        $players = $res2->json('data.players');
        $this->assertCount(2, $players);

        $playerIds = array_column($players, 'user_id');
        $this->assertContains($user1->id, $playerIds);
        $this->assertContains($user2->id, $playerIds);

        // Verify Room exists in DB
        $this->assertDatabaseHas('rooms', ['id' => $roomId]);

        // Verify Game exists in DB
        $this->assertDatabaseHas('games', ['id' => $gameId, 'room_id' => $roomId]);

        // Verify RoomPlayers were created
        $this->assertDatabaseHas('room_players', ['room_id' => $roomId, 'user_id' => $user1->id]);
        $this->assertDatabaseHas('room_players', ['room_id' => $roomId, 'user_id' => $user2->id]);

        // Verify MatchFound was broadcast to both users
        Event::assertDispatched(MatchFound::class, 2);
    }

    public function test_third_user_starts_new_queue(): void
    {
        Event::fake([MatchFound::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // Users 1 and 2 join and get matched
        $this->actingAs($user1)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        $this->actingAs($user2)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        // User 3 joins — should be waiting in a new queue
        $res3 = $this->actingAs($user3)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        $res3->assertStatus(200)
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.queue_position', 1);
    }

    public function test_user_can_leave_queue(): void
    {
        $user = User::factory()->create();

        // Join queue
        $this->actingAs($user)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        // Leave queue
        $response = $this->actingAs($user)->postJson('/api/v1/matchmaking/leave');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Left matchmaking queue');

        // Verify status is now idle
        $statusResponse = $this->actingAs($user)->getJson('/api/v1/matchmaking/status');
        $statusResponse->assertJsonPath('data.status', 'idle');
    }

    public function test_matchmaking_status_when_queued(): void
    {
        $user = User::factory()->create();

        // Join queue
        $this->actingAs($user)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 100,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/matchmaking/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.queue_position', 1)
            ->assertJsonPath('data.max_players', 2)
            ->assertJsonPath('data.entry_fee', 100);
    }

    public function test_matchmaking_status_when_idle(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/matchmaking/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'idle');
    }

    public function test_concurrent_join_no_double_match(): void
    {
        Event::fake([MatchFound::class]);

        // Create 3 users for a 2-player queue
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // User 1 joins (waiting)
        $res1 = $this->actingAs($user1)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);
        $res1->assertJsonPath('data.status', 'waiting');

        // Users 2 and 3 join — only one should trigger a match with user 1
        $res2 = $this->actingAs($user2)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        $res3 = $this->actingAs($user3)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 0,
        ]);

        // Exactly one should be matched, one should be waiting
        $statuses = [$res2->json('data.status'), $res3->json('data.status')];
        $this->assertContains('matched', $statuses);
        $this->assertContains('waiting', $statuses);

        // Only one room should have been created (2 players)
        $rooms = Room::all();
        $this->assertEquals(1, $rooms->count());

        // Only 2 room_players records for the matched room
        $this->assertEquals(2, RoomPlayer::where('room_id', $rooms->first()->id)->count());
    }

    public function test_matchmaking_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
        ]);
        $response->assertStatus(401);
    }

    public function test_matchmaking_rejects_insufficient_coins(): void
    {
        $user = User::factory()->create();
        $user->load('wallet');

        // Set wallet to 0 coins
        $user->wallet->update(['coins_balance' => 0]);

        $response = $this->actingAs($user)->postJson('/api/v1/matchmaking/join', [
            'max_players' => 2,
            'entry_fee' => 500,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Insufficient coins for entry fee');
    }

    public function test_four_player_matchmaking(): void
    {
        Event::fake([MatchFound::class]);

        $users = User::factory()->count(4)->create();

        // First 3 users join — all should be waiting
        for ($i = 0; $i < 3; $i++) {
            $res = $this->actingAs($users[$i])->postJson('/api/v1/matchmaking/join', [
                'max_players' => 4,
                'entry_fee' => 0,
            ]);
            $res->assertJsonPath('data.status', 'waiting');
        }

        // 4th user joins — should trigger match
        $res4 = $this->actingAs($users[3])->postJson('/api/v1/matchmaking/join', [
            'max_players' => 4,
            'entry_fee' => 0,
        ]);

        $res4->assertJsonPath('data.status', 'matched');
        $this->assertCount(4, $res4->json('data.players'));

        Event::assertDispatched(MatchFound::class, 4);
    }
}
