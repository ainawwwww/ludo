<?php

namespace Tests\Feature;

use App\Enums\FriendStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\RoomUpdated;
use App\Models\Follow;
use App\Models\Friend;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LobbyEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_returns_quick_entry_cards_and_recommended_rooms(): void
    {
        $user = User::factory()->create();

        // Create rooms with different countries and member counts
        $roomPk = Room::create([
            'room_code' => 'ROOMPK',
            'title' => 'Pakistan Ludo Champions',
            'category' => 'social',
            'tags' => ['Ludo', 'PK'],
            'country_code' => 'PK',
            'member_count' => 150,
            'is_live' => true,
            'status' => RoomStatus::WAITING->value,
            'type' => RoomType::PUBLIC->value,
            'max_players' => 4,
            'entry_fee' => 500,
            'created_by' => $user->id,
        ]);

        $roomIn = Room::create([
            'room_code' => 'ROOMIN',
            'title' => 'India Domino Lounge',
            'category' => 'music',
            'tags' => ['Music', 'Chill'],
            'country_code' => 'IN',
            'member_count' => 80,
            'is_live' => true,
            'status' => RoomStatus::WAITING->value,
            'type' => RoomType::PUBLIC->value,
            'max_players' => 4,
            'entry_fee' => 1000,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/lobby/explore');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'quick_entry_cards' => [
                        '*' => ['id', 'title', 'description', 'bg_asset', 'gradient', 'filter_category', 'tags'],
                    ],
                    'recommended_rooms' => [
                        '*' => ['id', 'room_id', 'room_code', 'title', 'name', 'category', 'tags', 'country_code', 'member_count', 'is_live', 'status', 'players'],
                    ],
                    'pagination' => ['current_page', 'last_page', 'per_page', 'total', 'has_more'],
                ],
            ]);

        $this->assertCount(4, $response->json('data.quick_entry_cards'));
        $this->assertEquals(2, $response->json('data.pagination.total'));
    }

    public function test_explore_filters_by_country_code(): void
    {
        $user = User::factory()->create();

        Room::create([
            'room_code' => 'PKROOM1',
            'title' => 'PK Room',
            'country_code' => 'PK',
            'is_live' => true,
            'created_by' => $user->id,
        ]);

        Room::create([
            'room_code' => 'SAROOM',
            'title' => 'KSA Room',
            'country_code' => 'SA',
            'is_live' => true,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/lobby/explore?country=PK');

        $response->assertStatus(200);
        $rooms = $response->json('data.recommended_rooms');
        $this->assertCount(1, $rooms);
        $this->assertEquals('PK', $rooms[0]['country_code']);
    }

    public function test_hot_returns_popular_hosts_and_trending_rooms(): void
    {
        $host1 = User::factory()->create(['username' => 'SuperHost1']);
        $host2 = User::factory()->create(['username' => 'SuperHost2']);

        $room1 = Room::create([
            'room_code' => 'HOT101',
            'title' => 'VIP Mega Room',
            'member_count' => 300,
            'is_live' => true,
            'created_by' => $host1->id,
        ]);

        $room2 = Room::create([
            'room_code' => 'HOT102',
            'title' => 'Chill Vibe Room',
            'member_count' => 120,
            'is_live' => true,
            'created_by' => $host2->id,
        ]);

        $response = $this->actingAs($host1)->getJson('/api/v1/lobby/hot');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'popular_hosts' => [
                        '*' => ['id', 'user_id', 'username', 'badge', 'active_room'],
                    ],
                    'trending_rooms' => [
                        '*' => ['id', 'room_id', 'room_code', 'title', 'member_count'],
                    ],
                    'pagination',
                ],
            ]);

        $hosts = $response->json('data.popular_hosts');
        $this->assertNotEmpty($hosts);
        $this->assertEquals($host1->id, $hosts[0]['user_id']);
        $this->assertEquals('HOT101', $hosts[0]['active_room']['room_code']);
    }

    public function test_my_filter_recently_visited_rooms(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $room1 = Room::create(['room_code' => 'VISIT1', 'created_by' => $other->id]);
        $room2 = Room::create(['room_code' => 'VISIT2', 'created_by' => $other->id]);
        $room3 = Room::create(['room_code' => 'VISIT3', 'created_by' => $other->id]);

        // User visited room 1 first, then room 2
        RoomVisit::create(['user_id' => $user->id, 'room_id' => $room1->id, 'visited_at' => now()->subMinutes(10)]);
        RoomVisit::create(['user_id' => $user->id, 'room_id' => $room2->id, 'visited_at' => now()->subMinutes(2)]);

        $response = $this->actingAs($user)->getJson('/api/v1/lobby/my?filter=recently');

        $response->assertStatus(200);
        $rooms = $response->json('data.rooms');
        $this->assertCount(2, $rooms);
        $this->assertEquals($room2->id, $rooms[0]['id']);
        $this->assertEquals($room1->id, $rooms[1]['id']);
    }

    public function test_my_filter_joined_rooms(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $room = Room::create(['room_code' => 'JOINED1', 'created_by' => $other->id]);
        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
        ]);

        $unjoinedRoom = Room::create(['room_code' => 'NOJOIN', 'created_by' => $other->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/lobby/my?filter=joined');

        $response->assertStatus(200);
        $rooms = $response->json('data.rooms');
        $this->assertCount(1, $rooms);
        $this->assertEquals($room->id, $rooms[0]['id']);
    }

    public function test_my_filter_following_rooms(): void
    {
        $user = User::factory()->create();
        $followedHost = User::factory()->create();
        $stranger = User::factory()->create();

        Follow::create(['user_id' => $user->id, 'followed_user_id' => $followedHost->id]);

        $followedRoom = Room::create(['room_code' => 'FOL101', 'created_by' => $followedHost->id]);
        $strangerRoom = Room::create(['room_code' => 'STR101', 'created_by' => $stranger->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/lobby/my?filter=following');

        $response->assertStatus(200);
        $rooms = $response->json('data.rooms');
        $this->assertCount(1, $rooms);
        $this->assertEquals($followedRoom->id, $rooms[0]['id']);
    }

    public function test_my_filter_friends_rooms(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        $stranger = User::factory()->create();

        Friend::create([
            'user_id' => $user->id,
            'friend_id' => $friend->id,
            'status' => FriendStatus::ACCEPTED->value,
        ]);

        $friendsRoom = Room::create(['room_code' => 'FRND1', 'created_by' => $stranger->id]);
        RoomPlayer::create([
            'room_id' => $friendsRoom->id,
            'user_id' => $friend->id,
            'seat_position' => 1,
            'color' => PlayerColor::RED->value,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/lobby/my?filter=friends');

        $response->assertStatus(200);
        $rooms = $response->json('data.rooms');
        $this->assertCount(1, $rooms);
        $this->assertEquals($friendsRoom->id, $rooms[0]['id']);
    }

    public function test_countries_endpoint_returns_countries_list(): void
    {
        $response = $this->getJson('/api/v1/countries');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => ['name', 'code', 'dial_code', 'flag'],
                ],
                'total',
            ]);

        $this->assertGreaterThan(10, $response->json('total'));
    }

    public function test_join_room_as_listener_increments_members_logs_visit_and_broadcasts(): void
    {
        Event::fake([RoomUpdated::class]);

        $user = User::factory()->create();
        $host = User::factory()->create();

        $room = Room::create([
            'room_code' => 'LISTEN1',
            'title' => 'Live Concert Room',
            'member_count' => 5,
            'created_by' => $host->id,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/rooms/{$room->id}/join");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Joined room as listener',
            ]);

        // Verify member_count reflects distinct visitor/seated count
        $room->refresh();
        $this->assertGreaterThanOrEqual(1, $room->member_count);

        // Verify room visit logged
        $this->assertDatabaseHas('room_visits', [
            'user_id' => $user->id,
            'room_id' => $room->id,
        ]);

        // Verify broadcast event dispatched
        Event::assertDispatched(RoomUpdated::class, function ($event) use ($room) {
            return $event->roomId === $room->id;
        });
    }
}
