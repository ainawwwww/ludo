<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Wallet;
use App\Services\MatchmakingService;
use Illuminate\Console\Command;

/**
 * Local testing helper: Simulates a second player joining the matchmaking queue.
 *
 * Usage:
 *   php artisan matchmaking:test-join                  # 2-player, 500 coins
 *   php artisan matchmaking:test-join --players=4      # 4-player mode
 *   php artisan matchmaking:test-join --fee=1000       # 1000 coin entry
 *   php artisan matchmaking:test-join --count=3        # Join 3 dummy players (for 4-player mode)
 */
class TestMatchmakingJoin extends Command
{
    protected $signature = 'matchmaking:test-join
        {--players=2 : Max players (2 or 4)}
        {--fee=500 : Entry fee amount}
        {--count=1 : Number of dummy players to join}';

    protected $description = '[DEV ONLY] Simulate dummy player(s) joining the matchmaking queue for local testing';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('❌ This command is disabled in production!');
            return 1;
        }

        $maxPlayers = (int) $this->option('players');
        $entryFee = (int) $this->option('fee');
        $count = (int) $this->option('count');

        $this->info("🎮 Matchmaking Test Join");
        $this->info("   Mode: {$maxPlayers}-player | Entry Fee: {$entryFee} coins | Dummy Players: {$count}");
        $this->newLine();

        $matchmakingService = app(MatchmakingService::class);

        for ($i = 0; $i < $count; $i++) {
            // Create or find a test user
            $testUsername = "test_bot_" . ($i + 1) . "_" . time();
            $user = User::create([
                'username' => $testUsername,
                'email' => $testUsername . '@test.local',
                'password' => bcrypt('test1234'),
                'is_guest' => true,
                'avatar_url' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Give them enough coins
            Wallet::updateOrCreate(
                ['user_id' => $user->id],
                ['coins_balance' => max($entryFee * 2, 10000), 'diamonds_balance' => 0]
            );

            $this->info("👤 Created test user: {$user->username} (ID: {$user->id})");

            // Join the queue
            $result = $matchmakingService->join($user, $maxPlayers, $entryFee);

            $status = $result['status'] ?? 'unknown';

            if ($status === 'matched') {
                $this->info("🎉 MATCH FOUND!");
                $this->info("   Room ID: {$result['room_id']}");
                $this->info("   Game ID: {$result['game_id']}");
                $this->info("   Players:");
                foreach ($result['players'] as $p) {
                    $this->info("     - {$p['username']} (seat {$p['seat_position']}, color: {$p['color']})");
                }
                $this->newLine();
                $this->info("✅ Match created! Your Flutter app should receive the MatchFound WebSocket event now.");
            } elseif ($status === 'waiting') {
                $position = $result['queue_position'] ?? '?';
                $this->warn("⏳ Queued at position {$position}. Waiting for more players...");
                $this->info("   Run this command again or press 'FIND MATCH' in Flutter to fill the queue.");
            } else {
                $this->error("❌ Unexpected result: " . json_encode($result));
            }

            $this->newLine();
        }

        return 0;
    }
}
