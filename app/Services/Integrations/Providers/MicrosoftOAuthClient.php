<?php

namespace App\Services\Integrations\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2.0 against the Microsoft Identity Platform, used for SharePoint and
 * the rest of Microsoft 365 through Microsoft Graph.
 *
 * Microsoft rotates refresh tokens on every use, so a refresh must always
 * store the newest one the answer carries. It also publishes no endpoint that
 * revokes a delegated token on demand — disconnecting ends the session and
 * deletes the stored credentials, and a tenant admin can revoke the grant in
 * Entra if a hard revocation is needed.
 */
class MicrosoftOAuthClient implements ProviderOAuthClient
{
    public function authorizationUrl(array $scopes, string $state, string $redirectUri): string
    {
        return $this->endpoint('authorize').'?'.http_build_query([
            'client_id' => config('integrations.microsoft.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            // Ask again even for a known user, so an incremental round-trip can
            // add scopes to a connection that already consented once.
            'prompt' => 'consent',
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

    /**
     * Microsoft publishes no on-demand revocation for delegated tokens; the
     * closest thing is ending the session. The stored credentials are deleted
     * either way, which is what actually severs the connection.
     */
    public function revokeToken(string $token): bool
    {
        $response = Http::asForm()->post($this->endpoint('logout'), [
            'token' => $token,
        ]);

        return $response->successful();
    }

    public function accountInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(config('integrations.microsoft.graph_url').'/me');

        if (! $response->successful()) {
            return ['id' => null, 'email' => null, 'name' => null];
        }

        return [
            'id' => $response->json('id'),
            'email' => $response->json('mail') ?? $response->json('userPrincipalName'),
            'name' => $response->json('displayName'),
        ];
    }

    /**
     * A v2.0 endpoint under the configured tenant.
     */
    protected function endpoint(string $name): string
    {
        $tenant = config('integrations.microsoft.tenant');

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$name}";
    }

    /**
     * The authenticated token-endpoint call.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    protected function tokenRequest(array $payload): array
    {
        $response = $this->client()->post($this->endpoint('token'), $payload + [
            'client_id' => config('integrations.microsoft.client_id'),
            'client_secret' => config('integrations.microsoft.client_secret'),
        ]);

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
