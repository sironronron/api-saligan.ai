<?php

namespace App\Services\Integrations\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2.0 against Google's authorization server, used for Google Workspace.
 *
 * Google rotates access tokens but keeps the refresh token stable, and its
 * revoke endpoint accepts either one, so a disconnect revokes the refresh
 * token when there is one and falls back to the access token otherwise.
 */
class GoogleOAuthClient implements ProviderOAuthClient
{
    public function authorizationUrl(array $scopes, string $state, string $redirectUri): string
    {
        return config('integrations.google.authorize_url').'?'.http_build_query([
            'client_id' => config('integrations.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            // Consent every time, so an incremental round-trip can add scopes
            // to a connection that already consented once.
            'prompt' => 'consent',
            'access_type' => 'offline',
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        return $this->tokenPayload($response);
    }

    public function refreshToken(string $refreshToken): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return $this->tokenPayload($response);
    }

    public function revokeToken(string $token): bool
    {
        $response = Http::asForm()->post(config('integrations.google.revoke_url'), [
            'token' => $token,
        ]);

        return $response->successful();
    }

    public function accountInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(config('integrations.google.userinfo_url'));

        if (! $response->successful()) {
            return ['id' => null, 'email' => null, 'name' => null];
        }

        return [
            'id' => $response->json('sub'),
            'email' => $response->json('email'),
            'name' => $response->json('name'),
        ];
    }

    /**
     * The authenticated token-endpoint client.
     */
    protected function tokenRequest(array $payload): array
    {
        $response = $this->client()->post(
            config('integrations.google.token_url'),
            $payload + [
                'client_id' => config('integrations.google.client_id'),
                'client_secret' => config('integrations.google.client_secret'),
            ],
        );

        $response->throw();

        return $response->json();
    }

    protected function client(): PendingRequest
    {
        return Http::asForm()->acceptJson();
    }

    /**
     * Normalize a token-endpoint answer into the shape both grant types share.
     *
     * @param  array<string, mixed>  $json
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null, scope: string|null}
     */
    protected function tokenPayload(array $json): array
    {
        return [
            'access_token' => (string) $json['access_token'],
            'refresh_token' => $json['refresh_token'] ?? null,
            'expires_in' => isset($json['expires_in']) ? (int) $json['expires_in'] : null,
            'scope' => $json['scope'] ?? null,
        ];
    }
}
