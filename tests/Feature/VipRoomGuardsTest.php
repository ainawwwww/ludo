<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VipRoomGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_and_shared_guards_protect_vip_rooms(): void
    {
        $host = User::factory()->create();
        $vipRoom = Room::create([
            'room_code' => 'VIP999',
            'title' => "{$host->username}'s VIP Room",
            'type' => RoomType::VIP->value,
            'max_players' => 2,
            'entry_fee' => 5000,
            'turn_seconds' => 15,
            'status' => RoomStatus::WAITING->value,
            'state_version' => 1,
            'created_by' => $host->id,
            'is_live' => true,
        ]);

        RoomPlayer::create([
            'room_id' => $vipRoom->id,
            'user_id' => $host->id,
            'seat_position' => 1,
            'color' => 'red',
            'is_ready' => true,
            'joined_at' => now(),
        ]);

        $stranger = User::factory()->create();
        $stranger->wallet->update(['coins_balance' => 10000]);

        // 1. Legacy POST /rooms/join with VIP room code must be rejected (403)
        $this->actingAs($stranger)->postJson('/api/v1/rooms/join', [
            'room_code' => 'VIP999',
        ])->assertStatus(403);

        // 2. Legacy GET /rooms/{id} for VIP room by non-member must be rejected (403)
        $this->actingAs($stranger)->getJson("/api/v1/rooms/{$vipRoom->id}")
            ->assertStatus(403);

        // 3. Legacy POST /rooms/{id}/join as listener must be rejected (403)
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$vipRoom->id}/join")
            ->assertStatus(403);

        // 4. Legacy POST /rooms/{id}/seat must be rejected (403)
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$vipRoom->id}/seat", ['seat_position' => 2])
            ->assertStatus(403);

        // 5. Legacy POST /rooms/{id}/leave-seat must be rejected (403)
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$vipRoom->id}/leave-seat")
            ->assertStatus(403);

        // 6. Lobby explore, hot must never include VIP rooms
        $exploreRes = $this->actingAs($stranger)->getJson('/api/v1/lobby/explore');
        $exploreRes->assertStatus(200);
        $exploreRoomIds = collect($exploreRes->json('data.recommended_rooms'))->pluck('id')->toArray();
        $this->assertNotContains($vipRoom->id, $exploreRoomIds);

        $hotRes = $this->actingAs($stranger)->getJson('/api/v1/lobby/hot');
        $hotRes->assertStatus(200);
        $hotRoomIds = collect($hotRes->json('data.trending_rooms'))->pluck('id')->toArray();
        $this->assertNotContains($vipRoom->id, $hotRoomIds);

        // 7. Chat endpoint for VIP room by non-member must be rejected (403)
        $this->actingAs($stranger)->postJson('/api/v1/chat/message', [
            'room_id' => $vipRoom->id,
            'message' => 'Hello',
        ])->assertStatus(403);

        $this->actingAs($stranger)->getJson("/api/v1/chat/messages?room_id={$vipRoom->id}")
            ->assertStatus(403);

        // 8. Public game start endpoint for VIP room must be rejected (403)
        $this->actingAs($host)->postJson('/api/v1/game/start', [
            'room_id' => $vipRoom->id,
        ])->assertStatus(403);
    }
}
