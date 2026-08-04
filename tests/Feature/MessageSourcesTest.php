<?php

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\CrawledPage;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageSources;

it('resolves legal and document source cards actually cited by a message', function () {
    $user = User::factory()->create();

    $source = LegalSource::factory()->create(['name' => 'LawPhil']);
    $page = CrawledPage::factory()->for($source)->create([
        'law_name' => 'RA No. 6657',
        'gr_number' => null,
    ]);
    $legalChunk = LegalChunk::factory()->for($page)->create([
        'content' => 'The Comprehensive Agrarian Reform Law covers all public agricultural lands.',
    ]);

    $document = Document::factory()->for($user)->create([
        'original_filename' => 'case-brief.pdf',
        'title' => 'Case Brief',
    ]);
    $docChunk = DocumentChunk::factory()->for($document)->for($user)->create([
        'content' => 'My notes on the case.',
    ]);

    $message = Message::factory()->create([
        'role' => MessageRole::Assistant,
        'provider' => ChatProvider::Ollama,
        'content' => 'Under [Source 1], agrarian reform applies. See also [User Doc 1].',
        'cited_legal_chunk_ids' => [$legalChunk->id],
        'cited_chunk_ids' => [$docChunk->id],
    ]);

    $sources = MessageSources::for($message);

    expect($sources)->toHaveCount(2)
        ->and($sources[0]['type'])->toBe('legal')
        ->and($sources[0]['label'])->toBe('RA No. 6657')
        ->and($sources[0]['source_name'])->toBe('LawPhil')
        ->and($sources[0]['url'])->toBe($page->url)
        ->and($sources[0]['excerpt'])->toContain('Comprehensive Agrarian Reform Law')
        ->and($sources[1]['type'])->toBe('document')
        ->and($sources[1]['label'])->toBe('case-brief.pdf');
});

it('omits retrieved sources that were not cited inline', function () {
    $user = User::factory()->create();

    $firstPage = CrawledPage::factory()->create(['law_name' => 'RA No. 6657']);
    $secondPage = CrawledPage::factory()->create(['law_name' => 'RA No. 8371']);
    $firstChunk = LegalChunk::factory()->for($firstPage)->create(['content' => 'Agrarian reform coverage.']);
    $secondChunk = LegalChunk::factory()->for($secondPage)->create(['content' => 'IPRA coverage.']);

    $document = Document::factory()->for($user)->create(['original_filename' => 'case-brief.pdf']);
    $docChunk = DocumentChunk::factory()->for($document)->for($user)->create(['content' => 'My notes.']);

    $message = Message::factory()->create([
        'content' => 'Only the first source was used [Source 1].',
        'cited_legal_chunk_ids' => [$firstChunk->id, $secondChunk->id],
        'cited_chunk_ids' => [$docChunk->id],
    ]);

    $sources = MessageSources::for($message);

    expect($sources)->toHaveCount(1)
        ->and($sources[0]['label'])->toBe('RA No. 6657')
        ->and(collect($sources)->pluck('label'))->not->toContain('case-brief.pdf');
});

it('resolves citation indices against the order sources appear in context', function () {
    $firstPage = CrawledPage::factory()->create(['law_name' => 'RA No. 6657']);
    $secondPage = CrawledPage::factory()->create(['law_name' => 'RA No. 8371']);
    $firstChunk = LegalChunk::factory()->for($firstPage)->create(['content' => 'Agrarian reform coverage.']);
    $secondChunk = LegalChunk::factory()->for($secondPage)->create(['content' => 'IPRA coverage.']);

    $message = Message::factory()->create([
        'content' => 'As stated in [Source 2], the IPRA law applies.',
        'cited_legal_chunk_ids' => [$firstChunk->id, $secondChunk->id],
    ]);

    $sources = MessageSources::for($message);

    expect($sources)->toHaveCount(1)
        ->and($sources[0]['label'])->toBe('RA No. 8371');
});

it('omits sources that no longer exist', function () {
    $message = Message::factory()->create([
        'content' => 'Cites [Source 1].',
        'cited_legal_chunk_ids' => [fake()->uuid()],
        'cited_chunk_ids' => [fake()->uuid()],
    ]);

    expect(MessageSources::for($message))->toBe([]);
});

it('deduplicates source cards that share the same source', function () {
    $user = User::factory()->create();

    $source = LegalSource::factory()->create(['name' => 'LawPhil']);
    $page = CrawledPage::factory()->for($source)->create(['law_name' => 'RA No. 6657']);
    $firstChunk = LegalChunk::factory()->for($page)->create(['content' => 'First section.']);
    $secondChunk = LegalChunk::factory()->for($page)->create(['content' => 'Second section.']);

    $document = Document::factory()->for($user)->create(['original_filename' => 'case-brief.pdf']);
    $firstDocChunk = DocumentChunk::factory()->for($document)->for($user)->create(['content' => 'Note one.']);
    $secondDocChunk = DocumentChunk::factory()->for($document)->for($user)->create(['content' => 'Note two.']);

    $message = Message::factory()->create([
        'role' => MessageRole::Assistant,
        'content' => 'See [Source 1] and [User Doc 1].',
        'cited_legal_chunk_ids' => [$firstChunk->id, $secondChunk->id],
        'cited_chunk_ids' => [$firstDocChunk->id, $secondDocChunk->id],
    ]);

    $sources = MessageSources::for($message);

    expect($sources)->toHaveCount(2)
        ->and($sources[0]['type'])->toBe('legal')
        ->and($sources[0]['label'])->toBe('RA No. 6657')
        ->and($sources[1]['type'])->toBe('document')
        ->and($sources[1]['label'])->toBe('case-brief.pdf');
});
