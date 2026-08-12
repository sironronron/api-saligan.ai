<?php

namespace App\Auth;

use App\Models\User;
use App\Services\Auth\SupabaseJwtService;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

/**
 * Stateless guard that authenticates requests using the Supabase access token
 * sent in the `Authorization: Bearer` header. The token is validated against
 * Supabase's JWT secret and the matching local {@see User} is resolved (and
 * imported) from the `users` table.
 */
class SupabaseGuard implements Guard
{
    use GuardHelpers;

    private bool $resolved = false;

    private Request $request;

    public function __construct(
        private readonly SupabaseJwtService $jwts,
        UserProvider $provider,
        Request $request,
    ) {
        // GuardHelpers already declares $provider (protected, untyped), so
        // promoting a private readonly one of the same name makes the class
        // impossible to compose. Assign the trait's property instead — that is
        // the one getProvider()/setProvider() read.
        $this->provider = $provider;
        $this->request = $request;
    }

    /**
     * Return the currently authenticated user, resolving it from the bearer
     * token on the first call.
     */
    public function user(): ?Authenticatable
    {
        if ($this->resolved || $this->user !== null) {
            return $this->user;
        }

        $claims = $this->jwts->decode((string) $this->token());

        $this->user = $claims !== null ? $this->jwts->resolveOrCreateUser($claims) : null;
        $this->resolved = true;

        return $this->user;
    }

    /**
     * Validate the bearer token without resolving a full user record.
     */
    public function validate(array $credentials = []): bool
    {
        $token = $credentials['token'] ?? $this->token();

        if (! is_string($token) || $token === '') {
            return false;
        }

        return $this->jwts->decode($token) !== null;
    }

    /**
     * Set the request instance for the guard. The resolved user is dropped so
     * request state can never leak across requests under Octane.
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->resolved = false;
        $this->user = null;
    }

    /**
     * The bearer token from the incoming request, or null.
     */
    protected function token(): ?string
    {
        $authorization = $this->request->header('Authorization');

        if (! is_string($authorization)) {
            return null;
        }

        return preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) ? $matches[1] : null;
    }
}
