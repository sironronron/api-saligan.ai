<?php

use App\Models\Document;
use App\Models\LegalCase;
use App\Models\MatterMemory;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('requires authentication', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->getJson("/api/cases/{$case->id}/progress")->assertStatus(401);
});

it('refuses to show the progress of another users case', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->signInAs($this->user)->getJson("/api/cases/{$case->id}/progress")->assertForbidden();
});

it('summarizes the whole progress of a case', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'in_progress',
        'due_date' => now()->addDays(10)->toDateString(),
    ]);

    $general = $case->conversations()->create(['user_id' => $this->user->id, 'purpose' => 'General']);
    $letter = $case->conversations()->create(['user_id' => $this->user->id, 'purpose' => 'Draft a letter']);

    Message::factory()->for($general)->create(['role' => 'user', 'content' => 'What are my rights?']);
    Message::factory()->for($general)->create(['role' => 'assistant', 'content' => 'Here is the answer.']);
    Message::factory()->for($letter)->create([
        'role' => 'assistant',
        'content' => 'Demand Letter',
        'metadata' => [
            'letter_draft' => [
                'content' => ['type' => 'doc', 'content' => []],
                'title' => 'Demand Letter',
            ],
        ],
    ]);

    Todo::factory()->for($general)->create(['title' => 'File the complaint', 'status' => 'completed']);
    Todo::factory()->for($general)->create(['title' => 'Gather receipts', 'status' => 'pending']);
    Todo::factory()->for($letter)->create([
        'title' => 'Serve the letter',
        'status' => 'pending',
        'due_date' => now()->subDay()->toDateString(),
    ]);

    Document::factory()->for($this->user)->ready()->create(['case_id' => $case->id]);
    Document::factory()->for($this->user)->create(['case_id' => $case->id]);

    MatterMemory::factory()->create([
        'case_id' => $case->id,
        'user_id' => $this->user->id,
        'organization_id' => $case->organization_id,
        'type' => 'fact',
        'content' => 'The lease ended in March.',
    ]);

    $response = $this->signInAs($this->user)->getJson("/api/cases/{$case->id}/progress")->assertOk();

    expect($response->json('data.case.id'))->toBe($case->id)
        ->and($response->json('data.progress.percent'))->toBe(33)
        ->and($response->json('data.progress.basis'))->toBe('tasks')
        ->and($response->json('data.deadline.days_remaining'))->toBe(10)
        ->and($response->json('data.deadline.overdue'))->toBeFalse()
        ->and($response->json('data.stats.threads'))->toBe(2)
        ->and($response->json('data.stats.messages'))->toBe(3)
        ->and($response->json('data.stats.user_messages'))->toBe(1)
        ->and($response->json('data.stats.tasks.total'))->toBe(3)
        ->and($response->json('data.stats.tasks.completed'))->toBe(1)
        ->and($response->json('data.stats.tasks.overdue'))->toBe(1)
        ->and($response->json('data.stats.documents.total'))->toBe(2)
        ->and($response->json('data.stats.documents.ready'))->toBe(1)
        ->and($response->json('data.stats.documents.processing'))->toBe(1)
        ->and($response->json('data.stats.generated_documents'))->toBe(1)
        ->and($response->json('data.generated_documents.0.title'))->toBe('Demand Letter')
        ->and($response->json('data.generated_documents.0.thread'))->toBe('Draft a letter')
        ->and($response->json('data.key_facts.fact.0.content'))->toBe('The lease ended in March.')
        ->and($response->json('data.threads'))->toHaveCount(2)
        ->and($response->json('data.timeline'))->not->toBeEmpty();
});

it('marks the stage track from the case status', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'closed']);

    $response = $this->signInAs($this->user)->getJson("/api/cases/{$case->id}/progress")->assertOk();

    expect($response->json('data.stages.0.state'))->toBe('done')
        ->and($response->json('data.stages.1.state'))->toBe('done')
        ->and($response->json('data.stages.2.state'))->toBe('active')
        ->and($response->json('data.progress.basis'))->toBe('status')
        ->and($response->json('data.progress.percent'))->toBe(100);
});

it('flags a case whose deadline has passed as overdue', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => now()->subDays(3)->toDateString(),
    ]);

    $this->signInAs($this->user)->getJson("/api/cases/{$case->id}/progress")
        ->assertOk()
        ->assertJsonPath('data.deadline.days_remaining', -3)
        ->assertJsonPath('data.deadline.overdue', true);
});

it('returns an empty but complete payload for a brand new case', function () {
    $case = LegalCase::factory()->for($this->user)->create(['due_date' => null]);

    $response = $this->signInAs($this->user)->getJson("/api/cases/{$case->id}/progress")->assertOk();

    expect($response->json('data.deadline'))->toBeNull()
        ->and($response->json('data.stats.messages'))->toBe(0)
        ->and($response->json('data.tasks'))->toBe([])
        ->and($response->json('data.documents'))->toBe([])
        ->and($response->json('data.timeline'))->toHaveCount(1)
        ->and($response->json('data.timeline.0.type'))->toBe('case_created');
});
