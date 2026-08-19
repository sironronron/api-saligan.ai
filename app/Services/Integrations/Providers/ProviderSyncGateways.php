<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;

/**
 * Resolves the sync gateway for a provider.
 */
class ProviderSyncGateways
{
    public function __construct(
        public readonly GoogleSyncGateway $google,
        public readonly MicrosoftSyncGateway $microsoft,
    ) {
        //
    }

    public function for(IntegrationProvider $provider): ProviderSyncGateway
    {
        return match ($provider) {
            IntegrationProvider::GoogleWorkspace => $this->google,
            IntegrationProvider::SharePoint => $this->microsoft,
        };
    }
}
