<?php

use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
    $this->conversation = Conversation::factory()->for($this->user)->create();
});

it('requires authentication', function () {
    $this->getJson('/api/advisories')->assertStatus(401);
});

it('lists advisories scoped to the authenticated user', function () {
    $own = Advisory::factory()->for($this->conversation)->create();
    Advisory::factory()->for(Conversation::factory()->for(User::factory()))->create();

    $response = $this->signInAs($this->user)
        ->getJson('/api/advisories')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($own->id);
});

it('filters advisories by conversation', function () {
    $other = Conversation::factory()->for($this->user)->create();
    $inFirst = Advisory::factory()->for($this->conversation)->create();
    Advisory::factory()->for($other)->create();

    $response = $this->signInAs($this->user)
        ->getJson("/api/advisories?conversation_id={$this->conversation->id}")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($inFirst->id);
});

it('records the user answer with a note', function () {
    $advisory = Advisory::factory()->for($this->conversation)->create();

    $response = $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", [
            'status' => 'not_a_problem',
            'note' => 'The parties already settled this point.',
        ])
        ->assertOk();

    expect($response->json('data.status'))->toBe('not_a_problem')
        ->and($response->json('data.note'))->toBe('The parties already settled this point.')
        ->and($response->json('data.responded_at'))->not->toBeNull();
});

it('files a task when the advisory is tracked', function () {
    $advisory = Advisory::factory()->for($this->conversation)->create([
        'title' => 'Confirm the date of receipt of the demand letter',
        'detail' => 'The 15-day period cannot be computed without it.',
        'severity' => 'high',
    ]);

    $response = $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", ['status' => 'tracked'])
        ->assertOk();

    expect($response->json('data.todo_id'))->not->toBeNull();

    $this->assertDatabaseHas('todos', [
        'conversation_id' => $this->conversation->id,
        'title' => 'Confirm the date of receipt of the demand letter',
        'description' => 'The 15-day period cannot be computed without it.',
        'priority' => 'high',
    ]);
});

it('does not file a second task when the same advisory is tracked again', function () {
    $advisory = Advisory::factory()->for($this->conversation)->create();

    $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", ['status' => 'will_check'])
        ->assertOk();

    $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", ['status' => 'tracked'])
        ->assertOk();

    $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", ['status' => 'tracked'])
        ->assertOk();

    expect(Todo::query()->where('conversation_id', $this->conversation->id)->count())->toBe(1);
});

it('clears the answered timestamp when an advisory is reopened', function () {
    $advisory = Advisory::factory()->for($this->conversation)->answered()->create();

    $response = $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", ['status' => 'open'])
        ->assertOk();

    expect($response->json('data.responded_at'))->toBeNull();
});

it('rejects an unknown status', function () {
    $advisory = Advisory::factory()->for($this->conversation)->create();

    $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", ['status' => 'ignored'])
        ->assertStatus(422);
});

it('refuses to answer another user advisory', function () {
    $advisory = Advisory::factory()
        ->for(Conversation::factory()->for(User::factory()))
        ->create();

    $this->signInAs($this->user)
        ->patchJson("/api/advisories/{$advisory->id}", ['status' => 'mitigated'])
        ->assertStatus(403);
});

it('dismisses an advisory', function () {
    $advisory = Advisory::factory()->for($this->conversation)->create();

    $this->signInAs($this->user)
        ->deleteJson("/api/advisories/{$advisory->id}")
        ->assertStatus(204);

    $this->assertDatabaseMissing('advisories', ['id' => $advisory->id]);
});
