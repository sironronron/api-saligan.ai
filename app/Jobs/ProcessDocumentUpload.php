<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\TextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessDocumentUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly Document $document)
    {
        //
    }

    /**
     * Extract, chunk, and embed the uploaded document.
     */
    public function handle(
        TextExtractor $extractor,
        DocumentChunker $chunker,
        EmbeddingService $embeddings,
    ): void {
        $document = $this->document;

        if ($document->status === DocumentStatus::Ready) {
            return;
        }

        $document->update([
            'status' => DocumentStatus::Processing,
            'error_message' => null,
        ]);

        // Clear any chunks from a previous partial attempt.
        $document->chunks()->delete();

        $text = $extractor->extract($document->storage_path, $document->mime_type ?? '');

        if (trim($text) === '') {
            throw new \RuntimeException('No extractable text was found in the file. Scanned PDFs may require OCR.');
        }

        $chunks = $chunker->chunk(
            $text,
            config('saligan.documents.chunk_size'),
            config('saligan.documents.chunk_overlap'),
        );

        if ($chunks === []) {
            throw new \RuntimeException('The extracted text produced no chunks.');
        }

        $vectors = $embeddings->embedMany($chunks);

        foreach ($chunks as $index => $content) {
            DocumentChunk::create([
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $vectors[$index],
            ]);
        }

        $document->update(['status' => DocumentStatus::Ready]);
    }

    /**
     * Mark the document as failed once retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'The document could not be processed.',
        ]);
    }
}
