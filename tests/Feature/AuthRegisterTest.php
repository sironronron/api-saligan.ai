<?php

use App\Mail\ConfirmEmailMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config()->set('supabase.url', 'https://test.supabase.co');
    config()->set('supabase.secret_key', 'service-role-key');
    config()->set('app.frontend_url', 'https://app.batayan.ai');

    Mail::fake();
});

it('registers a new account and emails the confirmation link through Laravel', function () {
    Http::fake([
        '*/auth/v1/admin/generate_link' => Http::response([
            'action_link' => 'https://test.supabase.co/auth/v1/verify?token=abc',
        ], 200),
    ]);

    $this->postJson('/api/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'confirmation_sent');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test.supabase.co/auth/v1/admin/generate_link'
            && $request['type'] === 'signup'
            && $request['email'] === 'jane@example.com'
            && $request['data']['full_name'] === 'Jane Doe'
            && $request['redirect_to'] === 'https://app.batayan.ai/auth/verified';
    });

    Mail::assertSent(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) {
        return $mail->hasTo('jane@example.com')
            && $mail->confirmationUrl === 'https://test.supabase.co/auth/v1/verify?token=abc';
    });
});

it('answers identically when the address is already registered', function () {
    // Supabase reports an existing address as a 422. No new link is mailed to
    // a confirmed account, but the response must not reveal the difference.
    Http::fake([
        '*/auth/v1/admin/generate_link' => Http::response([
            'code' => 'user_already_exists',
            'msg' => 'A user with this email address has already been registered',
        ], 422),
    ]);

    $this->postJson('/api/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'confirmation_sent');

    Mail::assertNothingSent();
});

it('answers identically when provisioning fails for another reason', function () {
    Http::fake([
        '*/auth/v1/admin/generate_link' => Http::response(['msg' => 'boom'], 500),
    ]);

    $this->postJson('/api/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'confirmation_sent');

    Mail::assertNothingSent();
});

it('validates the registration payload', function () {
    $this->postJson('/api/auth/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

it('does not email when the admin client is not configured', function () {
    config()->set('supabase.secret_key', null);

    $this->postJson('/api/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'confirmation_sent');

    Mail::assertNothingSent();
});
