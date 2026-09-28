<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\GameEnded;
use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use App\Services\PrivateRoomService;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoomLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected PrivateRoomService $roomService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Queue::fake([ProcessTurnTimeout::class]);
        Event::fake([TurnChanged::class, GameEnded::class]);
        $this->roomService = app(PrivateRoomService::class);
    }

    /**
     * Test 4a: Room active 31 minutes after creation is NOT cancelled; an idle one is.
     */
    public function test_room_active_31_minutes_after_creation_is_not_cancelled_idle_one_is(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Idle room: created 35 mins ago, updated 35 mins ago
        $idleRoom = Room::create([
            'room_code' => 'IDLE01',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::WAITING->value,
            'state_version' => 1,
            'created_by' => $user1->id,
            'created_at' => now()->subMinutes(35),
        ]);
        Room::where('id', $idleRoom->id)->update(['updated_at' => now()->subMinutes(35)]);

        // Active room: created 31 mins ago, but updated 5 mins ago (e.g. recent join/ready)
        $activeRoom = Room::create([
            'room_code' => 'ACTV01',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::WAITING->value,
            'state_version' => 2,
            'created_by' => $user2->id,
            'created_at' => now()->subMinutes(31),
        ]);
        Room::where('id', $activeRoom->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('private-rooms:expire')->assertSuccessful();

        // Idle room is cancelled and state_version incremented
        $this->assertEquals(RoomStatus::CANCELLED, $idleRoom->fresh()->status);
        $this->assertEquals(2, $idleRoom->fresh()->state_version);

        // Active room is NOT cancelled
        $this->assertEquals(RoomStatus::WAITING, $activeRoom->fresh()->status);
        $this->assertEquals(2, $activeRoom->fresh()->state_version);
    }

    /**
     * Test 4c: Expire stuck command finishes playing rooms whose game completed or are inactive.
     */
    public function test_expire_stuck_command_finishes_rooms_with_completed_games_or_long_inactivity(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // Room 1: Playing, but its game has status=completed
        $roomWithCompletedGame = Room::create([
            'room_code' => 'STCK01',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::PLAYING->value,
            'state_version' => 3,
            'created_by' => $user1->id,
        ]);
        Game::create([
            'room_id' => $roomWithCompletedGame->id,
            'status' => GameStatus::COMPLETED->value,
            'started_at' => now()->subHours(1),
            'ended_at' => now()->subMinutes(30),
        ]);

        // Room 2: Playing, no activity for > 3 hours
        $inactivePlayingRoom = Room::create([
            'room_code' => 'STCK02',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::PLAYING->value,
            'state_version' => 3,
            'created_by' => $user2->id,
            'created_at' => now()->subHours(4),
        ]);
        Room::where('id', $inactivePlayingRoom->id)->update(['updated_at' => now()->subHours(4)]);

        // Room 3: Active playing match with in-progress game updated 10 minutes ago
        $activePlayingRoom = Room::create([
            'room_code' => 'ACTV03',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::PLAYING->value,
            'state_version' => 3,
            'created_by' => $user3->id,
            'created_at' => now()->subMinutes(15),
        ]);
        Game::create([
            'room_id' => $activePlayingRoom->id,
            'status' => GameStatus::IN_PROGRESS->value,
            'started_at' => now()->subMinutes(15),
        ]);
        Room::where('id', $activePlayingRoom->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('private-rooms:expire-stuck')->assertSuccessful();

        // Room 1 finished
        $this->assertEquals(RoomStatus::FINISHED, $roomWithCompletedGame->fresh()->status);
        $this->assertEquals(4, $roomWithCompletedGame->fresh()->state_version);

        // Room 2 finished
        $this->assertEquals(RoomStatus::FINISHED, $inactivePlayingRoom->fresh()->status);
        $this->assertEquals(4, $inactivePlayingRoom->fresh()->state_version);

        // Room 3 still playing
        $this->assertEquals(RoomStatus::PLAYING, $activePlayingRoom->fresh()->status);
        $this->assertEquals(3, $activePlayingRoom->fresh()->state_version);
    }

    /**
     * Test 4c: User whose match is completed is not trapped in getUserActiveRoom and can create new room.
     */
    public function test_user_with_finished_game_is_not_trapped_and_can_create_new_room(): void
    {
        $user = User::factory()->create();

        $oldRoom = Room::create([
            'room_code' => 'OLD001',
            'type' => RoomType::PRIVATE->value,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => RoomStatus::PLAYING->value,
            'state_version' => 3,
            'created_by' => $user->id,
        ]);
        $oldRoom->players()->create([
            'user_id' => $user->id,
            'seat_position' => 1,
            'color' => 'red',
            'is_ready' => true,
        ]);
        Game::create([
            'room_id' => $oldRoom->id,
            'status' => GameStatus::COMPLETED->value,
            'started_at' => now()->subHours(1),
            'ended_at' => now()->subMinutes(10),
        ]);

        // User should not be trapped
        $activeRoom = $this->roomService->getUserActiveRoom($user);
        $this->assertNull($activeRoom);

        // User can create a new room without 409 ALREADY_IN_ROOM
        $res = $this->actingAs($user)->postJson('/api/v1/private-rooms', [
            'max_players' => 2,
            'entry_fee' => 0,
            'turn_seconds' => 15,
        ]);
        $res->assertStatus(201);
    }
}
