<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Events\EventsService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventController extends Controller
{
    protected EventsService $eventsService;

    public function __construct(EventsService $eventsService)
    {
        $this->eventsService = $eventsService;
    }

    /**
     * GET /api/v1/events/daily-tasks
     */
    public function getDailyTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $tasks = $this->eventsService->getDailyTasksForUser($user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'tasks' => $tasks,
                'user_coins' => $user->coins,
                'user_diamonds' => $user->diamonds,
                'is_vip' => $user->is_vip,
            ],
        ]);
    }

    /**
     * POST /api/v1/events/daily-tasks/{id}/claim
     */
    public function claimDailyTask(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requestId = $request->input('request_id')
            ?? $request->header('X-Idempotency-Key')
            ?? (string) Str::uuid();

        try {
            $result = $this->eventsService->claimDailyTask($user, $id, $requestId);
            return response()->json([
                'status' => 'success',
                'message' => 'Daily task reward claimed successfully!',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/v1/events/arrival-chest
     */
    public function getArrivalChest(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $this->eventsService->getArrivalChestStatus($user);

        return response()->json([
            'status' => 'success',
            'data' => $status,
        ]);
    }

    /**
     * POST /api/v1/events/arrival-chest/claim
     */
    public function claimArrivalChest(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestId = $request->input('request_id')
            ?? $request->header('X-Idempotency-Key')
            ?? (string) Str::uuid();

        try {
            $result = $this->eventsService->claimArrivalChest($user, $requestId);
            return response()->json([
                'status' => 'success',
                'message' => 'Arrival chest claimed successfully!',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
