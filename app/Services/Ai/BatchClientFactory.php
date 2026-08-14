<?php

namespace App\Services\Ai;

use Laravel\Ai\Enums\Lab;

/**
 * Resolves the batch client for a provider.
 *
 * Kept as a lookup rather than a container binding because the provider is a
 * runtime value read from configuration, and a deployment can point
 * classification at a provider that has no batch API at all — for which the
 * answer is "none", not an exception.
 */
class BatchClientFactory
{
    public function __construct(
        private readonly AnthropicBatchClient $anthropic,
        private readonly GeminiBatchClient $gemini,
    ) {
        //
    }

    /**
     * The client for the given provider, or null when it does not batch.
     */
    public function for(?Lab $provider): ?BatchClient
    {
        return match ($provider) {
            Lab::Anthropic => $this->anthropic,
            Lab::Gemini => $this->gemini,
            default => null,
        };
    }
}
