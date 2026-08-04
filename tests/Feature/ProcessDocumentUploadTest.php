<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\TextExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

beforeEach(function () {
    Storage::fake('local');
    Http::fake([
        '*/api/embed' => function (Request $request) {
            $inputs = $request->data()['input'] ?? [];

            return Http::response([
                'embeddings' => array_map(
                    fn () => array_fill(0, 768, 0.5),
                    $inputs,
                ),
            ], 200);
        },
    ]);
});

it('extracts, chunks, embeds, and stores chunks for a text document', function () {
    $user = User::factory()->create();

    $text = implode("\n\n", array_fill(0, 40, str_repeat('agrarian reform and land reform jurisprudence. ', 20)));

    Storage::put('documents/memo.txt', $text);

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/memo.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunks()->count())->toBeGreaterThan(1)
        ->and($document->chunks()->first()->user_id)->toBe($user->id)
        ->and($document->chunks()->first()->embedding)->toHaveCount(768);
});

it('stores a single chunk for a short text document', function () {
    $user = User::factory()->create();

    Storage::put('documents/short.txt', 'A short legal note.');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/short.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and(DocumentChunk::where('document_id', $document->id)->count())->toBe(1);
});

it('marks a document as failed when no text can be extracted', function () {
    $user = User::factory()->create();

    Storage::put('documents/empty.txt', '   ');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/empty.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    $job = new ProcessDocumentUpload($document);

    try {
        $job->handle(
            app(TextExtractor::class),
            app(DocumentChunker::class),
            app(EmbeddingService::class),
        );
        $this->fail('Expected an exception for empty text.');
    } catch (RuntimeException) {
        $job->failed(new RuntimeException('No extractable text was found in the file.'));
    }

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error_message)->toContain('No extractable text');
});
