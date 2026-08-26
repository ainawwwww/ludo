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

use App\Services\Auth\GoogleTokenVerifier;
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
        $this->assertEquals('Guest0000', $username);
        $this->assertTrue($response->json('data.user.is_guest'));
        $this->assertEquals(500, $response->json('data.user.coins'));
    }

    public function test_guest_usernames_are_generated_sequentially(): void
    {
        $res1 = $this->postJson('/api/v1/auth/guest', ['device_id' => 'DEV_1']);
        $res1->assertStatus(201);
        $this->assertEquals('Guest0000', $res1->json('data.user.username'));

        $res2 = $this->postJson('/api/v1/auth/guest', ['device_id' => 'DEV_2']);
        $res2->assertStatus(201);
        $this->assertEquals('Guest0001', $res2->json('data.user.username'));

        $res3 = $this->postJson('/api/v1/auth/guest', ['device_id' => 'DEV_3']);
        $res3->assertStatus(201);
        $this->assertEquals('Guest0002', $res3->json('data.user.username'));
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

        // User 1 quick matches -> waits in queue (not enough players for 4-player match)
        $res1 = $this->actingAs($user1)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 4,
            'entry_fee' => 100,
        ]);

        $res1->assertStatus(200);
        $this->assertEquals('waiting', $res1->json('data.status'));

        // User 2 quick matches -> still waiting (need 4 players)
        $res2 = $this->actingAs($user2)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 4,
            'entry_fee' => 100,
        ]);

        $res2->assertStatus(200);
        $this->assertEquals('waiting', $res2->json('data.status'));

        // Now test 2-player quick match
        $user3 = User::factory()->create();
        $user4 = User::factory()->create();

        $res3 = $this->actingAs($user3)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 2,
            'entry_fee' => 100,
        ]);
        $res3->assertStatus(200);
        $this->assertEquals('waiting', $res3->json('data.status'));

        $res4 = $this->actingAs($user4)->postJson('/api/v1/rooms/quick-match', [
            'max_players' => 2,
            'entry_fee' => 100,
        ]);
        $res4->assertStatus(200);
        $this->assertEquals('matched', $res4->json('data.status'));
        $this->assertCount(2, $res4->json('data.players'));
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

    public function test_google_login_creates_new_user_and_wallet(): void
    {
        $mock = $this->mock(GoogleTokenVerifier::class);
        $mock->shouldReceive('verify')
            ->with('valid_token_123')
            ->andReturn([
                'sub' => 'google_sub_1001',
                'email' => 'newuser@example.com',
                'name' => 'New User',
                'picture' => 'https://example.com/pic.png',
            ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid_token_123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'token',
                    'user' => ['id', 'username', 'email', 'google_id', 'auth_provider', 'coins', 'diamonds']
                ]
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'google_id' => 'google_sub_1001',
            'auth_provider' => 'google',
            'is_guest' => false,
        ]);
    }

    public function test_google_login_existing_google_id_logs_in_without_duplication(): void
    {
        $existing = User::factory()->create([
            'username' => 'existing_google_user',
            'email' => 'existing@example.com',
            'google_id' => 'google_sub_2002',
            'auth_provider' => 'google',
        ]);

        $mock = $this->mock(GoogleTokenVerifier::class);
        $mock->shouldReceive('verify')
            ->with('valid_token_2002')
            ->andReturn([
                'sub' => 'google_sub_2002',
                'email' => 'existing@example.com',
                'name' => 'Existing Google User',
            ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid_token_2002',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $existing->id);

        $this->assertEquals(1, User::where('google_id', 'google_sub_2002')->count());
    }

    public function test_google_login_existing_email_links_google_id(): void
    {
        $existingEmailUser = User::factory()->create([
            'username' => 'email_user',
            'email' => 'linkme@example.com',
            'google_id' => null,
            'auth_provider' => 'email',
        ]);

        $mock = $this->mock(GoogleTokenVerifier::class);
        $mock->shouldReceive('verify')
            ->with('valid_token_link')
            ->andReturn([
                'sub' => 'google_sub_3003',
                'email' => 'linkme@example.com',
                'name' => 'Link Me',
            ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid_token_link',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $existingEmailUser->id)
            ->assertJsonPath('data.user.google_id', 'google_sub_3003');

        $this->assertDatabaseHas('users', [
            'id' => $existingEmailUser->id,
            'google_id' => 'google_sub_3003',
            'auth_provider' => 'google',
        ]);
    }

    public function test_google_login_invalid_token_returns_401(): void
    {
        $mock = $this->mock(GoogleTokenVerifier::class);
        $mock->shouldReceive('verify')
            ->with('bad_token')
            ->andReturn(null);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'bad_token',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid Google token',
            ]);
    }

    public function test_google_login_conflicting_google_id_returns_409(): void
    {
        User::factory()->create([
            'username' => 'other_user',
            'email' => 'conflict@example.com',
            'google_id' => 'different_google_id_9999',
            'auth_provider' => 'google',
        ]);

        $mock = $this->mock(GoogleTokenVerifier::class);
        $mock->shouldReceive('verify')
            ->with('valid_token_conflict')
            ->andReturn([
                'sub' => 'new_google_id_8888',
                'email' => 'conflict@example.com',
                'name' => 'Conflict User',
            ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid_token_conflict',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'message' => 'Email is already linked to another account',
            ]);
    }

    public function test_user_registration_auto_infers_country_code_when_omitted(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'dynamo_user_1',
            'email' => 'dynamo1@example.com',
            'password' => 'secret123',
            'country' => 'pk', // Lowercase ISO code without country_code parameter
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.country', 'PK')
            ->assertJsonPath('data.user.country_code', '+92');

        $this->assertDatabaseHas('users', [
            'username' => 'dynamo_user_1',
            'country' => 'PK',
            'country_code' => '+92',
        ]);
    }

    public function test_user_registration_accepts_device_id_and_dynamic_metadata(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'meta_user_99',
            'email' => 'meta99@example.com',
            'password' => 'secret123',
            'country' => 'US',
            'device_id' => 'DEV_META_555',
            'metadata' => [
                'app_version' => '2.4.1',
                'platform' => 'ios',
                'referral_code' => 'FRIEND2026',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.country_code', '+1')
            ->assertJsonPath('data.user.device_id', 'DEV_META_555')
            ->assertJsonPath('data.user.metadata.app_version', '2.4.1')
            ->assertJsonPath('data.user.metadata.platform', 'ios')
            ->assertJsonPath('data.user.metadata.referral_code', 'FRIEND2026');

        $this->assertDatabaseHas('users', [
            'username' => 'meta_user_99',
            'device_id' => 'DEV_META_555',
        ]);
    }

    public function test_user_registration_with_explicit_country_code(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'explicit_user',
            'email' => 'explicit@example.com',
            'password' => 'secret123',
            'country' => 'GB',
            'country_code' => '+44',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.country', 'GB')
            ->assertJsonPath('data.user.country_code', '+44');
    }
}
