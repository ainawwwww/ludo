<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class CreditVipDailyRewardsCommand extends Command
{
    protected $signature = 'vip:credit-daily-rewards';
    protected $description = 'Credit daily gold and diamonds reward to all eligible active VIP subscribers';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $this->info('Processing daily VIP subscription rewards...');
        $count = $subscriptionService->creditAllDailyRewards();
        $this->info("Successfully credited daily rewards to {$count} active subscriber(s).");
        return Command::SUCCESS;
    }
}
