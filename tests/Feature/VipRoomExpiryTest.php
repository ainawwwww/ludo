<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class VipRoomExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_vip_waiting_room_idle_past_ttl_gets_cancelled(): void
    {
        Event::fake([\App\Events\PrivateRoomUpdated::class]);

        $host = User::factory()->create();
        $room = Room::create([
            'room_code' => 'VIPSTALE',
            'title' => 'Stale VIP Room',
            'type' => RoomType::VIP->value,
            'max_players' => 2,
            'entry_fee' => 5000,
            'status' => RoomStatus::WAITING->value,
            'created_by' => $host->id,
            'updated_at' => now()->subMinutes(35),
        ]);

        $this->artisan('private-rooms:expire')->assertExitCode(0);

        $this->assertEquals(RoomStatus::CANCELLED->value, $room->fresh()->status->value);
        Event::assertDispatched(\App\Events\PrivateRoomUpdated::class);
    }

    public function test_vip_playing_room_stuck_past_threshold_gets_finished_and_user_can_rejoin(): void
    {
        Event::fake([\App\Events\PrivateRoomUpdated::class]);

        $host = User::factory()->create();
        $host->wallet->update(['coins_balance' => 50000]);

        $room = Room::create([
            'room_code' => 'VIPSTUCK',
            'title' => 'Stuck VIP Room',
            'type' => RoomType::VIP->value,
            'max_players' => 2,
            'entry_fee' => 5000,
            'status' => RoomStatus::PLAYING->value,
            'created_by' => $host->id,
            'updated_at' => now()->subHours(4),
        ]);

        RoomPlayer::create([
            'room_id' => $room->id,
            'user_id' => $host->id,
            'seat_position' => 1,
            'color' => 'red',
            'is_ready' => true,
            'joined_at' => now(),
        ]);

        Game::create([
            'room_id' => $room->id,
            'status' => GameStatus::COMPLETED->value,
            'started_at' => now()->subHours(4),
            'ended_at' => now()->subHours(3),
        ]);

        $this->artisan('private-rooms:expire-stuck')->assertExitCode(0);

        $this->assertEquals(RoomStatus::FINISHED->value, $room->fresh()->status->value);

        // Host is no longer trapped and can create a new VIP room
        \App\Models\Subscription::create([
            'user_id' => $host->id,
            'tier' => \App\Enums\SubscriptionTier::KNIGHT,
            'status' => \App\Enums\SubscriptionStatus::ACTIVE,
            'started_at' => now(),
            'current_period_end' => now()->addDays(30),
            'last_daily_reward_at' => now(),
            'auto_renew' => true,
        ]);

        $createRes = $this->actingAs($host)->postJson('/api/v1/vip-rooms', [
            'max_players' => 2,
            'entry_fee' => 10000,
            'turn_seconds' => 15,
        ]);
        $createRes->assertStatus(201);
    }
}
