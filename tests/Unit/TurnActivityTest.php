<?php

use App\Support\TurnActivity;

it('reports nothing for a turn that only wrote an answer', function () {
    // One step is the answer being composed, which the answer itself already
    // demonstrates — a "how this was worked out" line with one entry saying
    // "Writing your answer" is noise under every reply.
    $activity = new TurnActivity;
    $activity->start();
    $activity->step('composing', 'Writing your answer');

    expect($activity->toArray())->toBeNull();
});

it('records the steps a turn went through, in order, once each', function () {
    $activity = new TurnActivity;
    $activity->start();
    $activity->step('checking_sources', 'Checking legal sources');
    $activity->step('searching_web', 'Searching the web');
    $activity->step('composing', 'Writing your answer');
    // The model searched again mid-answer; the step is already recorded.
    $activity->step('searching_web', 'Searching the web');

    expect($activity->toArray()['steps'])->toBe([
        ['key' => 'checking_sources', 'label' => 'Checking legal sources'],
        ['key' => 'searching_web', 'label' => 'Searching the web'],
        ['key' => 'composing', 'label' => 'Writing your answer'],
    ]);
});

it('keeps the label a step was first seen with', function () {
    $activity = new TurnActivity;
    $activity->start();
    $activity->step('drafting_document', 'Drafting your demand letter');
    $activity->step('drafting_document', 'Drafting your document');
    $activity->step('composing', 'Writing your answer');

    expect($activity->toArray()['steps'][0]['label'])->toBe('Drafting your demand letter');
});

it('reports a single-step turn once it has read the web', function () {
    // The sources are themselves worth accounting for, whatever the step count.
    $activity = new TurnActivity;
    $activity->start();
    $activity->step('composing', 'Writing your answer');
    $activity->countWebSources(3);

    expect($activity->toArray())
        ->not->toBeNull()
        ->and($activity->toArray()['web_sources'])->toBe(3);
});

it('never lets a later count reduce the sources already recorded', function () {
    $activity = new TurnActivity;
    $activity->start();
    $activity->step('checking_sources', 'Checking legal sources');
    $activity->step('composing', 'Writing your answer');
    $activity->countWebSources(4);
    $activity->countWebSources(1);

    expect($activity->toArray()['web_sources'])->toBe(4);
});

it('times the turn from the moment it started', function () {
    $activity = new TurnActivity;
    $activity->start();
    $activity->step('checking_sources', 'Checking legal sources');
    $activity->step('composing', 'Writing your answer');

    expect($activity->toArray()['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('clears the previous turn when a new one starts', function () {
    $activity = new TurnActivity;
    $activity->start();
    $activity->step('checking_sources', 'Checking legal sources');
    $activity->step('composing', 'Writing your answer');
    $activity->countWebSources(2);

    $activity->start();
    $activity->step('composing', 'Writing your answer');

    expect($activity->toArray())->toBeNull();
});
