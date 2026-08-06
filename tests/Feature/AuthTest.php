<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

it('registers a new user', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'jane@example.com');

    $this->assertDatabaseHas('users', [
        'email' => 'jane@example.com',
        'is_admin' => false,
    ]);
});

it('rejects registration with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('logs a user in with valid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'secret123',
    ])->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.is_admin', false);
});

it('rejects login with invalid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'The provided credentials do not match our records.');
});

it('requires authentication for the user endpoint', function () {
    $this->getJson('/api/user')->assertStatus(401);
});

it('returns the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});

it('logs the user out and destroys the session', function () {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'secret123',
    ])->assertOk();

    // Login regenerates the session, which rotates the CSRF token.
    $this->withHeader('X-CSRF-TOKEN', csrf_token());

    $this->postJson('/api/logout')->assertNoContent();

    // Sanctum's RequestGuard caches the resolved user on the guard instance,
    // so drop the cached guards before issuing the follow-up request.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/user')->assertStatus(401);
});

it('sends a password reset link for a known email', function () {
    $user = User::factory()->create();

    $this->postJson('/api/forgot-password', ['email' => $user->email])
        ->assertOk();
});

it('does not reveal whether an email exists on forgot password', function () {
    $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])
        ->assertOk();
});

it('resets a password with a valid token', function () {
    $user = User::factory()->create();

    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'new-secret-123',
        'password_confirmation' => 'new-secret-123',
    ])->assertOk();

    expect(Hash::check('new-secret-123', $user->fresh()->password))->toBeTrue();
});
