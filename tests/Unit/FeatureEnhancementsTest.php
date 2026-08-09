<?php

namespace Tests\Unit;

use App\Enums\FriendStatus;
use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;

use App\Jobs\ProcessTurnTimeout;

use App\Models\Friend;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Models\Wallet;

use App\Services\GameEngine\BoardService;
use App\Services\GameEngine\DiceService;
use App\Services\GameEngine\MoveValidator;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\GameEngine\TurnManager;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_creates_guest_user_and_wallet(): void
    {
        $response = $this->postJson('/api/v1/auth/guest');

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'token',
                    'user' => ['id', 'username', 'is_guest', 'coins', 'diamonds']
                ]
            ]);

        $username = $response->json('data.user.username');
        $this->assertStringStartsWith('Guest', $username);
        $this->assertTrue($response->json('data.user.is_guest'));
        $this->assertEquals(500, $response->json('data.user.coins'));
    }

    public function test_guest_login_same_device_id_returns_same_user(): void
    {
        $deviceId = 'DEV_TEST_UNIQUE_1001';

        $res1 = $this->postJson('/api/v1/auth/guest', ['device_id' => $deviceId]);
        $res1->assertStatus(201);
        $userId1 = $res1->json('data.user.id');

        $res2 = $this->postJson('/api/v1/auth/guest', ['device_id' => $deviceId]);
        $res2->assertStatus(200); // 200 OK for session resumed
        $userId2 = $res2->json('data.user.id');

        $this->assertEquals($userId1, $userId2);
        $this->assertEquals('Guest session resumed', $res2->json('message'));
    }

    public function test_guest_login_different_device_ids_creates_separate_users(): void
    {
        $res1 = $this->postJson('/api/v1/auth/guest', ['device_id' => 'DEV_ALPHA']);
        $res2 = $this->postJson('/api/v1/auth/guest', ['device_id' => 'DEV_BETA']);

        $res1->assertStatus(201);
        $res2->assertStatus(201);

        $this->assertNotEquals($res1->json('data.user.id'), $res2->json('data.user.id'));
    }

    public function test_guest_login_omitting_device_id_creates_new_user_each_time(): void
    {
        $res1 = $this->postJson('/api/v1/auth/guest');
        $res2 = $this->postJson('/api/v1/auth/guest');

        $res1->assertStatus(201);
        $res2->assertStatus(201);

        $this->assertNotEquals($res1->json('data.user.id'), $res2->json('data.user.id'));
    }

    public function test_quick_match_creates_new_room_or_joins_existing(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // User 1 quick matches -> creates new room
        $res1 = $this->actingAs($user1)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 4,
            'entry_fee' => 100,
        ]);

        $res1->assertStatus(201);
        $roomId = $res1->json('data.id');

        // User 2 quick matches -> joins existing room
        $res2 = $this->actingAs($user2)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 4,
            'entry_fee' => 100,
        ]);

        $res2->assertStatus(200);
        $this->assertEquals($roomId, $res2->json('data.id'));
        $this->assertCount(2, $res2->json('data.players'));

        // Check seat & color assignment
        $players = $res2->json('data.players');
        $this->assertEquals(1, $players[0]['seat_position']);
        $this->assertEquals('red', $players[0]['color']);
        $this->assertEquals(2, $players[1]['seat_position']);
        $this->assertEquals('green', $players[1]['color']);
    }

    public function test_leaderboard_global_country_and_friends(): void
    {
        $u1 = User::factory()->create(['username' => 'Player1', 'country' => 'PK']);
        $u2 = User::factory()->create(['username' => 'Player2', 'country' => 'PK']);

        // Create completed game with u1 as winner
        $room = Room::create([
            'room_code' => 'ROOM1',
            'type' => RoomType::PUBLIC->value,
            'max_players' => 2,
            'entry_fee' => 100,
            'status' => RoomStatus::FINISHED->value,
            'created_by' => $u1->id,
            'created_at' => now(),
        ]);

        Game::create([
            'room_id' => $room->id,
            'winner_id' => $u1->id,
            'status' => GameStatus::COMPLETED->value,
            'started_at' => now(),
            'ended_at' => now(),
            'created_at' => now(),
        ]);

        RoomPlayer::create(['room_id' => $room->id, 'user_id' => $u1->id, 'seat_position' => 1, 'color' => 'red', 'is_ready' => true, 'joined_at' => now()]);
        RoomPlayer::create(['room_id' => $room->id, 'user_id' => $u2->id, 'seat_position' => 2, 'color' => 'green', 'is_ready' => true, 'joined_at' => now()]);

        // Add u2 as friend of u1
        Friend::create(['user_id' => $u1->id, 'friend_id' => $u2->id, 'status' => FriendStatus::ACCEPTED->value, 'created_at' => now()]);

        // 1. Global leaderboard
        $resGlobal = $this->actingAs($u1)->getJson('/api/v1/leaderboard?type=global');
        $resGlobal->assertStatus(200);

        // 2. Country leaderboard
        $resCountry = $this->actingAs($u1)->getJson('/api/v1/leaderboard?type=country');
        $resCountry->assertStatus(200);

        // 3. Friends leaderboard
        $resFriends = $this->actingAs($u1)->getJson('/api/v1/leaderboard?type=friends');
        $resFriends->assertStatus(200);
    }

    public function test_turn_timeout_job_executes_auto_pass(): void
    {
        $stateStore = new RedisGameStateStore();
        $boardService = new BoardService();
        $players = [
            ['seat_position' => 0, 'user_id' => 1, 'username' => 'P1', 'color' => 'red'],
            ['seat_position' => 1, 'user_id' => 2, 'username' => 'P2', 'color' => 'green'],
        ];

        $state = $stateStore->initializeState(999, 10, $players);
        $initialTimestamp = $state['last_action_at'];

        // Execute turn timeout job
        $job = new ProcessTurnTimeout(999, 0, $initialTimestamp);
        $job->handle($stateStore, new DiceService(), new MoveValidator($boardService), new TurnManager());

        $newState = $stateStore->getState(999);
        $this->assertNotNull($newState);
        $this->assertNotEquals($initialTimestamp, $newState['last_action_at']);
    }
}
