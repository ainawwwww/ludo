<?php

namespace App\Listeners;

use App\Events\BattleStartedEvent;
use App\Events\DiceRolledEvent;
use App\Events\GameWonEvent;
use App\Events\GiftSentEvent;
use App\Models\UserDailyTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateDailyTaskProgressListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(object $event): void
    {
        $taskKey = null;
        $userId = null;

        if ($event instanceof GameWonEvent) {
            $taskKey = 'win_matches';
            $userId = $event->userId;
        } elseif ($event instanceof BattleStartedEvent) {
            $taskKey = 'play_battles';
            $userId = $event->userId;
        } elseif ($event instanceof GiftSentEvent) {
            $taskKey = 'send_gifts';
            $userId = $event->userId;
        } elseif ($event instanceof DiceRolledEvent) {
            if ($event->diceValue === 6) {
                $taskKey = 'roll_sixes';
                $userId = $event->userId;
            }
        }

        if ($taskKey === null || $userId === null) {
            return;
        }

        $today = now()->toDateString();

        $task = UserDailyTask::where('user_id', $userId)
            ->where('task_key', $taskKey)
            ->whereDate('task_date', $today)
            ->first();

        if ($task && $task->current_progress < $task->total_progress) {
            $task->current_progress = min($task->total_progress, $task->current_progress + 1);
            $task->save();

            Log::info("🏆 [DAILY TASK] Incremented {$taskKey} for user {$userId}: {$task->current_progress}/{$task->total_progress}");
        }
    }
}
