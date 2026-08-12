<?php

namespace App\Services\Auth;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Validates Supabase Auth access tokens and resolves the matching local
 * {@see User}, creating one on first sign-in.
 */
class SupabaseJwtService
{
    private const ALGORITHM = 'HS256';

    private const JWKS_CACHE_KEY = 'supabase.jwks';

    /** Signing keys change only when the project rotates them. */
    private const JWKS_CACHE_TTL = 3600;

    /**
     * Tolerance for clock drift between Supabase and this server, in seconds.
     *
     * The library defaults to zero, which means a token whose `iat` is even one
     * second ahead of our clock is rejected outright with a BeforeValidException
     * — and since Supabase stamps `iat` at the moment it mints the token, any
     * server running fractionally behind rejects *every* token it is sent. Two
     * seconds of ordinary drift is enough to take sign-in down completely.
     * RFC 7519 allows a small leeway for exactly this; it applies to `exp` too,
     * so a token is honoured for a minute past expiry rather than to the second.
     */
    private const CLOCK_SKEW_LEEWAY_SECONDS = 60;

    private ?string $secret = null;

    /**
     * Decode and verify a Supabase access token, returning the JWT claims
     * (including `sub` and `email`), or null when the token is invalid.
     *
     * Supabase signs with one of two schemes depending on how the project is
     * set up. Projects using asymmetric keys — the default for anything created
     * recently — sign with ES256 and publish the public half as a JWKS. Older
     * projects sign with HS256 using the shared `jwt_secret`. The token's header
     * says which was used, and each algorithm is verified only against the key
     * material that belongs to it, so a token cannot nominate the weaker scheme
     * and be checked under it.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        $header = $this->header($token);

        $algorithm = $header['alg'] ?? null;

        if (! is_string($algorithm) || $algorithm === '') {
            return null;
        }

        $keys = str_starts_with($algorithm, 'HS')
            ? $this->sharedSecretKey()
            : $this->publicKeys($header['kid'] ?? null);

        if ($keys === null || $keys === []) {
            return null;
        }

        JWT::$leeway = self::CLOCK_SKEW_LEEWAY_SECONDS;

        try {
            return (array) JWT::decode($token, $keys);
        } catch (Throwable $e) {
            // Verification failures are a normal outcome for a bad token, so
            // this stays quiet — but logging the reason is the difference
            // between diagnosing a clock-skew outage in a minute and mistaking
            // it for a misconfigured key.
            Log::debug('Supabase token rejected.', [
                'reason' => $e->getMessage(),
                'algorithm' => $algorithm,
            ]);

            return null;
        }
    }

    /**
     * The token's unverified header, read only to learn which key material to
     * verify against. Nothing in it is trusted: the header names an algorithm,
     * and {@see decode()} picks the key for that algorithm rather than letting
     * the header select a key directly.
     *
     * @return array<string, mixed>
     */
    protected function header(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return [];
        }

        try {
            $decoded = JWT::jsonDecode(JWT::urlsafeB64Decode($segments[0]));
        } catch (Throwable) {
            return [];
        }

        return is_object($decoded) ? (array) $decoded : [];
    }

    /** The HS256 key for projects still signing with the shared secret. */
    protected function sharedSecretKey(): ?Key
    {
        $secret = $this->secret();

        return $secret === null ? null : new Key($secret, self::ALGORITHM);
    }

    /**
     * The project's published verification keys, indexed by `kid`.
     *
     * A `kid` missing from the cached set is what a key rotation looks like
     * from here, so that case — and only that case — pays for a refetch.
     *
     * @return array<string, Key>
     */
    protected function publicKeys(mixed $kid): array
    {
        $keys = $this->parseKeySet($this->jwks());

        if (is_string($kid) && $kid !== '' && ! array_key_exists($kid, $keys)) {
            return $this->parseKeySet($this->jwks(refresh: true));
        }

        return $keys;
    }

    /**
     * The raw JWKS document, cached between requests.
     *
     * @return array<string, mixed>|null
     */
    protected function jwks(bool $refresh = false): ?array
    {
        if ($refresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        $cached = Cache::get(self::JWKS_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $url = $this->jwksUrl();

        if ($url === null) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $jwks = $response->json();

        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            return null;
        }

        // Only a good response is cached. Caching a failure would lock every
        // request out of the API until the TTL expired.
        Cache::put(self::JWKS_CACHE_KEY, $jwks, self::JWKS_CACHE_TTL);

        return $jwks;
    }

    /**
     * @param  array<string, mixed>|null  $jwks
     * @return array<string, Key>
     */
    protected function parseKeySet(?array $jwks): array
    {
        if ($jwks === null) {
            return [];
        }

        try {
            return JWK::parseKeySet($jwks);
        } catch (Throwable) {
            return [];
        }
    }

    /** Where the project publishes the public half of its signing keys. */
    public function jwksUrl(): ?string
    {
        $url = config('supabase.url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        return rtrim($url, '/').'/auth/v1/.well-known/jwks.json';
    }

    /**
     * Resolve the local user for a set of Supabase JWT claims, importing the
     * account into the `users` table when it does not exist yet. Matches by
     * Supabase UID first, then by email so pre-Supabase accounts keep their
     * organizations, subscriptions, and profile data.
     */
    public function resolveOrCreateUser(array $claims): ?User
    {
        $uid = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;

        if (! is_string($uid) || $uid === '' || (! is_string($email) || $email === '')) {
            return null;
        }

        $user = User::query()->where('supabase_uid', $uid)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::query()->where('email', strtolower($email))->first();

        if ($user !== null) {
            if ($user->supabase_uid === null) {
                $user->forceFill(['supabase_uid' => $uid])->save();
            }

            return $user;
        }

        return User::create([
            'supabase_uid' => $uid,
            'name' => $this->nameFromClaims($claims),
            'email' => strtolower($email),
        ]);
    }

    /**
     * The display name carried by the Supabase JWT. Google populates
     * `user_metadata.full_name`; email/password sign-ups default to the full
     * name prefix of the email when none was provided.
     */
    protected function nameFromClaims(array $claims): string
    {
        $metadata = (array) ($claims['user_metadata'] ?? []);

        $name = $metadata['full_name'] ?? $metadata['name'] ?? null;

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $email = strtolower((string) ($claims['email'] ?? ''));

        $prefix = Str::before($email, '@');

        return $prefix !== '' ? $prefix : 'Batayan user';
    }

    /**
     * Build an HS256 JWT for a user (used by tests to simulate a real Supabase
     * access token).
     */
    public function mintToken(string $uid, string $email, array $metadata = []): string
    {
        $secret = $this->secret();

        if ($secret === null) {
            throw new InvalidArgumentException('No SUPABASE_JWT_SECRET configured.');
        }

        $now = time();

        $claims = [
            'aud' => 'authenticated',
            'exp' => $now + 3600,
            'iat' => $now,
            'iss' => $this->issuer(),
            'sub' => $uid,
            'email' => $email,
            'role' => 'authenticated',
            'user_metadata' => $metadata,
        ];

        return JWT::encode($claims, $secret, self::ALGORITHM);
    }

    /**
     * The JWT issuer on the token; used for generated tokens and exposed so
     * tests can verify claims.
     */
    public function issuer(): string
    {
        return 'https://'.parse_url((string) config('supabase.url'), PHP_URL_HOST).'/auth/v1';
    }

    protected function secret(): ?string
    {
        if ($this->secret === null) {
            $secret = config('supabase.jwt_secret');

            $this->secret = is_string($secret) && $secret !== '' ? $secret : null;
        }

        return $this->secret;
    }
}
