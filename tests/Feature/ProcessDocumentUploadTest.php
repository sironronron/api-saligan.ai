<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\TextExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
        app(ImageOcrExtractor::class),
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
        app(ImageOcrExtractor::class),
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
            app(ImageOcrExtractor::class),
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

it('uses OCR to extract text from an uploaded image', function () {
    $user = User::factory()->create();

    Storage::put('documents/scan.png', 'fake image bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/scan.png',
        'mime_type' => 'image/png',
        'status' => DocumentStatus::Queued,
    ]);

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldReceive('extract')
        ->with('documents/scan.png', 'image/png')
        ->once()
        ->andReturn('REPUBLIC OF THE PHILIPPINES
Deed of Absolute Sale');

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        $ocr,
        app(DocumentChunker::class),
        app(EmbeddingService::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and(DocumentChunk::where('document_id', $document->id)->count())->toBe(1)
        ->and(DocumentChunk::where('document_id', $document->id)->first()->content)
        ->toContain('Deed of Absolute Sale');
});

it('marks an image as failed when OCR returns no text', function () {
    $user = User::factory()->create();

    Storage::put('documents/blank.jpg', 'fake image bytes');

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/blank.jpg',
        'mime_type' => 'image/jpeg',
        'status' => DocumentStatus::Queued,
    ]);

    $ocr = Mockery::mock(ImageOcrExtractor::class);
    $ocr->shouldReceive('extract')->once()->andReturn('');

    $job = new ProcessDocumentUpload($document);

    try {
        $job->handle(
            app(TextExtractor::class),
            $ocr,
            app(DocumentChunker::class),
            app(EmbeddingService::class),
        );
        $this->fail('Expected an exception for empty OCR output.');
    } catch (RuntimeException) {
        $job->failed(new RuntimeException('No text could be read from the image.'));
    }

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error_message)->toContain('No text could be read from the image.');
});

it('sanitizes invalid UTF-8 so extracted text never breaks downstream requests', function () {
    $user = User::factory()->create();

    $badText = "REPUBLIC OF THE PHILIPPINES\xFF\xFE notarized.";

    Storage::put('documents/scan.txt', $badText);

    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/scan.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
    );

    $content = $document->chunks()->first()->content;

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($content)->toContain('REPUBLIC OF THE PHILIPPINES')
        ->and($content)->not->toContain("\xFF")
        ->and(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
});
