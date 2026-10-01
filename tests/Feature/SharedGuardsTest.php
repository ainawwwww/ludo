<?php

namespace Tests\Feature;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;
    protected User $member;
    protected User $outsider;
    protected Room $teamRoom;
    protected Room $privateRoom;
    protected Room $vipRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
        Wallet::create(['user_id' => $this->host->id, 'coins_balance' => 1000]);

        $this->member = User::factory()->create();
        Wallet::create(['user_id' => $this->member->id, 'coins_balance' => 1000]);

        $this->outsider = User::factory()->create();
        Wallet::create(['user_id' => $this->outsider->id, 'coins_balance' => 1000]);

        // Create Team Room with host and member
        $this->teamRoom = Room::create([
            'room_code' => 'TEAM01',
            'title' => 'Team Battle',
            'type' => RoomType::TEAM->value,
            'max_players' => 4,
            'entry_fee' => 100,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $this->host->id,
            'member_count' => 2,
        ]);

        RoomPlayer::create([
            'room_id' => $this->teamRoom->id,
            'user_id' => $this->host->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
            'is_ready' => true,
        ]);

        RoomPlayer::create([
            'room_id' => $this->teamRoom->id,
            'user_id' => $this->member->id,
            'seat_position' => 3,
            'color' => PlayerColor::YELLOW->value,
            'is_ready' => true,
        ]);

        // Create Private Room
        $this->privateRoom = Room::create([
            'room_code' => 'PRIV01',
            'title' => 'Private Room',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 100,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $this->host->id,
            'member_count' => 1,
        ]);

        RoomPlayer::create([
            'room_id' => $this->privateRoom->id,
            'user_id' => $this->host->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
            'is_ready' => true,
        ]);

        // Create VIP Room
        $this->vipRoom = Room::create([
            'room_code' => 'VIP001',
            'title' => 'VIP Lounge',
            'type' => RoomType::VIP->value,
            'max_players' => 4,
            'entry_fee' => 500,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $this->host->id,
            'member_count' => 1,
        ]);

        RoomPlayer::create([
            'room_id' => $this->vipRoom->id,
            'user_id' => $this->host->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
            'is_ready' => true,
        ]);
    }

    public function test_team_room_chat_blocks_non_members(): void
    {
        // Member can get messages
        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/v1/chat/messages?room_id={$this->teamRoom->id}");
        $response->assertStatus(200);

        // Outsider blocked
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/v1/chat/messages?room_id={$this->teamRoom->id}");
        $response->assertStatus(403);
    }

    public function test_team_room_chat_send_message_blocks_non_members(): void
    {
        // Member can send
        $response = $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/v1/chat/message', [
                'room_id' => $this->teamRoom->id,
                'message' => 'Go team!',
                'message_type' => 'text',
            ]);
        $response->assertStatus(200);

        // Outsider blocked
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->postJson('/api/v1/chat/message', [
                'room_id' => $this->teamRoom->id,
                'message' => 'Sneaky message',
                'message_type' => 'text',
            ]);
        $response->assertStatus(403);
    }

    public function test_public_room_show_blocks_non_members_for_team_rooms(): void
    {
        // Member allowed
        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->teamRoom->id}");
        $response->assertStatus(200);

        // Outsider blocked
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->teamRoom->id}");
        $response->assertStatus(403);
    }

    public function test_public_room_join_and_listener_endpoints_block_team_rooms(): void
    {
        // Public join blocked for team room
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->postJson('/api/v1/rooms/join', [
                'room_code' => 'TEAM01',
            ]);
        $response->assertStatus(403);

        // Public join as listener blocked for team room
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->postJson("/api/v1/rooms/{$this->teamRoom->id}/join");
        $response->assertStatus(403);
    }

    public function test_public_take_seat_blocks_team_rooms(): void
    {
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->postJson("/api/v1/rooms/{$this->teamRoom->id}/seat", [
                'seat_position' => 2,
            ]);
        $response->assertStatus(403);
    }

    public function test_websocket_channel_authorization_includes_team_rooms(): void
    {
        // Member allowed on room channel
        $this->assertTrue(
            $this->teamRoom->players()->where('user_id', $this->member->id)->exists()
        );

        // Outsider not allowed
        $this->assertFalse(
            $this->teamRoom->players()->where('user_id', $this->outsider->id)->exists()
        );
    }

    public function test_private_and_vip_rooms_remain_unaffected(): void
    {
        // Private room blocks outsider from chat
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/v1/chat/messages?room_id={$this->privateRoom->id}");
        $response->assertStatus(403);

        // VIP room blocks outsider from room show
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->vipRoom->id}");
        $response->assertStatus(403);

        // Private room host allowed to access chat
        $response = $this->actingAs($this->host, 'sanctum')
            ->getJson("/api/v1/chat/messages?room_id={$this->privateRoom->id}");
        $response->assertStatus(200);
    }
}
