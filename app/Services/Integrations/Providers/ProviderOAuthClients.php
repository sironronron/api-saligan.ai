<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;

/**
 * Resolves the OAuth client for a provider. Bound once in the container so a
 * controller or service never names a concrete client.
 */
class ProviderOAuthClients
{
    public function __construct(
        public readonly GoogleOAuthClient $google,
        public readonly MicrosoftOAuthClient $microsoft,
    ) {
        //
    }

    public function for(IntegrationProvider $provider): ProviderOAuthClient
    {
        return match ($provider) {
            IntegrationProvider::GoogleWorkspace => $this->google,
            IntegrationProvider::SharePoint => $this->microsoft,
        };
    }
}
