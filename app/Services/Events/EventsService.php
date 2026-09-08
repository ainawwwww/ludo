<?php

namespace App\Services\Events;

use App\Enums\TransactionType;
use App\Models\EventClaimLog;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserDailyTask;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventsService
{
    protected RewardMultiplierService $multiplierService;

    public function __construct(RewardMultiplierService $multiplierService)
    {
        $this->multiplierService = $multiplierService;
    }

    /**
     * Standard daily tasks definition templates.
     */
    public static function defaultTaskTemplates(): array
    {
        return [
            [
                'task_key' => 'win_matches',
                'title' => 'Win 2 Ludo Matches',
                'reward_type' => 'coins',
                'reward_amount' => 500,
                'total_progress' => 2,
            ],
            [
                'task_key' => 'play_battles',
                'title' => 'Play 5 Betting Battles',
                'reward_type' => 'coins',
                'reward_amount' => 1200,
                'total_progress' => 5,
            ],
            [
                'task_key' => 'send_gifts',
                'title' => 'Send 3 Gifts in Voice Lobbies',
                'reward_type' => 'coins',
                'reward_amount' => 800,
                'total_progress' => 3,
            ],
            [
                'task_key' => 'roll_sixes',
                'title' => 'Roll 6 Three Times',
                'reward_type' => 'coins',
                'reward_amount' => 300,
                'total_progress' => 3,
            ],
        ];
    }

    /**
     * Get or seed daily tasks for user for current date.
     */
    public function getDailyTasksForUser(User $user): Collection
    {
        $today = now()->toDateString();

        $existing = UserDailyTask::where('user_id', $user->id)
            ->whereDate('task_date', $today)
            ->get();

        if ($existing->count() >= count(self::defaultTaskTemplates())) {
            return $existing;
        }

        foreach (self::defaultTaskTemplates() as $template) {
            UserDailyTask::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'task_key' => $template['task_key'],
                    'task_date' => $today,
                ],
                [
                    'title' => $template['title'],
                    'reward_type' => $template['reward_type'],
                    'reward_amount' => $template['reward_amount'],
                    'total_progress' => $template['total_progress'],
                    'current_progress' => 0,
                    'is_claimed' => false,
                ]
            );
        }

        return UserDailyTask::where('user_id', $user->id)
            ->whereDate('task_date', $today)
            ->get();
    }

    /**
     * Claim Daily Task reward with row-level lock and idempotency check.
     */
    public function claimDailyTask(User $user, int $taskId, string $requestId): array
    {
        return DB::transaction(function () use ($user, $taskId, $requestId) {
            // 1. Idempotency Check
            $existingLog = EventClaimLog::where('user_id', $user->id)
                ->where('claim_type', 'daily_task_' . $taskId)
                ->where('request_id', $requestId)
                ->first();

            if ($existingLog) {
                $task = UserDailyTask::findOrFail($taskId);
                $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
                return [
                    'status' => 'already_claimed',
                    'task' => $task,
                    'coins' => $wallet->coins_balance,
                    'diamonds' => $wallet->diamonds_balance,
                ];
            }

            // 2. Row-Level Lock
            $task = UserDailyTask::where('id', $taskId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$task) {
                throw new Exception('Daily task not found.');
            }

            if ($task->is_claimed) {
                throw new Exception('Daily task reward has already been claimed.');
            }

            if ($task->current_progress < $task->total_progress) {
                throw new Exception('Daily task is not completed yet.');
            }

            // 3. Mark Claimed
            $task->is_claimed = true;
            $task->save();

            // 4. Calculate multiplied reward
            $finalReward = $this->multiplierService->calculateReward($user, $task->reward_amount);

            // 5. Credit Wallet & XP
            $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
            if ($task->reward_type === 'diamonds') {
                $wallet->diamonds_balance += $finalReward;
            } else {
                $wallet->coins_balance += $finalReward;
            }
            $wallet->save();

            // Award XP bonus for daily task
            $user->addXp(20);

            // 6. Record Transaction
            Transaction::create([
                'user_id' => $user->id,
                'type' => TransactionType::REWARD->value,
                'currency_type' => $task->reward_type,
                'amount' => $finalReward,
                'reference_id' => 'daily_task_' . $task->id,
                'created_at' => now(),
            ]);

            // 7. Log Claim Event
            EventClaimLog::create([
                'user_id' => $user->id,
                'claim_type' => 'daily_task_' . $taskId,
                'request_id' => $requestId,
                'created_at' => now(),
            ]);

            return [
                'status' => 'success',
                'task' => $task,
                'reward_type' => $task->reward_type,
                'reward_amount' => $finalReward,
                'coins' => $wallet->coins_balance,
                'diamonds' => $wallet->diamonds_balance,
            ];
        });
    }

    /**
     * Get Arrival Chest status for user.
     */
    public function getArrivalChestStatus(User $user): array
    {
        $lastClaimedAt = $user->last_chest_claimed_at;
        $isReady = false;
        $secondsRemaining = 0;
        $nextAvailableAt = null;

        if ($lastClaimedAt === null) {
            $isReady = true;
        } else {
            $nextAvailable = $lastClaimedAt->copy()->addHours(24);
            if (now()->greaterThanOrEqualTo($nextAvailable)) {
                $isReady = true;
            } else {
                $isReady = false;
                $secondsRemaining = now()->diffInSeconds($nextAvailable, false);
                $nextAvailableAt = $nextAvailable->toIso8601String();
            }
        }

        return [
            'is_ready' => $isReady,
            'last_claimed_at' => $lastClaimedAt ? $lastClaimedAt->toIso8601String() : null,
            'next_available_at' => $nextAvailableAt,
            'seconds_remaining' => max(0, $secondsRemaining),
            'base_coins' => 1000,
            'base_diamonds' => 5,
            'is_vip' => $user->is_vip,
        ];
    }

    /**
     * Claim Arrival Chest reward.
     */
    public function claimArrivalChest(User $user, string $requestId): array
    {
        return DB::transaction(function () use ($user, $requestId) {
            // 1. Idempotency Check
            $existingLog = EventClaimLog::where('user_id', $user->id)
                ->where('claim_type', 'arrival_chest')
                ->where('request_id', $requestId)
                ->first();

            if ($existingLog) {
                $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
                return [
                    'status' => 'already_claimed',
                    'coins' => $wallet->coins_balance,
                    'diamonds' => $wallet->diamonds_balance,
                ];
            }

            // 2. Lock User Row
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            $status = $this->getArrivalChestStatus($lockedUser);

            if (!$status['is_ready']) {
                throw new Exception('Arrival chest is not ready to be claimed yet.');
            }

            // 3. Base rewards + Multiplier
            $baseCoins = 1000;
            $baseDiamonds = 5;

            $finalCoins = $this->multiplierService->calculateReward($lockedUser, $baseCoins);
            $finalDiamonds = $this->multiplierService->calculateReward($lockedUser, $baseDiamonds);

            // 4. Update last_chest_claimed_at
            $lockedUser->last_chest_claimed_at = now();
            $lockedUser->save();

            // 5. Credit Wallet
            $wallet = Wallet::firstOrCreate(['user_id' => $lockedUser->id]);
            $wallet->coins_balance += $finalCoins;
            $wallet->diamonds_balance += $finalDiamonds;
            $wallet->save();

            // 6. Record Transactions
            Transaction::create([
                'user_id' => $lockedUser->id,
                'type' => TransactionType::REWARD->value,
                'currency_type' => 'coins',
                'amount' => $finalCoins,
                'reference_id' => 'arrival_chest_' . now()->timestamp,
                'created_at' => now(),
            ]);

            Transaction::create([
                'user_id' => $lockedUser->id,
                'type' => TransactionType::REWARD->value,
                'currency_type' => 'diamonds',
                'amount' => $finalDiamonds,
                'reference_id' => 'arrival_chest_' . now()->timestamp,
                'created_at' => now(),
            ]);

            // 7. Log Claim Event
            EventClaimLog::create([
                'user_id' => $lockedUser->id,
                'claim_type' => 'arrival_chest',
                'request_id' => $requestId,
                'created_at' => now(),
            ]);

            return [
                'status' => 'success',
                'reward_coins' => $finalCoins,
                'reward_diamonds' => $finalDiamonds,
                'is_vip_bonus' => $lockedUser->is_vip,
                'coins' => $wallet->coins_balance,
                'diamonds' => $wallet->diamonds_balance,
                'next_available_at' => now()->addHours(24)->toIso8601String(),
            ];
        });
    }
}
