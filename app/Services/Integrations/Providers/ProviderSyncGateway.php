<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;

/**
 * The read side of an integration: checking a capability's data source for
 * changes. Implementations make the lightest provider call that proves the
 * token works and reports what moved since the last sync.
 */
interface ProviderSyncGateway
{
    /**
     * Sync one capability. Returns a short, human-readable summary of what
     * was found.
     *
     * @return array{summary: string, changed: int}
     */
    public function sync(Integration $integration, string $capability, string $accessToken): array;

    /**
     * Whether this capability syncs by schedule at all. On-demand
     * capabilities (exports) have nothing to poll.
     */
    public function isSyncable(string $capability): bool;
}
