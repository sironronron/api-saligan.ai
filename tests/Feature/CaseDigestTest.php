<?php

use App\Models\LegalCase;
use App\Models\MatterMemory;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;
use App\Services\Cases\CaseDigestService;
use App\Services\Crawler\LegalDigestService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('exposes a persisted case digest in the case API', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'digest' => 'Overview: A tenant dispute.',
        'digest_generated_at' => now(),
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/cases')
        ->assertOk()
        ->assertJsonPath('data.0.digest', 'Overview: A tenant dispute.')
        ->assertJsonPath('data.0.digest_generated_at', fn (mixed $value): bool => $value !== null);

    $this->signInAs($this->user)
        ->getJson("/api/cases/{$case->id}")
        ->assertOk()
        ->assertJsonPath('data.digest', 'Overview: A tenant dispute.');
});

it('assembles chats documents tasks deadlines and matter memory into the digest source', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'title' => 'Unpaid rent',
        'description' => 'The tenant missed three monthly payments.',
        'due_date' => '2026-10-01',
    ]);
    $conversation = $case->conversations()->create([
        'user_id' => $this->user->id,
        'purpose' => 'Research',
    ]);

    Message::factory()->for($conversation)->create([
        'role' => 'user',
        'content' => 'The lease ended in March.',
    ]);
    Todo::factory()->for($conversation)->create([
        'title' => 'File the complaint',
        'due_hint' => 'Within 15 days',
    ]);
    MatterMemory::factory()->create([
        'case_id' => $case->id,
        'organization_id' => $case->organization_id,
        'user_id' => $this->user->id,
        'type' => 'strategy',
        'content' => 'Send a demand letter first.',
    ]);

    $source = app(CaseDigestService::class)->source($case);

    expect($source)
        ->toContain('The tenant missed three monthly payments.')
        ->toContain('The lease ended in March.')
        ->toContain('File the complaint')
        ->toContain('Within 15 days')
        ->toContain('Send a demand letter first.')
        ->toContain('2026-10-01');
});

it('stores a digest only when the source is still current', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $digest = Mockery::mock(LegalDigestService::class);
    $digest->shouldReceive('generateCase')->once()->andReturn('Overview: Current case.');
    $this->app->instance(LegalDigestService::class, $digest);

    app(CaseDigestService::class)->generateAndStore($case->id);

    expect($case->fresh()->digest)->toBe('Overview: Current case.')
        ->and($case->fresh()->digest_generated_at)->not->toBeNull()
        ->and($case->fresh()->digest_source_hash)->not->toBeNull();
});
