<?php

use App\Models\User;
use Illuminate\Support\Carbon;

it('records the first API request as the last-used time', function () {
    $user = User::factory()->create(['last_used_at' => null]);

    $this->signInAs($user)->getJson('/api/legal-pages/resolve?url=example.org/authority')->assertOk();

    expect($user->fresh()->last_used_at)->not->toBeNull();
});

it('does not rewrite the last-used time on every request', function () {
    $stamp = now()->subMinute();
    $user = User::factory()->create(['last_used_at' => $stamp]);

    $this->signInAs($user)->getJson('/api/legal-pages/resolve?url=example.org/authority')->assertOk();
    $this->signInAs($user)->getJson('/api/legal-pages/resolve?url=example.org/authority')->assertOk();

    expect($user->fresh()->last_used_at->toDateTimeString())->toBe($stamp->toDateTimeString());
});

it('advances the last-used time once it has gone stale', function () {
    Carbon::setTestNow(now()->subDay());
    $user = User::factory()->create(['last_used_at' => now()->subDay()]);

    Carbon::setTestNow();

    $this->signInAs($user)->getJson('/api/legal-pages/resolve?url=example.org/authority')->assertOk();

    expect($user->fresh()->last_used_at->toDateTimeString())->toBe(now()->toDateTimeString());

    Carbon::setTestNow();
});

it('reports the last-used time for a known account', function () {
    $stamp = now()->subDays(3);
    $user = User::factory()->create(['last_used_at' => $stamp]);

    $response = $this->getJson('/api/auth/last-used?email='.$user->email)->assertOk();

    expect($response->json('exists'))->toBeTrue()
        ->and(Carbon::parse($response->json('last_used_at'))->toDateTimeString())->toBe($stamp->toDateTimeString());
});

it('reports an account that exists but has never used the app', function () {
    $user = User::factory()->create(['last_used_at' => null]);

    $this->getJson('/api/auth/last-used?email='.$user->email)
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('last_used_at', null);
});

it('reports that an unknown email has no account so sign-in can refuse it', function () {
    $this->getJson('/api/auth/last-used?email=unknown@example.com')
        ->assertOk()
        ->assertJsonPath('exists', false)
        ->assertJsonPath('last_used_at', null);
});
