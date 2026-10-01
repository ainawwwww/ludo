<?php

namespace Tests\Feature;

use App\Events\MatchFound;
use App\Events\PrivateRoomUpdated;
use App\Events\TeamMatchFound;
use App\Events\TeamPartnerJoined;
use App\Jobs\ProcessTurnTimeout;
use App\Models\User;
use App\Models\Wallet;
use App\Services\MatchmakingService;
use App\Services\TeamLobbyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TeamRealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    protected TeamLobbyService $teamLobbyService;
    protected MatchmakingService $matchmakingService;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ProcessTurnTimeout::class]);
        $this->teamLobbyService = app(TeamLobbyService::class);
        $this->matchmakingService = app(MatchmakingService::class);
    }

    public function test_team_partner_joined_event_dispatches_when_guest_joins_lobby(): void
    {
        Event::fake([TeamPartnerJoined::class, PrivateRoomUpdated::class]);

        $host = User::factory()->create();
        Wallet::create(['user_id' => $host->id, 'coins_balance' => 2000]);

        $guest = User::factory()->create();
        Wallet::create(['user_id' => $guest->id, 'coins_balance' => 2000]);

        $room = $this->teamLobbyService->create($host, 1000);

        $this->teamLobbyService->join($guest, $room->room_code);

        Event::assertDispatched(TeamPartnerJoined::class, function ($event) use ($room, $guest) {
            return (int) $event->roomId === (int) $room->id
                && (int) $event->joinedUserId === (int) $guest->id
                && $event->seatPosition === 2
                && $event->color === 'yellow';
        });
    }

    public function test_team_match_found_event_dispatches_for_all_4_players_when_match_formed(): void
    {
        Event::fake([MatchFound::class, TeamMatchFound::class]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();
        $u4 = User::factory()->create();

        foreach ([$u1, $u2, $u3, $u4] as $u) {
            Wallet::create(['user_id' => $u->id, 'coins_balance' => 2000]);
        }

        // 4 solo players queueing for 2v2 team match
        $this->matchmakingService->joinTeamSolo($u1, 1000);
        $this->matchmakingService->joinTeamSolo($u2, 1000);
        $this->matchmakingService->joinTeamSolo($u3, 1000);
        $result = $this->matchmakingService->joinTeamSolo($u4, 1000);

        $this->assertEquals('matched', $result['status']);

        // Assert TeamMatchFound dispatched for all 4 users
        foreach ([$u1, $u2, $u3, $u4] as $u) {
            Event::assertDispatched(TeamMatchFound::class, function ($event) use ($u) {
                return (int) $event->userId === (int) $u->id;
            });
        }
    }
}
