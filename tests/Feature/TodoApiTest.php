<?php

use App\Models\Conversation;
use App\Models\Todo;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->conversation = Conversation::factory()->for($this->user)->create();
});

it('requires authentication', function () {
    $this->getJson('/api/todos')->assertStatus(401);
});

it('lists todos scoped to the authenticated user', function () {
    $ownTodo = Todo::factory()->for($this->conversation)->create();
    Todo::factory()->for(Conversation::factory()->for(User::factory()))->create();

    $response = $this->actingAs($this->user)
        ->getJson('/api/todos')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($ownTodo->id);
});

it('filters todos by conversation', function () {
    $other = Conversation::factory()->for($this->user)->create();
    $inFirst = Todo::factory()->for($this->conversation)->create();
    $inOther = Todo::factory()->for($other)->create();

    $response = $this->actingAs($this->user)
        ->getJson("/api/todos?conversation_id={$this->conversation->id}")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($inFirst->id);
});

it('filters todos by status', function () {
    $pending = Todo::factory()->for($this->conversation)->create(['status' => 'pending']);
    $completed = Todo::factory()->for($this->conversation)->completed()->create();

    $response = $this->actingAs($this->user)
        ->getJson('/api/todos?status=completed')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($completed->id);
});

it('updates a todo status', function () {
    $todo = Todo::factory()->for($this->conversation)->create(['status' => 'pending']);

    $this->actingAs($this->user)
        ->patchJson("/api/todos/{$todo->id}", ['status' => 'on-going'])
        ->assertOk()
        ->assertJsonPath('data.status', 'on-going');

    $this->assertDatabaseHas('todos', ['id' => $todo->id, 'status' => 'on-going']);
});

it('creates a todo', function () {
    $this->actingAs($this->user)
        ->postJson('/api/todos', [
            'conversation_id' => $this->conversation->id,
            'title' => 'File the complaint',
            'priority' => 'high',
            'due_hint' => 'Within 15 days',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'File the complaint');

    $this->assertDatabaseHas('todos', [
        'conversation_id' => $this->conversation->id,
        'title' => 'File the complaint',
        'status' => 'pending',
    ]);
});

it('does not allow creating a todo for another users conversation', function () {
    $other = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->postJson('/api/todos', [
            'conversation_id' => $other->id,
            'title' => 'Nope',
        ])
        ->assertForbidden();
});

it('does not allow updating another users todo', function () {
    $other = Todo::factory()->for(Conversation::factory()->for(User::factory()))->create();

    $this->actingAs($this->user)
        ->patchJson("/api/todos/{$other->id}", ['status' => 'completed'])
        ->assertForbidden();
});

it('rejects an invalid status', function () {
    $todo = Todo::factory()->for($this->conversation)->create();

    $this->actingAs($this->user)
        ->patchJson("/api/todos/{$todo->id}", ['status' => 'cancelled'])
        ->assertUnprocessable();
});

it('deletes a todo', function () {
    $todo = Todo::factory()->for($this->conversation)->create();

    $this->actingAs($this->user)
        ->deleteJson("/api/todos/{$todo->id}")
        ->assertOk();

    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
});
