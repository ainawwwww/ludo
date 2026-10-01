<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubscriptionTier;
use App\Exceptions\SubscriptionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * GET /api/v1/subscription/plans
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => [
                [
                    'tier' => SubscriptionTier::KNIGHT->value,
                    'title' => 'KNIGHT PASS',
                    'price' => SubscriptionTier::KNIGHT->price(),
                    'currency' => 'USD',
                    'daily_rewards' => [
                        'coins' => SubscriptionTier::KNIGHT->dailyCoins(),
                        'diamonds' => SubscriptionTier::KNIGHT->dailyDiamonds(),
                    ],
                ],
                [
                    'tier' => SubscriptionTier::BARON->value,
                    'title' => 'BARON PASS',
                    'price' => SubscriptionTier::BARON->price(),
                    'currency' => 'USD',
                    'daily_rewards' => [
                        'coins' => SubscriptionTier::BARON->dailyCoins(),
                        'diamonds' => SubscriptionTier::BARON->dailyDiamonds(),
                    ],
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/subscription/current
     */
    public function current(Request $request): JsonResponse
    {
        $subscription = $this->subscriptionService->current($request->user());

        if (!$subscription) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * POST /api/v1/subscription/checkout
     */
    public function checkout(CheckoutSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        $tier = SubscriptionTier::from($request->validated('tier'));
        $forceFailure = $request->header('X-Dummy-Payment-Force-Failure') === '1'
            || $request->boolean('force_failure');

        try {
            $subscription = $this->subscriptionService->checkout($user, $tier, $forceFailure);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription activated successfully.',
                'data' => new SubscriptionResource($subscription),
            ]);
        } catch (SubscriptionException $e) {
            return response()->json([
                'error_code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * POST /api/v1/subscription/cancel
     */
    public function cancel(Request $request): JsonResponse
    {
        try {
            $subscription = $this->subscriptionService->cancel($request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription auto-renewal cancelled. Pass remains active until current period end.',
                'data' => new SubscriptionResource($subscription),
            ]);
        } catch (SubscriptionException $e) {
            return response()->json([
                'error_code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * POST /api/v1/subscription/claim-daily-reward
     */
    public function claimDailyReward(Request $request): JsonResponse
    {
        try {
            $reward = $this->subscriptionService->claimDailyReward($request->user());

            return response()->json([
                'status' => 'success',
                'message' => "Claimed {$reward['coins']} gold coins and {$reward['diamonds']} diamonds!",
                'data' => [
                    'coins_claimed' => $reward['coins'],
                    'diamonds_claimed' => $reward['diamonds'],
                ],
            ]);
        } catch (SubscriptionException $e) {
            return response()->json([
                'error_code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }
}
