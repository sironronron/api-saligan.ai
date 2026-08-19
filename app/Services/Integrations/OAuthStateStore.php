<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Signs and verifies the OAuth `state` round-trip.
 *
 * The consent screen is the one place the API cannot authenticate the user —
 * the browser comes back without a bearer token — so the state carries an
 * encrypted, expiry-stamped description of who started the flow and why, and
 * the callback trusts nothing else.
 */
class OAuthStateStore
{
    public const PURPOSE_CONNECT = 'connect';

    public const PURPOSE_ENABLE_CAPABILITY = 'enable_capability';

    public const PURPOSE_REAUTHORIZE = 'reauthorize';

    /**
     * Build the state value for a consent round-trip.
     */
    public function issue(User $user, IntegrationProvider $provider, string $purpose, ?string $capability = null): string
    {
        $payload = [
            'user_id' => $user->id,
            'provider' => $provider->value,
            'purpose' => $purpose,
            'capability' => $capability,
            'nonce' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes((int) config('integrations.state_ttl_minutes', 10))->toIso8601String(),
        ];

        // base64url over the encrypted blob: providers are picky about the
        // characters a state may carry, and `+`, `/`, and `=` are not always.
        return rtrim(strtr(base64_encode(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))), '+/', '-_'), '=');
    }

    /**
     * Read a state back. Returns null when it cannot be decrypted, has
     * expired, or names a provider or user that does not exist.
     *
     * @return array{user_id: int, provider: IntegrationProvider, purpose: string, capability: string|null}|null
     */
    public function consume(string $state): ?array
    {
        try {
            $json = Crypt::decryptString(base64_decode(strtr($state, '-_', '+/'), true) ?: throw new \InvalidArgumentException);
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $provider = IntegrationProvider::tryFrom($payload['provider'] ?? '');

        if ($provider === null || ! isset($payload['user_id'], $payload['purpose'], $payload['expires_at'])) {
            return null;
        }

        if (now()->greaterThan($payload['expires_at'])) {
            return null;
        }

        return [
            'user_id' => $payload['user_id'],
            'provider' => $provider,
            'purpose' => $payload['purpose'],
            'capability' => $payload['capability'] ?? null,
        ];
    }
}
