<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Services\TournamentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    public function __construct(
        private TournamentService $tournamentService
    ) {}

    /**
     * GET /api/v1/tournaments
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $tournaments = Tournament::with(['levels'])
            ->withCount('participants')
            ->where('status', 'active')
            ->orderBy('entry_fee')
            ->get();

        $userParticipations = TournamentParticipant::where('user_id', $user->id)
            ->whereIn('tournament_id', $tournaments->pluck('id'))
            ->get()
            ->keyBy('tournament_id');

        $data = $tournaments->map(function ($tournament) use ($userParticipations) {
            $p = $userParticipations->get($tournament->id);
            return [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'mode' => $tournament->mode,
                'entry_fee' => $tournament->entry_fee,
                'currency_type' => $tournament->currency_type,
                'prize_pool' => $tournament->prize_pool,
                'max_level' => $tournament->max_level,
                'unlock_level' => $tournament->unlock_level ?? 1,
                'participant_count' => max($tournament->participants_count ?? 0, 32),
                'status' => $tournament->status,
                'levels' => $tournament->levels->map(fn($lvl) => [
                    'level' => $lvl->level,
                    'reward_coins' => $lvl->reward_coins,
                    'reward_diamonds' => $lvl->reward_diamonds,
                ]),
                'user_participation' => $p ? [
                    'is_active' => $p->status === 'active',
                    'current_level' => $p->current_level,
                    'highest_level_reached' => $p->highest_level_reached,
                    'status' => $p->status,
                    'is_claimed' => (bool) ($p->is_claimed ?? false),
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/v1/tournaments/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $tournament = Tournament::with('levels')->find($id);

        if (!$tournament || $tournament->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Tournament not found or inactive',
            ], 404);
        }

        $p = TournamentParticipant::where('tournament_id', $id)
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'mode' => $tournament->mode,
                'entry_fee' => $tournament->entry_fee,
                'currency_type' => $tournament->currency_type,
                'prize_pool' => $tournament->prize_pool,
                'max_level' => $tournament->max_level,
                'status' => $tournament->status,
                'levels' => $tournament->levels->map(fn($lvl) => [
                    'level' => $lvl->level,
                    'reward_coins' => $lvl->reward_coins,
                    'reward_diamonds' => $lvl->reward_diamonds,
                ]),
                'user_participation' => $p ? [
                    'is_active' => $p->status === 'active',
                    'current_level' => $p->current_level,
                    'highest_level_reached' => $p->highest_level_reached,
                    'status' => $p->status,
                ] : null,
            ],
        ]);
    }

    /**
     * POST /api/v1/tournaments/{id}/join
     */
    public function join(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $result = $this->tournamentService->join($user, $id);

        if (isset($result['status']) && $result['status'] === 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
            ], $result['code'] ?? 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/v1/tournaments/{id}/continue
     */
    public function continueMatch(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $result = $this->tournamentService->continueMatch($user, $id);

        if (isset($result['status']) && $result['status'] === 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
            ], $result['code'] ?? 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/v1/tournaments/{id}/leave
     */
    public function leave(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $result = $this->tournamentService->leaveQueue($user, $id);

        return response()->json([
            'status' => 'success',
            'message' => $result['message'],
        ]);
    }

    /**
     * GET /api/v1/tournaments/{id}/progress
     */
    public function progress(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $p = TournamentParticipant::where('tournament_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$p) {
            return response()->json([
                'status' => 'error',
                'message' => 'No participation record found for this tournament',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'current_level' => $p->current_level,
                'highest_level_reached' => $p->highest_level_reached,
                'status' => $p->status,
                'joined_at' => $p->joined_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/tournaments/my-history
     */
    public function myHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $history = $this->tournamentService->getUserTournamentHistory($user);

        return response()->json([
            'status' => 'success',
            'data' => $history,
        ]);
    }

    /**
     * POST /api/v1/tournaments/{id}/claim
     */
    public function claim(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $result = $this->tournamentService->claimPrize($user, $id);

        if (isset($result['status']) && in_array($result['status'], ['error', 'already_claimed'])) {
            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'],
            ], $result['code'] ?? 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/v1/tournaments/winners
     */
    public function winners(): JsonResponse
    {
        $winners = TournamentParticipant::with(['user:id,username', 'tournament:id,name,mode,prize_pool'])
            ->where('highest_level_reached', '>=', 6)
            ->orWhere('status', 'champion')
            ->latest('updated_at')
            ->limit(10)
            ->get();

        $data = $winners->map(function ($w) {
            return [
                'username' => $w->user->username ?? 'Player',
                'prize_gold' => $w->tournament->prize_pool ?? 1000000,
                'tournament_title' => $w->tournament->name ?? 'Classic Tournament',
                'mode' => $w->tournament->mode ?? 'classic',
                'round_reached' => $w->highest_level_reached ?? 6,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }
}
