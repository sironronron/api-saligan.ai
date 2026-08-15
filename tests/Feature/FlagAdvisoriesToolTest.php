<?php

use App\Ai\Tools\FlagAdvisoriesTool;
use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\User;
use Laravel\Ai\Tools\Request;

it('files advisories from tool items', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new FlagAdvisoriesTool($conversation->id);

    $request = new Request([
        'items' => [
            [
                'kind' => 'deadline',
                'title' => 'The 15-day period to answer runs from receipt, which is unconfirmed',
                'detail' => 'Confirm the date the summons was served before computing the deadline.',
                'severity' => 'high',
            ],
            [
                'kind' => 'assumption',
                'title' => 'The lot is assumed to be untenanted',
                'severity' => 'medium',
            ],
        ],
    ]);

    $result = json_decode($tool->handle($request), true);

    expect($result['items'])->toHaveCount(2)
        ->and($result['items'][0]['kind'])->toBe('deadline')
        ->and($result['items'][0]['severity'])->toBe('high');

    $this->assertDatabaseHas('advisories', [
        'conversation_id' => $conversation->id,
        'kind' => 'assumption',
        'title' => 'The lot is assumed to be untenanted',
        'status' => 'open',
    ]);
});

it('falls back to safe values when the model supplies an unknown kind or severity', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new FlagAdvisoriesTool($conversation->id);

    $result = json_decode($tool->handle(new Request([
        'items' => [
            ['kind' => 'catastrophe', 'title' => 'Something to watch', 'severity' => 'urgent'],
        ],
    ])), true);

    expect($result['items'][0]['kind'])->toBe('caveat')
        ->and($result['items'][0]['severity'])->toBe('medium');
});

it('skips items with no title', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new FlagAdvisoriesTool($conversation->id);

    $result = json_decode($tool->handle(new Request([
        'items' => [
            ['title' => '   '],
            ['title' => 'Registration with the Registry of Deeds is still required'],
        ],
    ])), true);

    expect($result['items'])->toHaveCount(1);
});

it('discards generic disclaimers so the list stays worth reading', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new FlagAdvisoriesTool($conversation->id);

    $result = json_decode($tool->handle(new Request([
        'items' => [
            ['title' => 'Consult a licensed Philippine attorney before filing'],
            ['title' => 'This is not legal advice'],
            ['title' => 'Laws and regulations may change'],
            ['title' => 'The deed must be reviewed by a lawyer'],
            ['title' => 'The property is still covered by a CLOA, so the five-year alienation ban under RA 6657 may bar the sale'],
        ],
    ])), true);

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['title'])->toContain('CLOA');
});

it('keeps a specific point that happens to mention a lawyer review', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new FlagAdvisoriesTool($conversation->id);

    $title = 'The retention-limit computation under DAR AO 2 turns on the date of coverage and should be reviewed by your lawyer before filing';

    $result = json_decode($tool->handle(new Request([
        'items' => [['title' => $title]],
    ])), true);

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['title'])->toBe($title);
});

it('does not raise the same point twice on one conversation', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $tool = new FlagAdvisoriesTool($conversation->id);

    $tool->handle(new Request([
        'items' => [['title' => 'The date of receipt of the demand letter is unconfirmed']],
    ]));

    // Re-raised on a later turn in slightly different wording — same point.
    $result = json_decode($tool->handle(new Request([
        'items' => [
            ['title' => 'The date of receipt of the demand letter is unconfirmed.'],
            ['title' => 'The Deed of Sale was never registered with the Registry of Deeds'],
        ],
    ])), true);

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['title'])->toContain('Registry of Deeds');

    expect(Advisory::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});

it('does not dedupe across conversations', function () {
    $user = User::factory()->create();
    $first = Conversation::factory()->for($user)->create();
    $second = Conversation::factory()->for($user)->create();

    $title = 'The date of receipt of the demand letter is unconfirmed';

    (new FlagAdvisoriesTool($first->id))->handle(new Request(['items' => [['title' => $title]]]));
    $result = json_decode((new FlagAdvisoriesTool($second->id))->handle(new Request([
        'items' => [['title' => $title]],
    ])), true);

    expect($result['items'])->toHaveCount(1);
});

it('fires the reviewing_gaps status when the tool runs', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $statuses = [];

    $tool = new FlagAdvisoriesTool($conversation->id, function (string $status, ?string $label = null) use (&$statuses): void {
        $statuses[] = $status;
    });

    $tool->handle(new Request(['items' => [['title' => 'Watch the prescriptive period']]]));

    expect($statuses)->toContain('reviewing_gaps');
});
