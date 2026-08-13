<?php

namespace App\Console\Commands;

use App\Services\Billing\TrialWarner;
use Illuminate\Console\Command;

class TrialsWarn extends Command
{
    protected $signature = 'trials:warn';

    protected $description = 'Email trial owners whose trial is within the warning window';

    public function handle(TrialWarner $warner): int
    {
        $sent = $warner->sweepExpiringTrials();

        $this->info("Trial warnings sent: {$sent}");

        return self::SUCCESS;
    }
}
