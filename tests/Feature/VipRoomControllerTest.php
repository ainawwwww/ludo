<?php

namespace Tests\Feature;

use App\Enums\RoomType;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VipRoomControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([\App\Jobs\ProcessTurnTimeout::class]);
        Event::fake([\App\Events\TurnChanged::class, \App\Events\GameEnded::class]);
    }

    protected function createVipUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        Subscription::create([
            'user_id' => $user->id,
            'tier' => SubscriptionTier::KNIGHT,
            'status' => SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'last_daily_reward_at' => now(),
            'auto_renew' => true,
        ]);
        return $user;
    }

    public function test_create_vip_room_happy_path(): void
    {
        $user = $this->createVipUser();
        $user->wallet->update(['coins_balance' => 20000]);

        $response = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
            'turn_seconds' => 15,
            'title' => 'Royal Stars Lounge',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.max_players', 2);
        $response->assertJsonPath('data.entry_fee', 5000);
        $response->assertJsonPath('data.turn_seconds', 15);
        $response->assertJsonPath('data.is_host', true);
        $response->assertJsonPath('data.my_seat', 1);
        $response->assertJsonPath('data.players.0.is_ready', true);

        $roomId = $response->json('data.id');
        $room = Room::find($roomId);
        $this->assertEquals(RoomType::VIP, $room->type);
        $this->assertEquals('Royal Stars Lounge', $room->title);
    }

    public function test_create_rejects_invalid_whitelist_values(): void
    {
        $user = $this->createVipUser();

        // 0 and 500 are private fees, but MUST be rejected for VIP room!
        $resFeeZero = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $resFeeZero->assertStatus(422);

        $resFee500 = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 500,
            'turn_seconds' => 15,
        ]);
        $resFee500->assertStatus(422);

        // Max players 3 is invalid
        $resMax3 = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 3,
            'entry_fee' => 5000,
            'turn_seconds' => 15,
        ]);
        $resMax3->assertStatus(422);
    }

    public function test_join_happy_path_normalizes_lowercase_code(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 20000]);
        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 10000,
            'turn_seconds' => 30,
        ]);
        $code = $createRes->json('data.code');

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 20000]);

        $joinRes = $this->actingAs($guest)->postJson('/api/v1/vip-rooms/join', [
            'room_code' => strtolower($code),
        ]);

        $joinRes->assertStatus(200);
        $joinRes->assertJsonPath('data.my_seat', 2);
        $joinRes->assertJsonPath('data.is_host', false);
        $joinRes->assertJsonPath('data.players.1.is_ready', false);
    }

    public function test_join_rejects_invalid_code_format_and_missing_room(): void
    {
        $user = $this->createVipUser();

        $invalidFormat = $this->actingAs($user)->postJson('/api/v1/vip-rooms/join', ['room_code' => 'SHORT']);
        $invalidFormat->assertStatus(404);

        $missingRes = $this->actingAs($user)->postJson('/api/v1/vip-rooms/join', ['room_code' => '999999']);
        $missingRes->assertStatus(404);
        $this->assertEquals('ROOM_NOT_FOUND', $missingRes->json('error_code'));
    }

    public function test_cannot_create_or_join_when_already_in_another_active_room(): void
    {
        $user = $this->createVipUser();
        $user->wallet->update(['coins_balance' => 50000]);

        $firstRoomRes = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $firstRoomRes->assertStatus(201);

        // Second create attempt fails
        $secondCreate = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $secondCreate->assertStatus(409);
        $this->assertEquals('ALREADY_IN_ROOM', $secondCreate->json('error_code'));

        // Join attempt fails
        $otherHost = $this->createVipUser();
        $otherHost->wallet->update(['coins_balance' => 50000]);
        $otherRoomRes = $this->actingAs($otherHost)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $otherCode = $otherRoomRes->json('data.code');

        $joinFail = $this->actingAs($user)->postJson('/api/v1/vip-rooms/join', ['room_code' => $otherCode]);
        $joinFail->assertStatus(409);
        $this->assertEquals('ALREADY_IN_ROOM', $joinFail->json('error_code'));
    }

    public function test_join_rejects_when_room_is_full(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);
        $res = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $code = $res->json('data.code');

        $guest1 = User::factory()->create();
        $guest1->wallet->update(['coins_balance' => 50000]);
        $this->actingAs($guest1)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code])->assertStatus(200);

        $guest2 = User::factory()->create();
        $guest2->wallet->update(['coins_balance' => 50000]);
        $failRes = $this->actingAs($guest2)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);
        $failRes->assertStatus(409);
        $this->assertEquals('ROOM_FULL', $failRes->json('error_code'));
    }

    public function test_insufficient_balance_at_create_and_join(): void
    {
        $poorHost = $this->createVipUser();
        $poorHost->wallet->update(['coins_balance' => 500]);

        $createRes = $this->actingAs($poorHost)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
            'turn_seconds' => 15,
        ]);
        $createRes->assertStatus(402);
        $this->assertEquals('INSUFFICIENT_BALANCE', $createRes->json('error_code'));

        $richHost = $this->createVipUser();
        $richHost->wallet->update(['coins_balance' => 50000]);
        $roomRes = $this->actingAs($richHost)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 25000,
            'turn_seconds' => 15,
        ]);
        $code = $roomRes->json('data.code');

        $poorGuest = User::factory()->create();
        $poorGuest->wallet->update(['coins_balance' => 10000]);
        $joinRes = $this->actingAs($poorGuest)->postJson('/api/v1/vip-rooms/join', [
            'room_code' => $code,
        ]);
        $joinRes->assertStatus(402);
        $this->assertEquals('INSUFFICIENT_BALANCE', $joinRes->json('error_code'));
    }

    public function test_get_current_active_room(): void
    {
        $user = $this->createVipUser();
        $user->wallet->update(['coins_balance' => 50000]);

        $noRoom = $this->actingAs($user)->getJson('/api/v1/vip-rooms/current');
        $noRoom->assertStatus(404);

        $createRes = $this->actingAs($user)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
        ]);
        $roomId = $createRes->json('data.id');

        $currentRes = $this->actingAs($user)->getJson('/api/v1/vip-rooms/current');
        $currentRes->assertStatus(200);
        $this->assertEquals($roomId, $currentRes->json('data.id'));
    }

    public function test_show_members_only_guard(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);
        $roomRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $roomId = $roomRes->json('data.id');

        $stranger = User::factory()->create();
        $showRes = $this->actingAs($stranger)->getJson("/api/v1/vip-rooms/{$roomId}");
        $showRes->assertStatus(404);

        $hostShow = $this->actingAs($host)->getJson("/api/v1/vip-rooms/{$roomId}");
        $hostShow->assertStatus(200);
    }

    public function test_ready_toggle_by_guest_updates_can_start(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);
        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 50000]);
        $this->actingAs($guest)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);

        // Full room, guest unready -> can_start false
        $showRes1 = $this->actingAs($host)->getJson("/api/v1/vip-rooms/{$roomId}");
        $this->assertFalse($showRes1->json('data.can_start'));

        // Guest readies -> can_start true
        $readyRes = $this->actingAs($guest)->postJson("/api/v1/vip-rooms/{$roomId}/ready", ['is_ready' => true]);
        $readyRes->assertStatus(200);
        $this->assertTrue($readyRes->json('data.can_start'));
    }

    public function test_start_guards(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);
        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        // 1. Host tries to start when room not full -> 400 NOT_ENOUGH_PLAYERS
        $notFull = $this->actingAs($host)->postJson("/api/v1/vip-rooms/{$roomId}/start");
        $notFull->assertStatus(409);
        $this->assertEquals('NOT_ENOUGH_PLAYERS', $notFull->json('error_code'));

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 50000]);
        $this->actingAs($guest)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);

        // 2. Non-host tries to start -> 403 NOT_HOST
        $nonHost = $this->actingAs($guest)->postJson("/api/v1/vip-rooms/{$roomId}/start");
        $nonHost->assertStatus(403);
        $this->assertEquals('NOT_HOST', $nonHost->json('error_code'));

        // 3. Host tries to start when guest unready -> 409 PLAYERS_NOT_READY
        $unready = $this->actingAs($host)->postJson("/api/v1/vip-rooms/{$roomId}/start");
        $unready->assertStatus(409);
        $this->assertEquals('PLAYERS_NOT_READY', $unready->json('error_code'));
    }

    public function test_start_happy_path_and_double_start_protection(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 50000]);

        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 5000,
            'turn_seconds' => 15,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $this->actingAs($guest)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);
        $this->actingAs($guest)->postJson("/api/v1/vip-rooms/{$roomId}/ready", ['is_ready' => true]);

        $startRes = $this->actingAs($host)->postJson("/api/v1/vip-rooms/{$roomId}/start");
        $startRes->assertStatus(200);
        $this->assertEquals('playing', $startRes->json('data.status'));
        $this->assertNotNull($startRes->json('data.game_id'));

        // Wallets debited by 5000 each
        $this->assertEquals(45000, $host->wallet->fresh()->coins_balance);
        $this->assertEquals(45000, $guest->wallet->fresh()->coins_balance);

        // Double start fails
        $doubleStart = $this->actingAs($host)->postJson("/api/v1/vip-rooms/{$roomId}/start");
        $doubleStart->assertStatus(409);
    }

    public function test_start_with_low_balance_rolls_back_atomically(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 50000]);

        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 10000,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $this->actingAs($guest)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);
        $this->actingAs($guest)->postJson("/api/v1/vip-rooms/{$roomId}/ready", ['is_ready' => true]);

        // Drain guest's balance right before start
        $guest->wallet->update(['coins_balance' => 5000]);

        $startRes = $this->actingAs($host)->postJson("/api/v1/vip-rooms/{$roomId}/start");
        $startRes->assertStatus(402);
        $this->assertEquals('INSUFFICIENT_BALANCE', $startRes->json('error_code'));

        // Host balance unaffected (rolled back)
        $this->assertEquals(50000, $host->wallet->fresh()->coins_balance);
        $this->assertEquals('waiting', Room::find($roomId)->status->value);
    }

    public function test_leave_semantics(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);
        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $roomId = $createRes->json('data.id');
        $code = $createRes->json('data.code');

        $guest = User::factory()->create();
        $guest->wallet->update(['coins_balance' => 50000]);
        $this->actingAs($guest)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);

        // 1. Guest leaves -> room stays waiting
        $guestLeaveRes = $this->actingAs($guest)->postJson("/api/v1/vip-rooms/{$roomId}/leave");
        $guestLeaveRes->assertStatus(200);
        $this->assertEquals('waiting', Room::find($roomId)->status->value);
        $this->assertEquals(1, RoomPlayer::where('room_id', $roomId)->count());

        // Guest rejoins and readies
        $this->actingAs($guest)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);
        $this->actingAs($guest)->postJson("/api/v1/vip-rooms/{$roomId}/ready", ['is_ready' => true]);

        // 2. Start game, then try to leave -> 409 ROOM_ALREADY_STARTED
        $this->actingAs($host)->postJson("/api/v1/vip-rooms/{$roomId}/start");
        $leavePlayingRes = $this->actingAs($guest)->postJson("/api/v1/vip-rooms/{$roomId}/leave");
        $leavePlayingRes->assertStatus(409);
        $this->assertEquals('ROOM_ALREADY_STARTED', $leavePlayingRes->json('error_code'));

        // 3. New waiting room: host leaves -> room is CANCELLED
        $host2 = $this->createVipUser();
        $host2->wallet->update(['coins_balance' => 50000]);
        $room2Res = $this->actingAs($host2)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $room2Id = $room2Res->json('data.id');

        $hostLeaveRes = $this->actingAs($host2)->postJson("/api/v1/vip-rooms/{$room2Id}/leave");
        $hostLeaveRes->assertStatus(200);
        $this->assertEquals('cancelled', Room::find($room2Id)->status->value);
    }

    public function test_join_endpoint_rate_limiting(): void
    {
        $user = $this->createVipUser();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson('/api/v1/vip-rooms/join', [
                'room_code' => 'TEST' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        // 11th request hits throttle
        $response = $this->actingAs($user)->postJson('/api/v1/vip-rooms/join', [
            'room_code' => 'TEST99',
        ]);

        $response->assertStatus(429);
    }

    public function test_two_users_racing_for_last_seat(): void
    {
        $host = $this->createVipUser();
        $host->wallet->update(['coins_balance' => 50000]);
        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 1000,
        ]);
        $code = $createRes->json('data.code');
        $roomId = $createRes->json('data.id');

        $userA = User::factory()->create();
        $userA->wallet->update(['coins_balance' => 50000]);

        $userB = User::factory()->create();
        $userB->wallet->update(['coins_balance' => 50000]);

        $resA = $this->actingAs($userA)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);
        $resB = $this->actingAs($userB)->postJson('/api/v1/vip-rooms/join', ['room_code' => $code]);

        $statuses = collect([$resA->status(), $resB->status()]);
        $this->assertTrue($statuses->contains(200));
        $this->assertTrue($statuses->contains(409));

        $this->assertEquals(2, RoomPlayer::where('room_id', $roomId)->count());
    }
}
