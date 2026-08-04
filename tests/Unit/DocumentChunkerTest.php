<?php

use App\Services\Documents\DocumentChunker;

it('returns a single chunk for short text', function () {
    $chunks = (new DocumentChunker)->chunk('A short legal note.');

    expect($chunks)->toBe(['A short legal note.']);
});

it('returns no chunks for empty text', function () {
    expect((new DocumentChunker)->chunk(''))->toBe([]);
});

it('splits long text into paragraph-aware chunks', function () {
    $paragraph = str_repeat('agrarian reform coverage and compensation. ', 60);
    $text = $paragraph."\n\n".$paragraph;

    $chunks = (new DocumentChunker)->chunk($text, size: 100, overlap: 20);

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect(count(preg_split('/\s+/', trim($chunk))))->toBeLessThanOrEqual(120);
    }
});

it('does not produce overlapping duplicate chunks when text fits in one', function () {
    $chunks = (new DocumentChunker)->chunk('agrarian reform. '.str_repeat('x ', 20), size: 500, overlap: 50);

    expect($chunks)->toHaveCount(1);
});

it('preserves paragraph boundaries in output', function () {
    $text = "First paragraph about RA 6657.\n\nSecond paragraph about compensation.";
    $chunks = (new DocumentChunker)->chunk($text, size: 500, overlap: 50);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toContain('First paragraph')
        ->and($chunks[0])->toContain('Second paragraph');
});
