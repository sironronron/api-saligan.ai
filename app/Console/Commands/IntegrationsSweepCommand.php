<?php

namespace App\Console\Commands;

use App\Services\Integrations\IntegrationEligibility;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('integrations:sweep')]
#[Description('Pause integrations whose plan no longer carries add-ons, resume ones whose plan does again')]
class IntegrationsSweepCommand extends Command
{
    public function handle(IntegrationEligibility $eligibility): int
    {
        $result = $eligibility->sweep();

        $this->info("Paused {$result['paused']} integration(s); resumed {$result['resumed']}.");

        return self::SUCCESS;
    }
}
