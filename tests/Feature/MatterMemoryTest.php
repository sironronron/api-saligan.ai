<?php

use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\User;
use App\Services\MatterMemory\MatterMemoryService;
use App\Services\MatterMemory\MemoryWriteBackParser;

test('matter memory service stores and retrieves memories', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $memory = $service->store($case, $user, 'fact', 'The hearing is scheduled for March 15, 2026');

    expect($memory->organization_id)->toBe($organization->id)
        ->and($memory->case_id)->toBe($case->id)
        ->and($memory->user_id)->toBe($user->id)
        ->and($memory->type)->toBe('fact')
        ->and($memory->content)->toBe('The hearing is scheduled for March 15, 2026')
        ->and($memory->is_active)->toBeTrue();

    $memories = $service->getMemories($case);
    expect($memories)->toHaveCount(1)
        ->and($memories->first()->id)->toBe($memory->id);
});

test('matter memory service scopes by case', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $caseA = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);
    $caseB = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($caseA, $user, 'fact', 'Fact for case A');
    $service->store($caseB, $user, 'fact', 'Fact for case B');

    $memoriesA = $service->getMemories($caseA);
    $memoriesB = $service->getMemories($caseB);

    expect($memoriesA)->toHaveCount(1)
        ->and($memoriesA->first()->content)->toBe('Fact for case A')
        ->and($memoriesB)->toHaveCount(1)
        ->and($memoriesB->first()->content)->toBe('Fact for case B');
});

test('matter memory service filters by type', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'A fact');
    $service->store($case, $user, 'strategy', 'A strategy');
    $service->store($case, $user, 'deadline', 'A deadline');

    $facts = $service->getMemories($case, 'fact');
    expect($facts)->toHaveCount(1)
        ->and($facts->first()->content)->toBe('A fact');

    $strategies = $service->getMemories($case, 'strategy');
    expect($strategies)->toHaveCount(1)
        ->and($strategies->first()->content)->toBe('A strategy');
});

test('matter memory service detects duplicates', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'The hearing is March 15');

    expect($service->existsSimilar($case, 'fact', 'The hearing is March 15'))->toBeTrue()
        ->and($service->existsSimilar($case, 'fact', 'Different fact'))->toBeFalse()
        ->and($service->existsSimilar($case, 'strategy', 'The hearing is March 15'))->toBeFalse();
});

test('matter memory service blocks writes on legal hold', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'retention_status' => 'on-legal-hold',
    ]);

    $service = new MatterMemoryService;

    expect($service->canWrite($case))->toBeFalse()
        ->and($service->isOnLegalHold($case))->toBeTrue();
});

test('matter memory service blocks writes on pending deletion', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'retention_status' => 'closed-pending-deletion',
    ]);

    $service = new MatterMemoryService;

    expect($service->canWrite($case))->toBeFalse();
});

test('matter memory service allows writes on active cases', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'retention_status' => 'active',
    ]);

    $service = new MatterMemoryService;

    expect($service->canWrite($case))->toBeTrue()
        ->and($service->isOnLegalHold($case))->toBeFalse();
});

test('matter memory service generates memory block for prompt', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'The hearing is March 15');
    $service->store($case, $user, 'strategy', 'Focus on procedural defects');

    $block = $service->getMemoryBlock($case);

    expect($block)->toContain('[fact]')
        ->and($block)->toContain('The hearing is March 15')
        ->and($block)->toContain('[[UNTRUSTED DATA START]]')
        ->and($block)->toContain('[[UNTRUSTED DATA END]]')
        ->and($block)->toContain('[strategy]')
        ->and($block)->toContain('Focus on procedural defects');
});

test('matter memory service returns empty message when no memories', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $block = $service->getMemoryBlock($case);

    expect($block)->toBe('No matter-specific memory entries recorded for this matter.');
});

test('memory write back parser extracts and stores valid blocks', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $parser = new MemoryWriteBackParser;
    $service = new MatterMemoryService;

    $text = "Here is the analysis.\n\n[[MEMORY_WRITE_START]] matter={$case->id} type=fact content: The hearing is scheduled for March 15 [[MEMORY_WRITE_END]]\n\nPlease review.";

    $cleaned = $parser->parseAndStore($text, $case, $user, $service);

    expect($cleaned)->not->toContain('MEMORY_WRITE_START')
        ->and($cleaned)->toContain('Here is the analysis.')
        ->and($cleaned)->toContain('Please review.');

    $memories = $service->getMemories($case, 'fact');
    expect($memories)->toHaveCount(1)
        ->and($memories->first()->content)->toBe('The hearing is scheduled for March 15');
});

test('memory write back parser handles multiple blocks', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $parser = new MemoryWriteBackParser;
    $service = new MatterMemoryService;

    $text = "Analysis.\n\n[[MEMORY_WRITE_START]] matter={$case->id} type=fact content: Fact one [[MEMORY_WRITE_END]]\n\n[[MEMORY_WRITE_START]] matter={$case->id} type=deadline content: Due March 20 [[MEMORY_WRITE_END]]\n\nDone.";

    $cleaned = $parser->parseAndStore($text, $case, $user, $service);

    expect($cleaned)->not->toContain('MEMORY_WRITE_START');

    $facts = $service->getMemories($case, 'fact');
    $deadlines = $service->getMemories($case, 'deadline');

    expect($facts)->toHaveCount(1)
        ->and($facts->first()->content)->toBe('Fact one')
        ->and($deadlines)->toHaveCount(1)
        ->and($deadlines->first()->content)->toBe('Due March 20');
});

test('memory write back parser rejects malformed blocks', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $parser = new MemoryWriteBackParser;
    $service = new MatterMemoryService;

    // Missing closing tag
    $text1 = "Text.\n\n[[MEMORY_WRITE_START]] matter={$case->id} type=fact content: Incomplete block";
    $cleaned1 = $parser->parseAndStore($text1, $case, $user, $service);
    expect($cleaned1)->toContain('MEMORY_WRITE_START');

    // Wrong field names
    $text2 = "Text.\n\n[[MEMORY_WRITE_START]] case={$case->id} kind=fact payload: Wrong fields [[MEMORY_WRITE_END]]";
    $cleaned2 = $parser->parseAndStore($text2, $case, $user, $service);
    expect($cleaned2)->toContain('MEMORY_WRITE_START');

    // Invalid type
    $text3 = "Text.\n\n[[MEMORY_WRITE_START]] matter={$case->id} type=invalid content: Bad type [[MEMORY_WRITE_END]]";
    $cleaned3 = $parser->parseAndStore($text3, $case, $user, $service);
    expect($cleaned3)->toContain('MEMORY_WRITE_START');

    $memories = $service->getMemories($case);
    expect($memories)->toHaveCount(0);
});

test('memory write back parser rejects wrong matter id', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);
    $otherCase = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $parser = new MemoryWriteBackParser;
    $service = new MatterMemoryService;

    $text = "Text.\n\n[[MEMORY_WRITE_START]] matter={$otherCase->id} type=fact content: Wrong matter [[MEMORY_WRITE_END]]";

    $cleaned = $parser->parseAndStore($text, $case, $user, $service);

    expect($cleaned)->toContain('MEMORY_WRITE_START');

    $memories = $service->getMemories($case);
    expect($memories)->toHaveCount(0);
});

test('memory write back parser skips duplicates', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $parser = new MemoryWriteBackParser;
    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'The hearing is March 15');

    $text = "Text.\n\n[[MEMORY_WRITE_START]] matter={$case->id} type=fact content: The hearing is March 15 [[MEMORY_WRITE_END]]";

    $parser->parseAndStore($text, $case, $user, $service);

    $memories = $service->getMemories($case, 'fact');
    expect($memories)->toHaveCount(1);
});

test('memory write back parser blocks writes on legal hold', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'retention_status' => 'on-legal-hold',
    ]);

    $parser = new MemoryWriteBackParser;
    $service = new MatterMemoryService;

    $text = "Text.\n\n[[MEMORY_WRITE_START]] matter={$case->id} type=fact content: Should not be stored [[MEMORY_WRITE_END]]";

    $parser->parseAndStore($text, $case, $user, $service);

    $memories = $service->getMemories($case);
    expect($memories)->toHaveCount(0);
});

test('memory write back parser handles text without blocks', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $parser = new MemoryWriteBackParser;
    $service = new MatterMemoryService;

    $text = 'This is a normal response without any write-back blocks.';

    $cleaned = $parser->parseAndStore($text, $case, $user, $service);

    expect($cleaned)->toBe($text);

    $memories = $service->getMemories($case);
    expect($memories)->toHaveCount(0);
});

test('memory write back parser detects blocks in text', function () {
    $parser = new MemoryWriteBackParser;

    $textWithBlock = "Text\n\n[[MEMORY_WRITE_START]] matter=123 type=fact content: Something [[MEMORY_WRITE_END]]";
    $textWithoutBlock = 'Text without any blocks.';

    expect($parser->hasWriteBackBlocks($textWithBlock))->toBeTrue()
        ->and($parser->hasWriteBackBlocks($textWithoutBlock))->toBeFalse();
});
