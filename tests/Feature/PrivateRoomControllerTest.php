<?php

namespace Tests\Feature;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\GameEnded;
use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrivateRoomControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([TurnChanged::class, GameEnded::class]);
    }

    /**
     * Happy path: Host creates a private room and receives unified snapshot shape.
     */
    public function test_create_private_room_happy_path(): void
    {
        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 2000]);

        $response = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 4,
            'entry_fee' => 500,
            'turn_seconds' => 15,
            'title' => 'VIP Club',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertNotNull($data['id']);
        $this->assertEquals(6, strlen($data['code']));
        $this->assertEquals('waiting', $data['status']);
        $this->assertEquals(4, $data['max_players']);
        $this->assertEquals(500, $data['entry_fee']);
        $this->assertEquals(15, $data['turn_seconds']);
        $this->assertEquals($host->id, $data['host_user_id']);
        $this->assertEquals(1, $data['my_seat']);
        $this->assertTrue($data['is_host']);
        $this->assertFalse($data['can_start']);
        $this->assertNull($data['game_id']);
        $this->assertEquals(1, $data['version']);

        $this->assertCount(1, $data['players']);
        $this->assertEquals($host->id, $data['players'][0]['user_id']);
        $this->assertEquals(1, $data['players'][0]['seat']);
        $this->assertEquals(PlayerColor::RED->value, $data['players'][0]['color']);
        $this->assertTrue($data['players'][0]['is_ready']);
        $this->assertTrue($data['players'][0]['is_host']);
    }

    /**
     * Validation: Rejects non-whitelisted values for max_players, entry_fee, turn_seconds.
     */
    public function test_create_rejects_invalid_whitelist_values(): void
    {
        $user = User::factory()->create();

        // Invalid max_players (e.g. 3)
        $this->actingAs($user)->postJson('/api/v1/private-rooms', [
            'max_players' => 3,
            'entry_fee' => 500,
            'turn_seconds' => 15,
        ])->assertStatus(422);

        // Invalid entry_fee (e.g. 777)
        $this->actingAs($user)->postJson('/api/v1/private-rooms', [
            'max_players' => 4,
            'entry_fee' => 777,
            'turn_seconds' => 15,
        ])->assertStatus(422);

        // Invalid turn_seconds (e.g. 45)
        $this->actingAs($user)->postJson('/api/v1/private-rooms', [
            'max_players' => 4,
            'entry_fee' => 500,
            'turn_seconds' => 45,
        ])->assertStatus(422);
    }

    /**
     * Join happy path: Normalizes lowercase code and seats guest.
     */
    public function test_join_happy_path_normalizes_lowercase_code(): void
    {
        $host = User::factory()->create();
        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $code = strtolower($createRes->json('data.code'));

        $guest = User::factory()->create();
        $joinRes = $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', [
            'room_code' => $code,
        ]);

        $joinRes->assertStatus(200);
        $data = $joinRes->json('data');

        $this->assertEquals(2, $data['my_seat']);
        $this->assertFalse($data['is_host']);
        $this->assertCount(2, $data['players']);
        $this->assertEquals(2, $data['version']); // Created (1) -> Joined (2)
        // Guest is not ready yet, so can_start is false
        $this->assertFalse($data['can_start']);
    }

    /**
     * Rejects invalid room code format and non-existent codes.
     */
    public function test_join_rejects_invalid_code_format_and_missing_room(): void
    {
        $guest = User::factory()->create();

        // Invalid regex format (not 6 chars alphanumeric)
        $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', [
            'room_code' => 'XYZ',
        ])->assertStatus(422);

        // Non-existent 6-character code
        $res = $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', [
            'room_code' => 'NONEX1',
        ]);
        $res->assertStatus(404);
        $this->assertEquals('ROOM_NOT_FOUND', $res->json('error_code'));
    }

    /**
     * A user can be in only ONE active private room at a time.
     */
    public function test_cannot_create_or_join_when_already_in_another_active_room(): void
    {
        $user = User::factory()->create();
        $firstRoomRes = $this->actingAs($user)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $roomId = $firstRoomRes->json('data.id');

        // Attempt creating a second room
        $createAgainRes = $this->actingAs($user)->postJson('/api/v1/private-rooms', [
            'max_players' => 4,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $createAgainRes->assertStatus(409);
        $this->assertEquals('ALREADY_IN_ROOM', $createAgainRes->json('error_code'));
        $this->assertEquals($roomId, $createAgainRes->json('room_id'));

        // Attempt joining another room created by someone else
        $otherHost = User::factory()->create();
        $otherRes = $this->actingAs($otherHost)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $otherCode = $otherRes->json('data.code');

        $joinRes = $this->actingAs($user)->postJson('/api/v1/private-rooms/join', [
            'room_code' => $otherCode,
        ]);
        $joinRes->assertStatus(409);
        $this->assertEquals('ALREADY_IN_ROOM', $joinRes->json('error_code'));
        $this->assertEquals($roomId, $joinRes->json('room_id'));
    }

    /**
     * Rejects join when room is full.
     */
    public function test_join_rejects_when_room_is_full(): void
    {
        $host = User::factory()->create();
        $res = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $code = $res->json('data.code');

        $guest1 = User::factory()->create();
        $this->actingAs($guest1)->postJson('/api/v1/private-rooms/join', ['room_code' => $code])->assertStatus(200);

        $guest2 = User::factory()->create();
        $failRes = $this->actingAs($guest2)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);
        $failRes->assertStatus(409);
        $this->assertEquals('ROOM_FULL', $failRes->json('error_code'));
    }

    /**
     * Insufficient balance pre-checks at create and join.
     */
    public function test_insufficient_balance_at_create_and_join(): void
    {
        $poorHost = User::factory()->create();
        $poorHost->wallet->update(['coins_balance' => 200]);

        $createRes = $this->actingAs($poorHost)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
            'turn_seconds' => 15,
        ]);
        $createRes->assertStatus(402);
        $this->assertEquals('INSUFFICIENT_BALANCE', $createRes->json('error_code'));

        $richHost = User::factory()->create();
        $richHost->wallet->update(['coins_balance' => 5000]);
        $roomRes = $this->actingAs($richHost)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
            'turn_seconds' => 15,
        ]);
        $code = $roomRes->json('data.code');

        $poorGuest = User::factory()->create();
        $poorGuest->wallet->update(['coins_balance' => 500]);
        $joinRes = $this->actingAs($poorGuest)->postJson('/api/v1/private-rooms/join', [
            'room_code' => $code,
        ]);
        $joinRes->assertStatus(402);
        $this->assertEquals('INSUFFICIENT_BALANCE', $joinRes->json('error_code'));
    }

    /**
     * GET /current active room endpoint.
     */
    public function test_get_current_active_room(): void
    {
        $user = User::factory()->create();

        // No active room -> 404 ROOM_NOT_FOUND
        $this->actingAs($user)->getJson('/api/v1/private-rooms/current')
            ->assertStatus(404)
            ->assertJson(['error_code' => 'ROOM_NOT_FOUND']);

        // Create a room -> 200 snapshot
        $createRes = $this->actingAs($user)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');

        $currentRes = $this->actingAs($user)->getJson('/api/v1/private-rooms/current');
        $currentRes->assertStatus(200);
        $this->assertEquals($roomId, $currentRes->json('data.id'));
    }

    /**
     * Members-only guard on GET /{room}.
     */
    public function test_show_members_only_guard(): void
    {
        $host = User::factory()->create();
        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');

        // Member sees snapshot
        $this->actingAs($host)->getJson("/api/v1/private-rooms/{$roomId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $roomId);

        // Non-member gets 404 ROOM_NOT_FOUND so existence does not leak
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/v1/private-rooms/{$roomId}")
            ->assertStatus(404)
            ->assertJson(['error_code' => 'ROOM_NOT_FOUND']);

        // Alphanumeric code lookup is rejected with 404 (only numeric ID allowed)
        $code = $createRes->json('data.code');
        $this->actingAs($host)->getJson("/api/v1/private-rooms/{$code}")
            ->assertStatus(404)
            ->assertJson(['error_code' => 'ROOM_NOT_FOUND']);
    }

    /**
     * Ready toggle and can_start evaluation.
     */
    public function test_ready_toggle_by_guest_updates_can_start(): void
    {
        $host = User::factory()->create();
        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $guest = User::factory()->create();
        $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);

        // Toggle ready
        $readyRes = $this->actingAs($guest)->postJson("/api/v1/private-rooms/{$roomId}/ready", ['is_ready' => true]);
        $readyRes->assertStatus(200);
        $this->assertTrue($readyRes->json('data.can_start'));

        // Non-member cannot toggle ready (404 so existence does not leak)
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson("/api/v1/private-rooms/{$roomId}/ready")
            ->assertStatus(404);
    }

    /**
     * Start guards: non-host, not enough players, guests not ready.
     */
    public function test_start_guards(): void
    {
        $host = User::factory()->create();
        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        // 1. Host tries to start when not full (only 1 player)
        $notEnoughRes = $this->actingAs($host)->postJson("/api/v1/private-rooms/{$roomId}/start");
        $notEnoughRes->assertStatus(409);
        $this->assertEquals('NOT_ENOUGH_PLAYERS', $notEnoughRes->json('error_code'));

        $guest = User::factory()->create();
        $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);

        // 2. Non-host tries to start
        $guestStartRes = $this->actingAs($guest)->postJson("/api/v1/private-rooms/{$roomId}/start");
        $guestStartRes->assertStatus(403);
        $this->assertEquals('NOT_HOST', $guestStartRes->json('error_code'));

        // 3. Host tries to start when guest is not ready
        $notReadyRes = $this->actingAs($host)->postJson("/api/v1/private-rooms/{$roomId}/start");
        $notReadyRes->assertStatus(409);
        $this->assertEquals('PLAYERS_NOT_READY', $notReadyRes->json('error_code'));
    }

    /**
     * Start happy path and double-start protection with deterministic wallet references.
     */
    public function test_start_happy_path_and_double_start_protection(): void
    {
        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 1000]);

        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 500,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 1000]);
        $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);
        $this->actingAs($guest)->postJson("/api/v1/private-rooms/{$roomId}/ready", ['is_ready' => true]);

        // Host starts
        $startRes = $this->actingAs($host)->postJson("/api/v1/private-rooms/{$roomId}/start");
        $startRes->assertStatus(200);
        $data = $startRes->json('data');

        $this->assertEquals('playing', $data['status']);
        $this->assertNotNull($data['game_id']);
        $this->assertEquals(500, $host->fresh()->wallet->coins_balance);
        $this->assertEquals(500, $guest->fresh()->wallet->coins_balance);

        // Double start tap returns 409 ROOM_ALREADY_STARTED and charges nothing
        $doubleStartRes = $this->actingAs($host)->postJson("/api/v1/private-rooms/{$roomId}/start");
        $doubleStartRes->assertStatus(409);
        $this->assertEquals('ROOM_ALREADY_STARTED', $doubleStartRes->json('error_code'));
        $this->assertEquals(500, $host->fresh()->wallet->coins_balance);
        $this->assertEquals(500, $guest->fresh()->wallet->coins_balance);
    }

    /**
     * Start rollback when one player has low balance at start time.
     */
    public function test_start_with_low_balance_rolls_back_atomically(): void
    {
        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 1000]);

        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 500,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 1000]);
        $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);
        $this->actingAs($guest)->postJson("/api/v1/private-rooms/{$roomId}/ready", ['is_ready' => true]);

        // Guest spends coins elsewhere before match starts
        $guest->wallet->update(['coins_balance' => 100]);

        $failRes = $this->actingAs($host)->postJson("/api/v1/private-rooms/{$roomId}/start");
        $failRes->assertStatus(402);
        $this->assertEquals('INSUFFICIENT_BALANCE', $failRes->json('error_code'));

        // Host was NOT debited
        $this->assertEquals(1000, $host->fresh()->wallet->coins_balance);
        $this->assertEquals('waiting', Room::find($roomId)->status->value);
    }

    /**
     * Leave semantics: guest leaves frees seat, host leaves cancels room without deleting players, leave during playing is blocked.
     */
    public function test_leave_semantics(): void
    {
        $host = User::factory()->create();
        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $guest = User::factory()->create();
        $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);

        // 1. Guest leaves
        $guestLeaveRes = $this->actingAs($guest)->postJson("/api/v1/private-rooms/{$roomId}/leave");
        $guestLeaveRes->assertStatus(200);
        $this->assertEquals('waiting', Room::find($roomId)->status->value);
        $this->assertEquals(1, RoomPlayer::where('room_id', $roomId)->count());

        // Guest rejoins and readies
        $this->actingAs($guest)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);
        $this->actingAs($guest)->postJson("/api/v1/private-rooms/{$roomId}/ready", ['is_ready' => true]);

        // 2. Start game, then try to leave -> 409 ROOM_ALREADY_STARTED
        $this->actingAs($host)->postJson("/api/v1/private-rooms/{$roomId}/start");
        $leavePlayingRes = $this->actingAs($guest)->postJson("/api/v1/private-rooms/{$roomId}/leave");
        $leavePlayingRes->assertStatus(409);
        $this->assertEquals('ROOM_ALREADY_STARTED', $leavePlayingRes->json('error_code'));

        // 3. New waiting room: host leaves -> room is CANCELLED (players rows preserved)
        $host2 = User::factory()->create();
        $room2Res = $this->actingAs($host2)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $room2Id = $room2Res->json('data.id');

        $hostLeaveRes = $this->actingAs($host2)->postJson("/api/v1/private-rooms/{$room2Id}/leave");
        $hostLeaveRes->assertStatus(200);
        $this->assertEquals('cancelled', Room::find($room2Id)->status->value);
        $this->assertEquals(1, RoomPlayer::where('room_id', $room2Id)->count());
    }

    /**
     * Join rate limiting: 10 attempts per minute per user.
     */
    public function test_join_endpoint_rate_limiting(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson('/api/v1/private-rooms/join', [
                'room_code' => 'TEST' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        // 11th request hits throttle
        $response = $this->actingAs($user)->postJson('/api/v1/private-rooms/join', [
            'room_code' => 'TEST99',
        ]);

        $response->assertStatus(429);
    }

    /**
     * Legacy guards: public room endpoints and lobby feeds must reject or exclude private rooms.
     */
    public function test_legacy_guards_protect_private_rooms(): void
    {
        $host = User::factory()->create();
        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 500,
            'turn_seconds' => 15,
        ]);
        $createRes->assertStatus(201);
        $privateRoomId = $createRes->json('data.id');
        $privateCode = $createRes->json('data.code');

        $stranger = User::factory()->create();
        $stranger->wallet->update(['coins_balance' => 1000]);

        // 1. Legacy POST /rooms/join with private room code must be rejected (403)
        $this->actingAs($stranger)->postJson('/api/v1/rooms/join', [
            'room_code' => $privateCode,
        ])->assertStatus(403);

        // 2. Legacy GET /rooms/{id} for private room by non-member must be rejected (403)
        $this->actingAs($stranger)->getJson("/api/v1/rooms/{$privateRoomId}")
            ->assertStatus(403);

        // 3. Legacy POST /rooms/{id}/join as listener must be rejected (403)
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$privateRoomId}/join")
            ->assertStatus(403);

        // 4. Legacy POST /rooms/{id}/seat must be rejected (403)
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$privateRoomId}/seat", ['seat_position' => 2])
            ->assertStatus(403);

        // 5. Legacy POST /rooms/{id}/leave-seat must be rejected (403)
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$privateRoomId}/leave-seat")
            ->assertStatus(403);

        // 6. Lobby explore, hot must never include private rooms
        $exploreRes = $this->actingAs($stranger)->getJson('/api/v1/lobby/explore');
        $exploreRes->assertStatus(200);
        $exploreRoomIds = collect($exploreRes->json('data.recommended_rooms'))->pluck('id')->toArray();
        $this->assertNotContains($privateRoomId, $exploreRoomIds);

        $hotRes = $this->actingAs($stranger)->getJson('/api/v1/lobby/hot');
        $hotRes->assertStatus(200);
        $hotRoomIds = collect($hotRes->json('data.trending_rooms'))->pluck('id')->toArray();
        $this->assertNotContains($privateRoomId, $hotRoomIds);
    }

    /**
     * Concurrency: Two users race to join a 2-player room with 1 seat remaining.
     * Exactly one succeeds and one receives 409 ROOM_FULL.
     */
    public function test_two_users_racing_for_last_seat(): void
    {
        $host = User::factory()->create();
        $createRes = $this->actingAs($host)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $code = $createRes->json('data.code');
        $roomId = $createRes->json('data.id');

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $resA = $this->actingAs($userA)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);
        $resB = $this->actingAs($userB)->postJson('/api/v1/private-rooms/join', ['room_code' => $code]);

        $statuses = collect([$resA->status(), $resB->status()]);
        $this->assertTrue($statuses->contains(200));
        $this->assertTrue($statuses->contains(409));

        $this->assertEquals(2, RoomPlayer::where('room_id', $roomId)->count());
    }

    /**
     * Regression: Public rooms and QuickMatch endpoints remain fully functional without interference.
     */
    public function test_public_rooms_and_quick_match_regression_remain_functional(): void
    {
        // 1. Create public room
        $publicHost = User::factory()->create();
        $publicHost->wallet->update(['coins_balance' => 1000]);

        $pubCreateRes = $this->actingAs($publicHost)->postJson('/api/v1/rooms', [
            'title' => 'Public Room Test',
            'entry_fee' => 100,
        ]);
        $pubCreateRes->assertStatus(201);
        $pubRoomId = $pubCreateRes->json('data.id');

        // Public index lists public room
        $indexRes = $this->actingAs($publicHost)->getJson('/api/v1/rooms');
        $indexRes->assertStatus(200);
        $listedIds = collect($indexRes->json('data'))->pluck('id')->toArray();
        $this->assertContains($pubRoomId, $listedIds);

        // 2. Quick Match endpoint
        $qmUser = User::factory()->create();
        $qmUser->wallet->update(['coins_balance' => 1000]);

        $qmRes = $this->actingAs($qmUser)->postJson('/api/v1/quick-match/join', [
            'entry_fee' => 100,
            'player_count' => 2,
        ]);
        $qmRes->assertStatus(200);
    }
}

