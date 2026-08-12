<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\SupabaseJwtService;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

function supabaseToken(string $uid, string $email, array $metadata = []): string
{
    return app(SupabaseJwtService::class)->mintToken($uid, $email, $metadata);
}

function withToken(string $token): TestCase
{
    return test()->withHeader('Authorization', 'Bearer '.$token);
}

/**
 * A P-256 key pair, as the private PEM plus the public point the JWKS carries.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function ecKeyPair(): array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    openssl_pkey_export($key, $privatePem);

    $details = openssl_pkey_get_details($key);

    return [$privatePem, $details['ec']['x'], $details['ec']['y']];
}

/**
 * The JWKS document Supabase publishes for a project on asymmetric keys.
 *
 * @return array<string, mixed>
 */
function jwksDocument(string $x, string $y, string $kid): array
{
    $base64Url = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    return ['keys' => [[
        'kty' => 'EC',
        'crv' => 'P-256',
        'alg' => 'ES256',
        'use' => 'sig',
        'kid' => $kid,
        'x' => $base64Url($x),
        'y' => $base64Url($y),
    ]]];
}

it('rejects a request with no Authorization header', function () {
    test()->getJson('/api/user')->assertUnauthorized();
});

it('rejects a malformed Authorization header', function () {
    test()->withHeader('Authorization', 'Basic abc123')
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('rejects a token signed with the wrong secret', function () {
    $forged = JWT::encode([
        'sub' => (string) Str::uuid(),
        'email' => 'attacker@example.com',
        'exp' => time() + 3600,
        // HS256 requires a 256-bit key, so the forged secret has to be long
        // enough to sign with — it just must not be the project's.
    ], 'an-attacker-controlled-secret-that-is-long-enough', 'HS256');

    withToken($forged)->getJson('/api/user')->assertUnauthorized();

    expect(User::where('email', 'attacker@example.com')->exists())->toBeFalse();
});

it('rejects an expired token', function () {
    $secret = config('supabase.jwt_secret');

    $expired = JWT::encode([
        'sub' => (string) Str::uuid(),
        'email' => 'late@example.com',
        'iat' => time() - 7200,
        'exp' => time() - 3600,
    ], $secret, 'HS256');

    withToken($expired)->getJson('/api/user')->assertUnauthorized();
});

it('rejects a token carrying no email claim', function () {
    $secret = config('supabase.jwt_secret');

    $token = JWT::encode([
        'sub' => (string) Str::uuid(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    withToken($token)->getJson('/api/user')->assertUnauthorized();
});

it('imports a first-time Supabase account on its first request', function () {
    $uid = (string) Str::uuid();

    withToken(supabaseToken($uid, 'first@example.com', ['full_name' => 'First Timer']))
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'first@example.com');

    $user = User::where('supabase_uid', $uid)->firstOrFail();

    expect($user->email)->toBe('first@example.com')
        ->and($user->name)->toBe('First Timer');
});

it('falls back to the email prefix when the token carries no name', function () {
    withToken(supabaseToken((string) Str::uuid(), 'juan.delacruz@example.com'))
        ->getJson('/api/user')
        ->assertSuccessful();

    expect(User::where('email', 'juan.delacruz@example.com')->firstOrFail()->name)
        ->toBe('juan.delacruz');
});

it('imports an account exactly once across repeated requests', function () {
    $uid = (string) Str::uuid();
    $token = supabaseToken($uid, 'repeat@example.com');

    withToken($token)->getJson('/api/user')->assertSuccessful();
    withToken($token)->getJson('/api/user')->assertSuccessful();

    expect(User::where('email', 'repeat@example.com')->count())->toBe(1);
});

it('links a pre-Supabase account by email, keeping its organization and profile', function () {
    // Accounts that existed before the migration must not be duplicated: the
    // user keeps their organization, subscription, and onboarding profile.
    $organization = Organization::factory()->create();
    $existing = User::factory()->memberOf($organization)->create([
        'email' => 'legacy@example.com',
        'kyc_role' => 'lawyer',
        'supabase_uid' => null,
    ]);

    $uid = (string) Str::uuid();

    withToken(supabaseToken($uid, 'legacy@example.com'))
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $existing->id);

    $existing->refresh();

    expect($existing->supabase_uid)->toBe($uid)
        ->and($existing->organization_id)->toBe($organization->id)
        ->and($existing->kyc_role)->toBe('lawyer')
        ->and(User::where('email', 'legacy@example.com')->count())->toBe(1);
});

it('matches an existing account regardless of email casing', function () {
    $existing = User::factory()->create(['email' => 'mixed@example.com']);

    withToken(supabaseToken((string) Str::uuid(), 'Mixed@Example.com'))
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $existing->id);

    expect(User::where('email', 'mixed@example.com')->count())->toBe(1);
});

it('keeps the Supabase uid stable when the account is already linked', function () {
    $uid = (string) Str::uuid();
    $user = User::factory()->create(['email' => 'linked@example.com', 'supabase_uid' => $uid]);

    withToken(supabaseToken($uid, 'linked@example.com'))
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $user->id);

    expect($user->fresh()->supabase_uid)->toBe($uid);
});

it('does not leak one user\'s identity into the next request', function () {
    // The guard caches the resolved user for the request; under Octane the
    // instance is reused, so a stale user would authenticate the next caller.
    $first = User::factory()->create(['email' => 'one@example.com']);
    $second = User::factory()->create(['email' => 'two@example.com']);

    withToken(supabaseToken((string) Str::uuid(), $first->email))
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'one@example.com');

    withToken(supabaseToken((string) Str::uuid(), $second->email))
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'two@example.com');
});

/*
|--------------------------------------------------------------------------
| Asymmetric signing keys
|--------------------------------------------------------------------------
|
| Supabase projects created recently sign access tokens with ES256 and publish
| the public half as a JWKS, rather than with the legacy shared `jwt_secret`.
| Verifying only HS256 rejects every token such a project issues, which reads
| as a successful sign-in with no profile behind it.
|
*/

it('accepts a token signed with the key the project publishes', function () {
    [$private, $x, $y] = ecKeyPair();
    $kid = (string) Str::uuid();

    Http::fake(['*/.well-known/jwks.json' => Http::response(jwksDocument($x, $y, $kid))]);

    $uid = (string) Str::uuid();

    $token = JWT::encode([
        'sub' => $uid,
        'email' => 'asymmetric@example.com',
        'iat' => time(),
        'exp' => time() + 3600,
        'user_metadata' => ['full_name' => 'Elliptic Curve'],
    ], $private, 'ES256', $kid);

    withToken($token)
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'asymmetric@example.com');

    expect(User::where('supabase_uid', $uid)->firstOrFail()->name)->toBe('Elliptic Curve');
});

it('rejects a token signed with a key the project does not publish', function () {
    [, $x, $y] = ecKeyPair();
    [$attackerPrivate] = ecKeyPair();
    $kid = (string) Str::uuid();

    // The forged token names the project's key id, so only the signature check
    // stands between it and an account.
    Http::fake(['*/.well-known/jwks.json' => Http::response(jwksDocument($x, $y, $kid))]);

    $token = JWT::encode([
        'sub' => (string) Str::uuid(),
        'email' => 'forged@example.com',
        'iat' => time(),
        'exp' => time() + 3600,
    ], $attackerPrivate, 'ES256', $kid);

    withToken($token)->getJson('/api/user')->assertUnauthorized();

    expect(User::where('email', 'forged@example.com')->exists())->toBeFalse();
});

it('refetches the key set once when the token names an unknown key id', function () {
    [$private, $x, $y] = ecKeyPair();
    $rotatedKid = (string) Str::uuid();

    // The first response is the stale set the cache would hold after a
    // rotation; the second carries the key the new token was signed with.
    Http::fake(['*/.well-known/jwks.json' => Http::sequence()
        ->push(jwksDocument($x, $y, (string) Str::uuid()))
        ->push(jwksDocument($x, $y, $rotatedKid)),
    ]);

    $token = JWT::encode([
        'sub' => (string) Str::uuid(),
        'email' => 'rotated@example.com',
        'iat' => time(),
        'exp' => time() + 3600,
    ], $private, 'ES256', $rotatedKid);

    withToken($token)->getJson('/api/user')->assertSuccessful();
});

it('rejects tokens rather than failing open when the key set cannot be fetched', function () {
    [$private] = ecKeyPair();

    Http::fake(['*/.well-known/jwks.json' => Http::response('', 500)]);

    $token = JWT::encode([
        'sub' => (string) Str::uuid(),
        'email' => 'unreachable@example.com',
        'iat' => time(),
        'exp' => time() + 3600,
    ], $private, 'ES256', (string) Str::uuid());

    withToken($token)->getJson('/api/user')->assertUnauthorized();

    expect(User::where('email', 'unreachable@example.com')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Clock skew
|--------------------------------------------------------------------------
|
| Supabase stamps `iat` at the instant it mints a token. With the library's
| default zero leeway, a server running even a second behind rejects every
| token it is handed — sign-in fails completely, and the only symptom is a
| blanket 401 that looks identical to a key misconfiguration.
|
*/

it('accepts a token issued a few seconds ahead of this server clock', function () {
    $token = JWT::encode([
        'sub' => (string) Str::uuid(),
        'email' => 'skewed@example.com',
        'iat' => time() + 5,
        'exp' => time() + 3600,
    ], config('supabase.jwt_secret'), 'HS256');

    withToken($token)->getJson('/api/user')->assertSuccessful();
});

it('still rejects a token issued far beyond the skew tolerance', function () {
    $token = JWT::encode([
        'sub' => (string) Str::uuid(),
        'email' => 'far-future@example.com',
        'iat' => time() + 3600,
        'exp' => time() + 7200,
    ], config('supabase.jwt_secret'), 'HS256');

    withToken($token)->getJson('/api/user')->assertUnauthorized();

    expect(User::where('email', 'far-future@example.com')->exists())->toBeFalse();
});
