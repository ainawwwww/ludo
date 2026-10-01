<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use App\Enums\TransactionType;
use App\Exceptions\SubscriptionException;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(
        protected PaymentGatewayInterface $paymentGateway,
        protected WalletService $walletService
    ) {}

    /**
     * Check if user has an active or cancelled (unexpired) VIP subscription.
     */
    public function hasActiveVip(User|int $user): bool
    {
        $subscription = $this->current($user);
        return $subscription !== null && $subscription->isActiveVip();
    }

    /**
     * Get user's current subscription if active or recently cancelled (unexpired).
     */
    public function current(User|int $user): ?Subscription
    {
        $userId = $user instanceof User ? $user->id : (int) $user;

        return Subscription::where('user_id', $userId)
            ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLED])
            ->where('current_period_end', '>', now())
            ->first();
    }

    /**
     * Process checkout for a new VIP subscription.
     * Concurrency guarded via Cache::lock + DB transaction.
     *
     * @throws SubscriptionException
     */
    public function checkout(User $user, SubscriptionTier $tier, bool $forceFailure = false): Subscription
    {
        $lockKey = "checkout_lock_{$user->id}";

        try {
            return Cache::lock($lockKey, 10)->block(3, function () use ($user, $tier, $forceFailure) {
                // Re-check active subscription inside lock
                if ($this->hasActiveVip($user)) {
                    throw SubscriptionException::alreadySubscribed(
                        'You already have an active VIP subscription. Please cancel your current plan first.'
                    );
                }

                // Process charge via PaymentGateway (Dummy)
                $result = $this->paymentGateway->charge($user, $tier, $forceFailure);

                if (!$result['success']) {
                    // Log failed audit transaction
                    PaymentTransaction::create([
                        'transaction_id' => $result['transaction_id'],
                        'user_id' => $user->id,
                        'subscription_id' => null,
                        'gateway' => $result['gateway'],
                        'tier' => $tier,
                        'amount' => $result['amount'],
                        'currency' => 'USD',
                        'status' => 'failed',
                        'failure_reason' => $result['failure_reason'] ?? 'declined',
                    ]);

                    throw SubscriptionException::paymentFailed(
                        'Payment failed: ' . ($result['failure_reason'] ?? 'Payment declined')
                    );
                }

                return DB::transaction(function () use ($user, $tier, $result) {
                    $now = now();
                    $periodEnd = $now->copy()->addDays(30);

                    // Upsert subscription row
                    $subscription = Subscription::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'tier' => $tier,
                            'status' => SubscriptionStatus::ACTIVE,
                            'started_at' => $now,
                            'current_period_end' => $periodEnd,
                            'cancelled_at' => null,
                            'auto_renew' => true,
                        ]
                    );

                    // Log successful audit transaction
                    PaymentTransaction::create([
                        'transaction_id' => $result['transaction_id'],
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
                        'gateway' => $result['gateway'],
                        'tier' => $tier,
                        'amount' => $result['amount'],
                        'currency' => 'USD',
                        'status' => 'success',
                    ]);

                    return $subscription;
                });
        });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw SubscriptionException::alreadySubscribed('Checkout transaction in progress. Please try again.');
        }
    }

    /**
     * Cancel auto-renewal for current subscription.
     *
     * @throws SubscriptionException
     */
    public function cancel(User $user): Subscription
    {
        $subscription = $this->current($user);

        if ($subscription === null || !$subscription->isActiveVip()) {
            throw SubscriptionException::noActiveSubscription();
        }

        $subscription->status = SubscriptionStatus::CANCELLED;
        $subscription->auto_renew = false;
        $subscription->cancelled_at = now();
        $subscription->save();

        return $subscription;
    }

    /**
     * Manually claim daily VIP reward.
     *
     * @throws SubscriptionException
     */
    public function claimDailyReward(User $user): array
    {
        $subscription = $this->current($user);

        if ($subscription === null || !$subscription->isActiveVip()) {
            throw SubscriptionException::noActiveSubscription();
        }

        if (!$subscription->canClaimDailyReward()) {
            throw SubscriptionException::rewardAlreadyClaimed();
        }

        $tier = $subscription->tier;
        $today = now()->toDateString();
        $refIdCoins = "vip_daily_coins_{$user->id}_{$today}";
        $refIdDiamonds = "vip_daily_diamonds_{$user->id}_{$today}";

        return DB::transaction(function () use ($user, $subscription, $tier, $today, $refIdCoins, $refIdDiamonds) {
            $subscription->last_daily_reward_at = $today;
            $subscription->save();

            $this->walletService->credit(
                $user,
                $tier->dailyCoins(),
                $refIdCoins,
                TransactionType::REWARD,
                'coins'
            );

            $this->walletService->credit(
                $user,
                $tier->dailyDiamonds(),
                $refIdDiamonds,
                TransactionType::REWARD,
                'diamonds'
            );

            return [
                'coins' => $tier->dailyCoins(),
                'diamonds' => $tier->dailyDiamonds(),
            ];
        });
    }

    /**
     * Credit daily VIP rewards to all eligible active subscribers.
     */
    public function creditAllDailyRewards(): int
    {
        $today = now()->toDateString();

        $subscribers = Subscription::whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLED])
            ->where('current_period_end', '>', now())
            ->where(function ($query) use ($today) {
                $query->whereNull('last_daily_reward_at')
                    ->orWhere('last_daily_reward_at', '<', $today);
            })
            ->get();

        $creditedCount = 0;

        foreach ($subscribers as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            try {
                $this->claimDailyReward($user);
                $creditedCount++;
            } catch (\Exception $e) {
                // Ignore individual failure to continue batch
            }
        }

        return $creditedCount;
    }

    /**
     * Process hourly subscription renewals for expired periods.
     */
    public function processRenewals(): int
    {
        $expiredSubscriptions = Subscription::where('current_period_end', '<=', now())
            ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLED])
            ->get();

        $renewedCount = 0;

        foreach ($expiredSubscriptions as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            if (!$subscription->auto_renew || $subscription->status === SubscriptionStatus::CANCELLED) {
                $subscription->status = SubscriptionStatus::EXPIRED;
                $subscription->auto_renew = false;
                $subscription->save();
                continue;
            }

            $periodEndKey = $subscription->current_period_end->format('YmdHis');
            $deterministicTxId = "vip_renewal_{$subscription->id}_{$periodEndKey}";

            if (PaymentTransaction::where('transaction_id', $deterministicTxId)->exists()) {
                continue;
            }

            // Attempt auto-renewal via DummyPaymentGateway
            $result = $this->paymentGateway->charge($user, $subscription->tier);
            $txId = $result['transaction_id'] ?? $deterministicTxId;

            if ($result['success']) {
                $subscription->started_at = now();
                $subscription->current_period_end = now()->addDays(30);
                $subscription->status = SubscriptionStatus::ACTIVE;
                $subscription->save();

                PaymentTransaction::create([
                    'transaction_id' => $deterministicTxId,
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'gateway' => $result['gateway'],
                    'tier' => $subscription->tier,
                    'amount' => $result['amount'],
                    'currency' => 'USD',
                    'status' => 'success',
                ]);

                $renewedCount++;
            } else {
                $subscription->status = SubscriptionStatus::EXPIRED;
                $subscription->auto_renew = false;
                $subscription->save();

                PaymentTransaction::create([
                    'transaction_id' => $deterministicTxId,
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'gateway' => $result['gateway'],
                    'tier' => $subscription->tier,
                    'amount' => $result['amount'],
                    'currency' => 'USD',
                    'status' => 'failed',
                    'failure_reason' => $result['failure_reason'] ?? 'renewal_declined',
                ]);
            }
        }

        return $renewedCount;
    }
}
