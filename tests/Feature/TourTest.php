<?php

use App\Models\User;

it('records tour completion on the account', function () {
    $user = User::factory()->create(['tour_completed_at' => null]);

    $this->signInAs($user)
        ->postJson('/api/tour/complete')
        ->assertOk()
        ->assertJsonPath('data.tour_completed_at', fn ($value) => $value !== null);

    expect($user->fresh()->tour_completed_at)->not->toBeNull();
});

it('keeps the first completion timestamp when the tour is replayed', function () {
    $completedAt = now()->subDays(3);

    $user = User::factory()->create(['tour_completed_at' => $completedAt]);

    $this->signInAs($user)->postJson('/api/tour/complete')->assertOk();

    expect($user->fresh()->tour_completed_at->timestamp)->toBe($completedAt->timestamp);
});

it('exposes the flag on the user payload so a second device does not re-show the tour', function () {
    $user = User::factory()->create(['tour_completed_at' => now()]);

    $this->signInAs($user)
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.tour_completed_at', fn ($value) => $value !== null);
});

it('reports no completion for a user who has never seen the tour', function () {
    $user = User::factory()->create(['tour_completed_at' => null]);

    $this->signInAs($user)
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.tour_completed_at', null);
});

it('requires authentication to record completion', function () {
    $this->postJson('/api/tour/complete')->assertUnauthorized();
});
