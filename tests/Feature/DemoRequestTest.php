<?php

use App\Models\DemoRequest;
use Illuminate\Support\Facades\Http;

function fakeSiteverify(bool $success, float $score = 0.9): void
{
    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => $success,
            'score' => $score,
            'hostname' => 'batayan.co',
        ]),
    ]);
}

beforeEach(function () {
    config()->set('services.recaptcha.secret_key', 'test-secret');
    config()->set('services.recaptcha.min_score', 0.5);
});

it('requires the contact fields and a recaptcha token', function () {
    $this->postJson('/api/demo-requests', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'recaptcha_token']);
});

it('rejects the request when recaptcha is not configured', function () {
    config()->set('services.recaptcha.secret_key', null);

    $this->postJson('/api/demo-requests', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'recaptcha_token' => 'token',
    ])->assertUnprocessable();
});

it('rejects a failed recaptcha verification', function () {
    fakeSiteverify(false);

    $this->postJson('/api/demo-requests', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'recaptcha_token' => 'token',
    ])->assertUnprocessable();
});

it('rejects a recaptcha score below the threshold', function () {
    fakeSiteverify(true, 0.3);

    $this->postJson('/api/demo-requests', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'recaptcha_token' => 'token',
    ])->assertUnprocessable();
});

it('stores a demo request when verification passes', function () {
    fakeSiteverify(true, 0.9);

    $this->postJson('/api/demo-requests', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'organization' => 'Doe & Partners',
        'message' => 'Interested in a firm demo.',
        'recaptcha_token' => 'token',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $request = DemoRequest::query()->where('email', 'jane@example.com')->firstOrFail();

    expect($request->organization)->toBe('Doe & Partners')
        ->and($request->message)->toBe('Interested in a firm demo.')
        ->and($request->recaptcha_score)->toBe(0.9)
        ->and($request->ip_address)->toBe('127.0.0.1');
});

it('rejects an email that is not lowercase', function () {
    fakeSiteverify(true);

    $this->postJson('/api/demo-requests', [
        'name' => 'Jane Doe',
        'email' => 'Jane@Example.COM',
        'recaptcha_token' => 'token',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('throttles demo requests per IP', function () {
    fakeSiteverify(true);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/demo-requests', [
            'name' => 'Jane Doe',
            'email' => "jane{$i}@example.com",
            'recaptcha_token' => 'token',
        ])->assertCreated();
    }

    $this->postJson('/api/demo-requests', [
        'name' => 'Jane Doe',
        'email' => 'overflow@example.com',
        'recaptcha_token' => 'token',
    ])->assertStatus(429);
});

it('throttles demo requests per email', function () {
    fakeSiteverify(true);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/demo-requests', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'recaptcha_token' => 'token',
        ])->assertCreated();
    }

    $this->postJson('/api/demo-requests', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'recaptcha_token' => 'token',
    ])->assertStatus(429);
});
