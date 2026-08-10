<?php

use App\Models\CrawledPage;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\LegalCase;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Models\User;
use App\Services\Retrieval\RetrievalService;
use App\Support\CitationTokens;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('retrieves legal context and the users own document chunks by similarity', function () {
    $source = LegalSource::factory()->create(['base_domain' => 'lawphil.net']);
    $page = CrawledPage::factory()->for($source)->create();
    $legalChunk = LegalChunk::factory()->for($page)->create([
        'content' => 'Comprehensive Agrarian Reform Program coverage rules.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $document = Document::factory()->for($this->user)->create();
    $docChunk = DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'My notes on agrarian reform.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $otherUser = User::factory()->create();
    $otherDocument = Document::factory()->for($otherUser)->create();
    DocumentChunk::factory()->for($otherDocument)->for($otherUser)->create([
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform');

    expect($result->legalChunks)->toHaveCount(1)
        ->and($result->legalChunks->first()->id)->toBe($legalChunk->id)
        ->and($result->documentChunks)->toHaveCount(1)
        ->and($result->documentChunks->first()->id)->toBe($docChunk->id)
        ->and($result->isEmpty())->toBeFalse()
        ->and($result->legalChunkIds())->toBe([$legalChunk->id])
        ->and($result->documentChunkIds())->toBe([$docChunk->id]);
});

it('filters chunks below the minimum similarity threshold', function () {
    $page = CrawledPage::factory()->create();
    LegalChunk::factory()->for($page)->create([
        'content' => 'Unrelated civil procedure text.',
        'embedding' => array_fill(0, 768, -1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform');

    expect($result->isEmpty())->toBeTrue();
});

it('scopes document retrieval to the documents attached to a case', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $otherCase = LegalCase::factory()->for($this->user)->create();

    $inCase = Document::factory()->for($this->user)->create(['case_id' => $case->id]);
    $inOtherCase = Document::factory()->for($this->user)->create(['case_id' => $otherCase->id]);

    $caseChunk = DocumentChunk::factory()->for($inCase)->for($this->user)->create([
        'content' => 'Agrarian reform notes inside this case.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);
    DocumentChunk::factory()->for($inOtherCase)->for($this->user)->create([
        'content' => 'Agrarian reform notes in another case.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $result = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform', $case);

    expect($result->documentChunks)->toHaveCount(1)
        ->and($result->documentChunks->first()->id)->toBe($caseChunk->id);
});

it('builds a context block with labeled sources', function () {
    $source = LegalSource::factory()->create(['name' => 'LawPhil']);
    $page = CrawledPage::factory()->for($source)->create(['law_name' => 'RA No. 6657']);
    LegalChunk::factory()->for($page)->create([
        'content' => 'Agrarian reform coverage.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $document = Document::factory()->for($this->user)->create([
        'title' => 'Case Notes',
        'original_filename' => 'case-notes.pdf',
    ]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'My notes.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $tokens = CitationTokens::assign([(string) $page->id, (string) $document->id]);

    $block = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform')->contextBlock();

    expect($block)
        ->toContain('[SRC '.$tokens[(string) $page->id].']')
        ->toContain('RA No. 6657')
        ->toContain('[DOC '.$tokens[(string) $document->id].']')
        ->toContain('case-notes.pdf');
});

it('labels each distinct source exactly once when it has multiple chunks', function () {
    $source = LegalSource::factory()->create(['name' => 'LawPhil']);
    $page = CrawledPage::factory()->for($source)->create(['law_name' => 'RA No. 6657']);
    LegalChunk::factory()->for($page)->create([
        'content' => 'First section of RA 6657.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);
    LegalChunk::factory()->for($page)->create([
        'content' => 'Second section of RA 6657.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    $document = Document::factory()->for($this->user)->create([
        'title' => 'Case Notes',
        'original_filename' => 'case-notes.pdf',
    ]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'First note.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'content' => 'Second note.',
        'embedding' => array_fill(0, 768, 1.0),
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $tokens = CitationTokens::assign([(string) $page->id, (string) $document->id]);
    $srcMarker = '[SRC '.$tokens[(string) $page->id].']';
    $docMarker = '[DOC '.$tokens[(string) $document->id].']';

    $block = app(RetrievalService::class)->retrieve($this->user, 'agrarian reform')->contextBlock();

    expect($block)
        ->toContain($srcMarker)
        ->toContain('First section of RA 6657.')
        ->toContain('Second section of RA 6657.')
        ->toContain($docMarker)
        ->toContain('case-notes.pdf');

    expect(substr_count($block, $srcMarker))->toBe(1)
        ->and(substr_count($block, $docMarker))->toBe(1);
});
