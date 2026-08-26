<?php

namespace App\Console\Commands;

use App\Models\LeagueSeason;
use App\Services\League\LeagueSeasonService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessLeagueSeasonEnd extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'league:process-season-end';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and process end-of-season promotion, demotion, and rollover for expired active league seasons.';

    /**
     * Execute the console command.
     */
    public function handle(LeagueSeasonService $seasonService): int
    {
        $now = Carbon::now();
        $expiredSeasons = LeagueSeason::where('status', 'active')
            ->where('ends_at', '<=', $now)
            ->get();

        if ($expiredSeasons->isEmpty()) {
            $this->info('No expired active league seasons found to process.');
            return Command::SUCCESS;
        }

        foreach ($expiredSeasons as $season) {
            $this->info("Processing end of Season #{$season->season_number} (ID: {$season->id})...");
            $seasonService->endSeason($season->id);
            $this->info("Season #{$season->season_number} rollover completed successfully.");
        }

        return Command::SUCCESS;
    }
}
