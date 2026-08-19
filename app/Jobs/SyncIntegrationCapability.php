<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\Integrations\IntegrationSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sync one capability of one integration off the request path.
 *
 * Syncs talk to a third party and can take a while, so a manual "sync now"
 * and the scheduled sweep both queue the work here instead of holding a
 * request open on Google or Microsoft.
 */
class SyncIntegrationCapability implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $integrationId,
        public readonly string $capability,
    ) {
        //
    }

    public function handle(IntegrationSyncService $sync): void
    {
        $integration = Integration::query()->find($this->integrationId);

        if ($integration === null) {
            return;
        }

        $sync->syncCapability($integration, $this->capability);
    }
}
