<?php

use App\Services\Auth\SupabaseAdminClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('supabase.url', 'https://test.supabase.co');
    config()->set('supabase.secret_key', 'service-role-key');
});

it('reports whether the project credentials are present', function () {
    expect(app(SupabaseAdminClient::class)->isConfigured())->toBeTrue();

    config()->set('supabase.secret_key', null);

    expect(app(SupabaseAdminClient::class)->isConfigured())->toBeFalse();
});

it('creates a Supabase account and returns its id', function () {
    Http::fake([
        '*/auth/v1/admin/users' => Http::response(['id' => 'uid-123', 'email' => 'test@example.com'], 200),
    ]);

    $uid = app(SupabaseAdminClient::class)->ensureUser('test@example.com', 'password', ['full_name' => 'Test User']);

    expect($uid)->toBe('uid-123');

    Http::assertSent(function ($request) {
        return $request->hasHeader('apikey', 'service-role-key')
            && $request->hasHeader('Authorization', 'Bearer service-role-key')
            // Seeded accounts have no inbox to confirm through.
            && $request['email_confirm'] === true
            && $request['email'] === 'test@example.com'
            && $request['user_metadata']['full_name'] === 'Test User';
    });
});

it('adopts an existing account instead of failing on a duplicate', function () {
    // Re-running the seeder must be safe: Supabase rejects the duplicate, and
    // the client falls back to looking the address up.
    Http::fakeSequence()
        ->push(['msg' => 'A user with this email address has already been registered'], 422)
        ->push(['users' => [
            ['id' => 'uid-existing', 'email' => 'test@example.com'],
        ]], 200);

    $uid = app(SupabaseAdminClient::class)->ensureUser('test@example.com', 'password');

    expect($uid)->toBe('uid-existing');
});

it('matches an existing account regardless of email casing', function () {
    Http::fakeSequence()
        ->push(['msg' => 'already registered'], 422)
        ->push(['users' => [
            ['id' => 'uid-mixed', 'email' => 'Mixed@Example.com'],
        ]], 200);

    expect(app(SupabaseAdminClient::class)->ensureUser('mixed@example.com', 'password'))
        ->toBe('uid-mixed');
});

it('pages through the listing to find an account beyond the first page', function () {
    $firstPage = array_map(
        fn (int $i) => ['id' => "uid-{$i}", 'email' => "user{$i}@example.com"],
        range(1, 200),
    );

    Http::fakeSequence()
        ->push(['msg' => 'already registered'], 422)
        ->push(['users' => $firstPage], 200)
        ->push(['users' => [['id' => 'uid-late', 'email' => 'late@example.com']]], 200);

    expect(app(SupabaseAdminClient::class)->ensureUser('late@example.com', 'password'))
        ->toBe('uid-late');
});

it('fails loudly when the account can neither be created nor found', function () {
    Http::fakeSequence()
        ->push(['msg' => 'boom'], 500)
        ->push(['users' => []], 200);

    app(SupabaseAdminClient::class)->ensureUser('nobody@example.com', 'password');
})->throws(RuntimeException::class);

it('refuses admin calls when the project is not configured', function () {
    config()->set('supabase.secret_key', null);

    app(SupabaseAdminClient::class)->findIdByEmail('test@example.com');
})->throws(RuntimeException::class);
