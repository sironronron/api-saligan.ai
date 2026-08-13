<?php

use App\Console\Commands\ArchiveClosedCases;
use App\Models\LegalCase;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('stamps closed_at when a case is closed through the status endpoint', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'open']);

    $this->signInAs($this->user)
        ->patchJson("/api/cases/{$case->id}/status", ['status' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    expect($case->fresh()->closed_at)->not->toBeNull();
});

it('clears closed_at when a closed case moves back to an open status', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => now()]);

    $this->signInAs($this->user)
        ->patchJson("/api/cases/{$case->id}/status", ['status' => 'on_hold'])
        ->assertOk();

    expect($case->fresh()->closed_at)->toBeNull();
});

it('restarts the archive countdown when a reopened case is closed again', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => now()->subDays(10)]);

    $this->signInAs($this->user)
        ->patchJson("/api/cases/{$case->id}/status", ['status' => 'on_hold'])
        ->assertOk();

    $this->travelTo(Carbon::parse('2026-08-01 12:00:00'));
    $this->signInAs($this->user)
        ->patchJson("/api/cases/{$case->id}/status", ['status' => 'closed'])
        ->assertOk();

    expect($case->fresh()->closed_at->toDateString())->toBe('2026-08-01');
});

it('stamps closed_at when a case is closed through the update endpoint', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'open']);

    $this->signInAs($this->user)
        ->patchJson("/api/cases/{$case->id}", [
            'title' => $case->title,
            'status' => 'closed',
        ])
        ->assertOk();

    expect($case->fresh()->closed_at)->not->toBeNull();
});

it('keeps the existing closed_at on unrelated edits to a closed case', function () {
    $closedAt = now()->subDays(3);
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => $closedAt]);

    $this->signInAs($this->user)
        ->patchJson("/api/cases/{$case->id}", [
            'title' => 'Renamed while closed',
            'status' => 'closed',
        ])
        ->assertOk();

    expect($case->fresh()->closed_at->timestamp)->toBe($closedAt->timestamp);
});

it('exposes closed_at in the case payload', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => now()]);

    $response = $this->signInAs($this->user)->getJson("/api/cases/{$case->id}")->assertOk();

    expect($response->json('data.closed_at'))->not->toBeNull();
});

it('archives closed cases whose grace period has elapsed', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00'));
    $expired = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => now()->subDays(31)]);
    $withinGrace = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => now()->subDays(29)]);
    $alreadyArchived = LegalCase::factory()->for($this->user)->archived()->create(['status' => 'closed', 'closed_at' => now()->subDays(40)]);
    $open = LegalCase::factory()->for($this->user)->create(['status' => 'open']);

    $this->artisan('cases:archive-closed')->assertSuccessful();

    expect($expired->fresh()->archived_at)->not->toBeNull()
        ->and($withinGrace->fresh()->archived_at)->toBeNull()
        ->and($alreadyArchived->fresh()->archived_at)->not->toBeNull()
        ->and($open->fresh()->archived_at)->toBeNull();
});

it('archives a closed case exactly 30 days after closing', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => now()->subDays(30)]);

    $this->artisan('cases:archive-closed')->assertSuccessful();

    expect($case->fresh()->archived_at)->not->toBeNull();
});

it('does not archive a closed case before the 30 day mark', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'closed_at' => now()->subDays(30)->addMinute()]);

    $this->artisan('cases:archive-closed')->assertSuccessful();

    expect($case->fresh()->archived_at)->toBeNull();
});

it('uses the archive command constant as the grace period', function () {
    expect(ArchiveClosedCases::ARCHIVE_AFTER_DAYS)->toBe(30);
});
