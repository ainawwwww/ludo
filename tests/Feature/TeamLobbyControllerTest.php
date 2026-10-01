<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TeamLobbyControllerTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_create_team_room_happy_path(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000]);

        $response = $this->actingAs($user)->postJson('/api/v1/team-rooms/create', [
            'entry_fee' => 500,
            'turn_seconds' => 15,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.room.max_players', 2)
            ->assertJsonPath('data.room.member_count', 1)
            ->assertJsonPath('data.room.type', 'team')
            ->assertJsonPath('data.room.players.0.user_id', $user->id)
            ->assertJsonPath('data.room.players.0.seat_position', 1)
            ->assertJsonPath('data.room.players.0.color', 'red')
            ->assertJsonPath('data.room.players.0.is_host', true);
    }

    public function test_create_rejects_invalid_fee(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/team-rooms/create', [
            'entry_fee' => 777,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_join_team_room_happy_path(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $host->wallet->update(['coins_balance' => 1000]);
        $guest->wallet->update(['coins_balance' => 1000]);

        $createRes = $this->actingAs($host)->postJson('/api/v1/team-rooms/create', [
            'entry_fee' => 500,
        ]);
        $code = $createRes->json('data.room.room_code');

        $joinRes = $this->actingAs($guest)->postJson('/api/v1/team-rooms/join', [
            'code' => strtolower($code),
        ]);

        $joinRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.room.member_count', 2)
            ->assertJsonPath('data.room.players.1.user_id', $guest->id)
            ->assertJsonPath('data.room.players.1.seat_position', 2)
            ->assertJsonPath('data.room.players.1.color', 'yellow')
            ->assertJsonPath('data.room.players.1.is_host', false);
    }

    public function test_join_rejects_full_team_room(): void
    {
        $host = User::factory()->create();
        $guest1 = User::factory()->create();
        $guest2 = User::factory()->create();

        $createRes = $this->actingAs($host)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 0]);
        $code = $createRes->json('data.room.room_code');

        $this->actingAs($guest1)->postJson('/api/v1/team-rooms/join', ['code' => $code]);

        $res2 = $this->actingAs($guest2)->postJson('/api/v1/team-rooms/join', ['code' => $code]);
        $res2->assertStatus(409)
            ->assertJsonPath('message', 'Room is full');
    }

    public function test_cannot_create_or_join_when_already_in_team_room(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 0]);

        $res2 = $this->actingAs($user)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 0]);
        $res2->assertStatus(409)
            ->assertJsonPath('message', 'User is already in an active room');
    }

    public function test_cross_type_active_room_isolation(): void
    {
        $user = User::factory()->create();

        // 1. User creates a Private room
        $this->actingAs($user)->postJson('/api/v1/private-rooms', ['max_players' => 2, 'entry_fee' => 0]);

        // 2. User tries to create a Team room while active in Private room -> rejected with 409
        $teamRes = $this->actingAs($user)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 0]);
        $teamRes->assertStatus(409)
            ->assertJsonPath('message', 'User is already in an active room');
    }

    public function test_ready_toggle_by_guest_updates_can_start(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $createRes = $this->actingAs($host)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 0]);
        $code = $createRes->json('data.room.room_code');

        $this->actingAs($guest)->postJson('/api/v1/team-rooms/join', ['code' => $code]);

        $currentHost = $this->actingAs($host)->getJson('/api/v1/team-rooms/current');
        $currentHost->assertJsonPath('data.room.can_start', false);

        $this->actingAs($guest)->postJson('/api/v1/team-rooms/ready', ['is_ready' => true]);

        $currentHost2 = $this->actingAs($host)->getJson('/api/v1/team-rooms/current');
        $currentHost2->assertJsonPath('data.room.can_start', true);
    }

    public function test_leave_semantics_host_disbands_guest_removes(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $createRes = $this->actingAs($host)->postJson('/api/v1/team-rooms/create', ['entry_fee' => 0]);
        $code = $createRes->json('data.room.room_code');

        $this->actingAs($guest)->postJson('/api/v1/team-rooms/join', ['code' => $code]);

        // Guest leaves -> room stays waiting, member_count becomes 1
        $guestLeaveRes = $this->actingAs($guest)->postJson('/api/v1/team-rooms/leave');
        $guestLeaveRes->assertStatus(200)
            ->assertJsonPath('data.action', 'left');

        $this->assertDatabaseHas('rooms', ['room_code' => $code, 'member_count' => 1, 'status' => 'waiting']);

        // Host leaves -> room disbands (status cancelled)
        $hostLeaveRes = $this->actingAs($host)->postJson('/api/v1/team-rooms/leave');
        $hostLeaveRes->assertStatus(200)
            ->assertJsonPath('data.action', 'disbanded');

        $this->assertDatabaseHas('rooms', ['room_code' => $code, 'status' => 'cancelled']);
    }
}
