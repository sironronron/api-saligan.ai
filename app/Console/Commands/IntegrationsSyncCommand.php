<?php

namespace App\Console\Commands;

use App\Jobs\SyncIntegrationCapability;
use App\Models\Integration;
use App\Services\Integrations\IntegrationCatalogue;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\Providers\ProviderSyncGateways;
use App\Services\Integrations\WebhookRegistrar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('integrations:sync {--integration= : Sync only this integration id}')]
#[Description('Sync every connected integration\'s enabled capabilities')]
class IntegrationsSyncCommand extends Command
{
    public function handle(
        IntegrationManager $manager,
        ProviderSyncGateways $gateways,
        WebhookRegistrar $webhooks,
    ): int {
        $query = Integration::query()->where('status', Integration::STATUS_CONNECTED);

        if ($id = $this->option('integration')) {
            $query->whereKey($id);
        }

        $queued = 0;

        $query->chunkById(50, function ($integrations) use ($manager, $gateways, $webhooks, &$queued): void {
            foreach ($integrations as $integration) {
                $gateway = $gateways->for($integration->provider);

                foreach (array_keys(IntegrationCatalogue::capabilities($integration->provider)) as $capability) {
                    if (! $gateway->isSyncable($capability)) {
                        continue;
                    }

                    if (! $manager->isEffectivelyEnabled($integration, $capability)) {
                        continue;
                    }

                    // Push where the provider offers it; the scheduled run is
                    // the fallback and the reconciliation either way.
                    $webhooks->ensureSubscribed($integration, $capability);

                    SyncIntegrationCapability::dispatch($integration->id, $capability);
                    $queued++;
                }
            }
        });

        $this->info("Queued {$queued} capability sync(s).");

        return self::SUCCESS;
    }
}
