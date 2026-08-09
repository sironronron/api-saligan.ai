<?php

use App\Ai\Tools\CreateTodoTool;
use App\Models\Conversation;
use App\Models\User;
use Laravel\Ai\Tools\Request;

it('creates todos from tool items', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new CreateTodoTool($conversation->id);

    $request = new Request([
        'items' => [
            ['title' => 'File the complaint at the RTC', 'status' => 'pending', 'priority' => 'high', 'due_hint' => 'Within 15 days'],
            ['title' => 'Gather land title (TCT)', 'status' => 'pending'],
        ],
    ]);

    $result = json_decode($tool->handle($request), true);

    expect($result['items'])->toHaveCount(2)
        ->and($result['items'][0]['title'])->toBe('File the complaint at the RTC')
        ->and($result['items'][0]['priority'])->toBe('high');

    $this->assertDatabaseHas('todos', [
        'conversation_id' => $conversation->id,
        'title' => 'Gather land title (TCT)',
        'status' => 'pending',
    ]);
});

it('defaults missing status fields', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new CreateTodoTool($conversation->id);

    $request = new Request([
        'items' => [
            ['title' => 'Attend mediation'],
        ],
    ]);

    $result = json_decode($tool->handle($request), true);

    expect($result['items'][0]['status'])->toBe('pending')
        ->and($result['items'][0]['priority'])->toBeNull();
});

it('fires the preparing_next_steps status when the todo tool runs', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $statuses = [];

    $tool = new CreateTodoTool($conversation->id, function (string $status, ?string $label = null) use (&$statuses): void {
        $statuses[] = [$status, $label];
    });

    $tool->handle(new Request([
        'items' => [['title' => 'File the complaint at the RTC', 'status' => 'pending']],
    ]));

    expect($statuses)->toBe([['preparing_next_steps', null]]);
});
