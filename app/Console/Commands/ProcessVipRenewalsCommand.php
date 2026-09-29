<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ProcessVipRenewalsCommand extends Command
{
    protected $signature = 'vip:process-renewals';
    protected $description = 'Process hourly auto-renewals or expirations for past-period VIP subscriptions';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $this->info('Processing VIP subscription renewals...');
        $count = $subscriptionService->processRenewals();
        $this->info("Successfully auto-renewed {$count} VIP subscription(s).");
        return Command::SUCCESS;
    }
}
