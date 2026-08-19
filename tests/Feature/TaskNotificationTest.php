<?php

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskCommented;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->owner = User::factory()->ownerOf($this->org)->create();
    Subscription::factory()->for($this->org)->for($this->owner)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
    $this->assignee = User::factory()->memberOf($this->org)->create(['name' => 'Maria Santos']);
    $this->conversation = Conversation::factory()->for($this->owner)->create();
});

it('notifies the assignee when a task is created assigned to them', function () {
    $this->signInAs($this->owner)
        ->postJson('/api/todos', [
            'conversation_id' => $this->conversation->id,
            'title' => 'File the complaint',
            'assignee' => 'Maria Santos',
        ])
        ->assertCreated();

    $todo = $this->owner->todos()->first();

    expect($todo)->not->toBeNull();

    $notification = $this->assignee->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe(TaskAssigned::class)
        ->and($notification->data['kind'])->toBe('task_assigned')
        ->and($notification->data['body'])->toBe('File the complaint')
        ->and($notification->data['url'])->toBe("/tasks/{$todo->id}")
        ->and($notification->data['assigned_by'])->toBe($this->owner->name);
});

it('notifies the assignee when a task is updated with a new assignee', function () {
    $todo = Todo::factory()->for($this->conversation)->create();

    $this->signInAs($this->owner)
        ->patchJson("/api/todos/{$todo->id}", ['assignee' => 'Maria Santos'])
        ->assertOk();

    $notification = $this->assignee->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe(TaskAssigned::class)
        ->and($notification->data['url'])->toBe("/tasks/{$todo->id}");
});

it('does not notify the assignee again when the assignee is unchanged', function () {
    $todo = Todo::factory()->for($this->conversation)->create(['assignee' => 'Maria Santos']);

    $this->signInAs($this->owner)
        ->patchJson("/api/todos/{$todo->id}", ['assignee' => 'Maria Santos', 'status' => 'on-going'])
        ->assertOk();

    expect($this->assignee->notifications()->count())->toBe(0);
});

it('does not notify the assignee when they assigned the task to themselves', function () {
    $assigneeConversation = Conversation::factory()->for($this->assignee)->create();

    $this->signInAs($this->assignee)
        ->postJson('/api/todos', [
            'conversation_id' => $assigneeConversation->id,
            'title' => 'Self assigned',
            'assignee' => 'Maria Santos',
        ])
        ->assertCreated();

    expect($this->assignee->notifications()->count())->toBe(0);
});

it('does not notify when the assignee is not a member of the organization', function () {
    $this->signInAs($this->owner)
        ->postJson('/api/todos', [
            'conversation_id' => $this->conversation->id,
            'title' => 'Outside assignee',
            'assignee' => 'Jane Doe',
        ])
        ->assertCreated();

    expect($this->assignee->notifications()->count())->toBe(0);
});

it('notifies the assignee when a comment is added to their task', function () {
    $todo = Todo::factory()->for($this->conversation)->create(['assignee' => 'Maria Santos']);

    $this->signInAs($this->owner)
        ->postJson("/api/todos/{$todo->id}/comments", ['body' => 'Please finish this today.'])
        ->assertCreated();

    $notification = $this->assignee->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe(TaskCommented::class)
        ->and($notification->data['kind'])->toBe('task_comment')
        ->and($notification->data['body'])->toBe('Please finish this today.')
        ->and($notification->data['commenter'])->toBe($this->owner->name)
        ->and($notification->data['url'])->toBe("/tasks/{$todo->id}");
});

it('does not notify the assignee when they comment on their own task', function () {
    $assigneeConversation = Conversation::factory()->for($this->assignee)->create();
    $todo = Todo::factory()->for($assigneeConversation)->create(['assignee' => 'Maria Santos']);

    $this->signInAs($this->assignee)
        ->postJson("/api/todos/{$todo->id}/comments", ['body' => 'Working on it.'])
        ->assertCreated();

    expect($this->assignee->notifications()->count())->toBe(0);
});
