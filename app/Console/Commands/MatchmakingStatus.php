<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Check current matchmaking queue status for debugging.
 *
 * Usage:
 *   php artisan matchmaking:status                    # Check all queues
 *   php artisan matchmaking:status --players=2 --fee=500   # Check specific queue
 *   php artisan matchmaking:flush                     # Clear all queues (use --flush flag)
 */
class MatchmakingStatus extends Command
{
    protected $signature = 'matchmaking:status
        {--players= : Filter by player count (2 or 4)}
        {--fee= : Filter by entry fee}
        {--flush : Clear ALL matchmaking queues}';

    protected $description = '[DEV ONLY] Check or flush matchmaking queue status';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('❌ This command is disabled in production!');
            return 1;
        }

        if ($this->option('flush')) {
            return $this->flushQueues();
        }

        // Check specific queue or common ones
        $queuesToCheck = [];

        if ($this->option('players') && $this->option('fee')) {
            $queuesToCheck[] = [
                'players' => (int) $this->option('players'),
                'fee' => (int) $this->option('fee'),
            ];
        } else {
            // Check common queue combinations
            foreach ([2, 4] as $players) {
                foreach ([500, 1000, 2000, 5000, 10000] as $fee) {
                    $queuesToCheck[] = ['players' => $players, 'fee' => $fee];
                }
            }
        }

        $this->info("📊 Matchmaking Queue Status");
        $this->newLine();

        $foundAny = false;

        foreach ($queuesToCheck as $q) {
            $key = "matchmaking:queue:{$q['players']}:{$q['fee']}";
            $queue = Cache::get($key, []);

            if (!empty($queue)) {
                $foundAny = true;
                $this->info("🎮 Queue: {$q['players']}-player | {$q['fee']} coins | {$key}");
                $this->info("   Players in queue: " . count($queue));

                foreach ($queue as $idx => $entry) {
                    $userId = $entry['user_id'] ?? '?';
                    $joinedAt = $entry['joined_at'] ?? '?';
                    $user = \App\Models\User::find($userId);
                    $username = $user ? $user->username : "User#{$userId}";
                    $this->info("   [{$idx}] {$username} (ID: {$userId}) — joined: {$joinedAt}");
                }

                $this->newLine();
            }
        }

        if (!$foundAny) {
            $this->warn("⚠️ All queues are empty. No players waiting.");
        }

        return 0;
    }

    private function flushQueues(): int
    {
        $this->warn("🗑️ Flushing ALL matchmaking queues...");

        $flushed = 0;
        foreach ([2, 4] as $players) {
            foreach ([500, 1000, 2000, 5000, 10000] as $fee) {
                $key = "matchmaking:queue:{$players}:{$fee}";
                $queue = Cache::get($key, []);

                if (!empty($queue)) {
                    // Clean user markers too
                    foreach ($queue as $entry) {
                        Cache::forget("matchmaking:user:{$entry['user_id']}");
                    }
                    Cache::forget($key);
                    $flushed++;
                    $this->info("   Cleared: {$key} ({" . count($queue) . "} players)");
                }
            }
        }

        if ($flushed === 0) {
            $this->info("   Nothing to flush — all queues were already empty.");
        } else {
            $this->info("✅ Flushed {$flushed} queue(s).");
        }

        return 0;
    }
}
