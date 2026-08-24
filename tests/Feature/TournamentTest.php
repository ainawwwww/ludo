<?php

namespace Tests\Feature;

use App\Events\TournamentLevelReached;
use App\Events\TournamentMatchFound;
use App\Models\Game;
use App\Models\Room;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TournamentService;
use Database\Seeders\LeagueSeeder;
use Database\Seeders\TournamentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TournamentTest extends TestCase
{
    use RefreshDatabase;

    protected Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        $this->seed(TournamentSeeder::class);

        Cache::flush();

        $this->tournament = Tournament::where('name', 'Classic-600')->first();
    }

    public function test_joining_tournament_deducts_entry_fee_and_creates_participant_at_level_1(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000]);

        $response = $this->actingAs($user)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.queue_position', 1);

        // Balance deducted from 1000 to 400
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'coins_balance' => 400,
        ]);

        // Participant record created at level 1
        $this->assertDatabaseHas('tournament_participants', [
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'current_level' => 1,
            'highest_level_reached' => 1,
            'status' => 'active',
        ]);

        // Transaction record created
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'entry_fee',
            'amount' => -600,
        ]);
    }

    public function test_joining_with_insufficient_balance_fails_with_400(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 100]);

        $response = $this->actingAs($user)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Insufficient balance');

        // No participant record created
        $this->assertDatabaseMissing('tournament_participants', [
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
        ]);

        // Wallet balance untouched
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'coins_balance' => 100,
        ]);
    }

    public function test_joining_active_tournament_twice_fails_with_422(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 5000]);

        // First join succeeds
        $this->actingAs($user)->postJson("/api/v1/tournaments/{$this->tournament->id}/join")
            ->assertStatus(200);

        // Second join attempt returns 422
        $response = $this->actingAs($user)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'User already has an active participation in this tournament');
    }

    public function test_two_participants_queued_for_same_level_get_matched(): void
    {
        Event::fake([TournamentMatchFound::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1->wallet->update(['coins_balance' => 1000]);
        $user2->wallet->update(['coins_balance' => 1000]);

        // User 1 joins -> waiting
        $res1 = $this->actingAs($user1)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");
        $res1->assertStatus(200)->assertJsonPath('data.status', 'waiting');

        // User 2 joins -> matched
        $res2 = $this->actingAs($user2)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");
        $res2->assertStatus(200)->assertJsonPath('data.status', 'matched');

        $roomId = $res2->json('data.room_id');
        $this->assertNotNull($roomId);

        // TournamentMatch record created
        $this->assertDatabaseHas('tournament_matches', [
            'tournament_id' => $this->tournament->id,
            'level' => 1,
            'room_id' => $roomId,
            'player1_id' => $user1->id,
            'player2_id' => $user2->id,
            'status' => 'in_progress',
        ]);

        Event::assertDispatched(TournamentMatchFound::class, 2);
    }

    public function test_winning_match_advances_winner_and_loser_stays_at_current_level(): void
    {
        Event::fake([TournamentLevelReached::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1->wallet->update(['coins_balance' => 1000]);
        $user2->wallet->update(['coins_balance' => 1000]);

        $this->actingAs($user1)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");
        $res2 = $this->actingAs($user2)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");
        $roomId = $res2->json('data.room_id');

        // Process match result (User 1 wins)
        app(TournamentService::class)->processMatchResult($roomId, $user1->id);

        // Match marked completed
        $this->assertDatabaseHas('tournament_matches', [
            'room_id' => $roomId,
            'winner_id' => $user1->id,
            'status' => 'completed',
        ]);

        // Winner advanced to level 2
        $this->assertDatabaseHas('tournament_participants', [
            'tournament_id' => $this->tournament->id,
            'user_id' => $user1->id,
            'current_level' => 2,
            'highest_level_reached' => 2,
            'status' => 'active',
        ]);

        // Loser stays at level 1
        $this->assertDatabaseHas('tournament_participants', [
            'tournament_id' => $this->tournament->id,
            'user_id' => $user2->id,
            'current_level' => 1,
            'highest_level_reached' => 1,
            'status' => 'active',
        ]);

        Event::assertDispatched(TournamentLevelReached::class, function ($e) use ($user1) {
            return $e->userId === $user1->id && $e->newLevel === 2 && !$e->isFinalLevel;
        });
    }

    public function test_reaching_max_level_marks_participant_completed_and_credits_reward(): void
    {
        Event::fake([TournamentLevelReached::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1->wallet->update(['coins_balance' => 1000]);
        $user2->wallet->update(['coins_balance' => 1000]);

        // Set user 1 to level 5 (max_level is 6)
        $p1 = TournamentParticipant::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user1->id,
            'current_level' => 5,
            'highest_level_reached' => 5,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Set user 2 to level 5
        $p2 = TournamentParticipant::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user2->id,
            'current_level' => 5,
            'highest_level_reached' => 5,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Queue both users via continue endpoint
        $this->actingAs($user1)->postJson("/api/v1/tournaments/{$this->tournament->id}/continue");
        $res2 = $this->actingAs($user2)->postJson("/api/v1/tournaments/{$this->tournament->id}/continue");
        $roomId = $res2->json('data.room_id');

        // User 1 wins level 5 match -> advances to max_level 6
        app(TournamentService::class)->processMatchResult($roomId, $user1->id);

        // Winner marked completed and reached level 6
        $this->assertDatabaseHas('tournament_participants', [
            'tournament_id' => $this->tournament->id,
            'user_id' => $user1->id,
            'current_level' => 6,
            'highest_level_reached' => 6,
            'status' => 'completed',
        ]);

        // Level 6 reward in seeder is 600 coins -> credited to wallet (1000 + 600 = 1600)
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user1->id,
            'coins_balance' => 1600,
        ]);

        // Transaction record created for reward
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user1->id,
            'type' => 'win',
            'amount' => 600,
        ]);

        Event::assertDispatched(TournamentLevelReached::class, function ($e) use ($user1) {
            return $e->userId === $user1->id && $e->newLevel === 6 && $e->isFinalLevel === true;
        });
    }

    public function test_completed_participant_cannot_continue_without_rejoining_and_rejoin_preserves_highest_level(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 5000]);

        // Mark participant as completed with lifetime highest level 6
        TournamentParticipant::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'current_level' => 6,
            'highest_level_reached' => 6,
            'status' => 'completed',
            'joined_at' => now(),
        ]);

        // Calling /continue fails with 404 because no ACTIVE participation exists
        $contRes = $this->actingAs($user)->postJson("/api/v1/tournaments/{$this->tournament->id}/continue");
        $contRes->assertStatus(404)
            ->assertJsonPath('message', 'No active participation found for this user in this tournament');

        // Calling /join pays entry fee, resets current_level to 1, but keeps highest_level_reached at 6
        $joinRes = $this->actingAs($user)->postJson("/api/v1/tournaments/{$this->tournament->id}/join");
        $joinRes->assertStatus(200);

        $this->assertDatabaseHas('tournament_participants', [
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'current_level' => 1,
            'highest_level_reached' => 6,
            'status' => 'active',
        ]);
    }

    public function test_normal_game_win_does_not_trigger_tournament_progression(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Standard non-tournament room
        $room = Room::create([
            'room_code' => 'NORMAL1',
            'type' => 'public',
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => 'playing',
            'created_by' => $user1->id,
        ]);

        // Call processMatchResult with a normal room ID (not in tournament_matches)
        app(TournamentService::class)->processMatchResult($room->id, $user1->id);

        // Verify no tournament_matches or tournament_participants records created or affected
        $this->assertDatabaseCount('tournament_matches', 0);
        $this->assertDatabaseCount('tournament_participants', 0);
    }
}
