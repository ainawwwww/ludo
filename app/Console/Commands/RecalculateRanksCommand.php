<?php

namespace App\Console\Commands;

use App\Enums\GameStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateRanksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leaderboard:recalculate-ranks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate global ranks for all users based on league points and total wins';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Recalculating user ranks...');

        $rank = 1;

        DB::table('users')
            ->select('users.id')
            ->selectSub(function ($query) {
                $query->from('games')
                    ->whereColumn('games.winner_id', 'users.id')
                    ->where('games.status', GameStatus::COMPLETED->value)
                    ->selectRaw('count(*)');
            }, 'total_wins')
            ->orderByDesc('league_points')
            ->orderByDesc('total_wins')
            ->orderBy('users.id')
            ->chunk(500, function ($users) use (&$rank) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['rank' => $rank]);
                    $rank++;
                }
            });

        $this->info("Successfully updated ranks for users.");
        return Command::SUCCESS;
    }
}
