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
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\GameEngine\RedisGameStateStore;
use App\Services\PrivateRoomService;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LegacyGuardsAndResolutionTest extends TestCase
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
     * Legacy POST /rooms ignores any 'type' input and forces type=public.
     */
    public function test_legacy_post_rooms_forces_type_public_even_if_private_requested(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/v1/rooms', [
            'name' => 'Attempted Private Room',
            'type' => 'private',
            'entry_fee' => 0,
            'max_players' => 4,
        ]);

        $res->assertStatus(201);
        $roomId = $res->json('data.id');

        $room = Room::find($roomId);
        $this->assertEquals(RoomType::PUBLIC, $room->type);
        $this->assertNotEquals(RoomType::PRIVATE, $room->type);
    }

    /**
     * Legacy start endpoints reject private rooms with 403.
     */
    public function test_legacy_start_endpoints_reject_private_rooms(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $room = $this->roomService->create($host, 2, 0);
        $this->roomService->join($guest, $room->room_code);

        // POST /game/start rejects private rooms
        $res1 = $this->actingAs($host)->postJson('/api/v1/game/start', [
            'room_id' => $room->id,
        ]);
        $res1->assertStatus(403);
        $this->assertEquals('Private rooms can only be started via private room endpoint', $res1->json('message'));

        // POST /quick-match/start rejects private rooms
        $res2 = $this->actingAs($host)->postJson('/api/v1/quick-match/start', [
            'quick_match_id' => $room->id,
        ]);
        $res2->assertStatus(403);
        $this->assertEquals('Private rooms can only be started via private room endpoint', $res2->json('message'));
    }

    /**
     * Legacy GET /rooms only returns public rooms.
     */
    public function test_legacy_get_rooms_returns_public_rooms_only(): void
    {
        $user = User::factory()->create();

        // Create 1 public room and 1 private room
        $publicRoom = Room::create([
            'room_code' => 'PUB001',
            'created_by' => $user->id,
            'title' => 'Public Lounge',
            'type' => RoomType::PUBLIC->value,
            'status' => RoomStatus::WAITING->value,
            'max_players' => 4,
            'entry_fee' => 0,
        ]);

        $privateRoom = $this->roomService->create($user, 2, 0);

        $res = $this->actingAs($user)->getJson('/api/v1/rooms');
        $res->assertStatus(200);

        $data = $res->json('data');
        $roomIds = collect($data)->pluck('id')->toArray();

        $this->assertContains($publicRoom->id, $roomIds);
        $this->assertNotContains($privateRoom->id, $roomIds);
    }

    /**
     * Legacy joinAsListener, takeSeat, and leaveSeat reject private rooms with 403.
     */
    public function test_legacy_listener_and_seat_endpoints_reject_private_rooms(): void
    {
        $host = User::factory()->create();
        $stranger = User::factory()->create();

        $room = $this->roomService->create($host, 2, 0);

        // joinAsListener rejects
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$room->id}/join")
            ->assertStatus(403);

        // takeSeat rejects
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$room->id}/seat", ['seat_position' => 2])
            ->assertStatus(403);

        // leaveSeat rejects
        $this->actingAs($stranger)->postJson("/api/v1/rooms/{$room->id}/leave-seat")
            ->assertStatus(403);
    }

    /**
     * Chat endpoints reject non-members for private rooms with 403.
     */
    public function test_chat_endpoints_reject_non_members_for_private_rooms(): void
    {
        $host = User::factory()->create();
        $stranger = User::factory()->create();

        $room = $this->roomService->create($host, 2, 0);

        // sendMessage rejects non-member
        $this->actingAs($stranger)->postJson('/api/v1/chat/message', [
            'room_id' => $room->id,
            'message' => 'Hello from outsider',
        ])->assertStatus(403);

        // getMessages rejects non-member
        $this->actingAs($stranger)->getJson("/api/v1/chat/messages?room_id={$room->id}")
            ->assertStatus(403);

        // Member is allowed
        $this->actingAs($host)->postJson('/api/v1/chat/message', [
            'room_id' => $room->id,
            'message' => 'Host greeting',
        ])->assertStatus(200);

        $this->actingAs($host)->getJson("/api/v1/chat/messages?room_id={$room->id}")
            ->assertStatus(200);
    }

    /**
     * Game state GET, rollDice, moveToken, forfeit return 404 for non-participants of private rooms.
     */
    public function test_gameplay_endpoints_return_404_for_non_participants_of_private_rooms(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $stranger = User::factory()->create();

        $room = $this->roomService->create($host, 2, 0);
        $this->roomService->join($guest, $room->room_code);
        $this->roomService->toggleReady($guest, $room, true);
        $this->roomService->start($host, $room);

        // getGameState returns 404 for stranger
        $this->actingAs($stranger)->getJson("/api/v1/game/state?room_id={$room->id}")
            ->assertStatus(404);

        // rollDice returns 404 for stranger
        $this->actingAs($stranger)->postJson('/api/v1/game/roll', [
            'room_id' => $room->id,
        ])->assertStatus(404);

        // moveToken returns 404 for stranger
        $this->actingAs($stranger)->postJson('/api/v1/game/move', [
            'room_id' => $room->id,
            'token_index' => 0,
        ])->assertStatus(404);

        // forfeit returns 404 for stranger
        $this->actingAs($stranger)->postJson('/api/v1/game/forfeit', [
            'room_id' => $room->id,
        ])->assertStatus(404);

        // Participants can access getGameState
        $this->actingAs($host)->getJson("/api/v1/game/state?room_id={$room->id}")
            ->assertStatus(200);
    }

    /**
     * GET /api/v1/private-rooms/{room} resolves by NUMERIC ID ONLY, and non-member gets 404.
     */
    public function test_private_room_show_resolves_numeric_only_and_returns_404_for_non_members(): void
    {
        $host = User::factory()->create();
        $stranger = User::factory()->create();

        $room = $this->roomService->create($host, 2, 0);

        // Member accessing by numeric ID succeeds
        $this->actingAs($host)->getJson("/api/v1/private-rooms/{$room->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $room->id);

        // Member accessing by code is rejected with 404 (numeric only)
        $this->actingAs($host)->getJson("/api/v1/private-rooms/{$room->room_code}")
            ->assertStatus(404)
            ->assertJson(['error_code' => 'ROOM_NOT_FOUND']);

        // Non-member accessing by numeric ID gets 404 (not 403) so existence does not leak
        $this->actingAs($stranger)->getJson("/api/v1/private-rooms/{$room->id}")
            ->assertStatus(404)
            ->assertJson(['error_code' => 'ROOM_NOT_FOUND']);
    }
}
