<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Integrations\TokenRefresher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('integrations:refresh-tokens')]
#[Description('Refresh integration access tokens before they expire')]
class IntegrationsRefreshTokensCommand extends Command
{
    public function handle(TokenRefresher $refresher): int
    {
        $refreshed = 0;
        $failed = 0;

        // Refresh anything expiring within the day so a sync never lands on a
        // dead token, and anything already marked for reauthorization gets one
        // last attempt in case the provider side healed itself.
        Integration::query()
            ->whereIn('status', [Integration::STATUS_CONNECTED, Integration::STATUS_NEEDS_REAUTHORIZATION])
            ->whereNotNull('refresh_token')
            ->where(fn ($query) => $query
                ->whereNull('token_expires_at')
                ->orWhere('token_expires_at', '<', now()->addDay()))
            ->chunkById(50, function ($integrations) use ($refresher, &$refreshed, &$failed): void {
                foreach ($integrations as $integration) {
                    if ($refresher->refresh($integration)) {
                        $refreshed++;
                    } else {
                        $failed++;
                    }
                }
            });

        $this->info("Refreshed {$refreshed} token(s); {$failed} need reauthorization.");

        return self::SUCCESS;
    }
}
