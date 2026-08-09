<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Game;
use App\Models\League;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserInventory;
use Carbon\Carbon;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_profile_returns_user_info_and_stats(): void
    {
        $user = User::factory()->create([
            'username' => 'profile_user',
            'level' => 3,
            'league_points' => 200,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'id',
                    'name',
                    'level',
                    'avatar_url',
                    'total_games_played',
                    'total_wins',
                    'total_losses',
                    'win_rate',
                    'league_info',
                    'achievements',
                ],
            ])
            ->assertJsonPath('data.name', 'profile_user')
            ->assertJsonPath('data.level', 3);
    }

    public function test_profile_computes_win_rate_correctly(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        // Create 3 completed games: user won 2, lost 1
        for ($i = 0; $i < 3; $i++) {
            $room = Room::create([
                'room_code' => 'WIN' . $i,
                'type' => RoomType::PUBLIC->value,
                'max_players' => 2,
                'entry_fee' => 0,
                'status' => RoomStatus::FINISHED->value,
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            Game::create([
                'room_id' => $room->id,
                'winner_id' => $i < 2 ? $user->id : $otherUser->id,
                'status' => GameStatus::COMPLETED->value,
                'started_at' => now(),
                'ended_at' => now(),
                'created_at' => now(),
            ]);

            RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'seat_position' => 1,
                'color' => 'red',
                'is_ready' => true,
                'joined_at' => now(),
            ]);
            RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $otherUser->id,
                'seat_position' => 2,
                'color' => 'green',
                'is_ready' => true,
                'joined_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/v1/profile');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_games_played', 3)
            ->assertJsonPath('data.total_wins', 2)
            ->assertJsonPath('data.total_losses', 1)
            ->assertJsonPath('data.win_rate', 66.67);
    }

    public function test_profile_league_progress_status(): void
    {
        // Bronze is 0-999. 300 points = 30% = low
        $userLow = User::factory()->create(['league_points' => 300]);
        $response = $this->actingAs($userLow)->getJson('/api/v1/profile');
        $response->assertJsonPath('data.league_info.progress_status', 'low');

        // 500 points = 50% = mid
        $userMid = User::factory()->create(['league_points' => 500]);
        $response = $this->actingAs($userMid)->getJson('/api/v1/profile');
        $response->assertJsonPath('data.league_info.progress_status', 'mid');

        // 800 points = 80% = high
        $userHigh = User::factory()->create(['league_points' => 800]);
        $response = $this->actingAs($userHigh)->getJson('/api/v1/profile');
        $response->assertJsonPath('data.league_info.progress_status', 'high');
    }

    public function test_profile_update_name_change_limit(): void
    {
        $user = User::factory()->create(['username' => 'original_name']);

        // First 3 name changes should succeed
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->actingAs($user->fresh())->putJson('/api/v1/profile', [
                'name' => "new_name_{$i}",
            ]);
            $response->assertStatus(200)
                ->assertJsonPath('status', 'success');
        }

        // 4th name change within 24 hours should fail
        $response = $this->actingAs($user->fresh())->putJson('/api/v1/profile', [
            'name' => 'blocked_name',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('message', 'Name can only be changed 3 times per day');

        // Verify the name was NOT changed
        $this->assertNotEquals('blocked_name', $user->fresh()->username);
    }

    public function test_profile_update_name_resets_after_24_hours(): void
    {
        $user = User::factory()->create(['username' => 'time_travel_user']);

        // Use up 3 name changes
        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($user->fresh())->putJson('/api/v1/profile', [
                'name' => "changed_{$i}",
            ]);
        }

        // 4th should fail
        $response = $this->actingAs($user->fresh())->putJson('/api/v1/profile', [
            'name' => 'should_fail',
        ]);
        $response->assertStatus(422);

        // Time travel 25 hours ahead — counter should reset
        Carbon::setTestNow(Carbon::now()->addHours(25));

        $response = $this->actingAs($user->fresh())->putJson('/api/v1/profile', [
            'name' => 'after_reset',
        ]);
        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'after_reset');

        Carbon::setTestNow(); // Reset time
    }

    public function test_avatar_upload_rejects_non_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->putJson('/api/v1/profile', [
            'avatar' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_avatar_upload_rejects_oversized_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        // Create a 3MB file with jpg MIME type (exceeds 2MB limit)
        $file = UploadedFile::fake()->create('large.jpg', 3072, 'image/jpeg');

        $response = $this->actingAs($user)->putJson('/api/v1/profile', [
            'avatar' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_avatar_upload_accepts_valid_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('avatar.png', 512, 'image/png');

        $response = $this->actingAs($user)->putJson('/api/v1/profile', [
            'avatar' => $file,
        ]);

        $response->assertStatus(200);

        $updatedUser = $user->fresh();
        $this->assertNotNull($updatedUser->avatar_url);
        $this->assertStringContainsString('avatars/', $updatedUser->avatar_url);
    }

    public function test_profile_update_gender_and_bio(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/profile', [
            'gender' => 'male',
            'bio' => 'I love playing Ludo!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.bio', 'I love playing Ludo!');
    }

    public function test_profile_dob_rejects_underage(): void
    {
        $user = User::factory()->create();

        // Set DOB to 5 years ago (under 13)
        $response = $this->actingAs($user)->putJson('/api/v1/profile', [
            'dob' => now()->subYears(5)->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('dob');
    }

    public function test_profile_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/profile');
        $response->assertStatus(401);
    }
}
