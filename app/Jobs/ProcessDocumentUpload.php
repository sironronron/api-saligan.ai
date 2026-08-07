<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\ImageOcrExtractor;
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
        ImageOcrExtractor $ocr,
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

        $mimeType = $document->mime_type ?? '';

        $text = $this->isImage($mimeType)
            ? $ocr->extract($document->storage_path, $mimeType)
            : $extractor->extract($document->storage_path, $mimeType);

        // Extracted text (especially OCR output from photos) can carry invalid
        // UTF-8 byte sequences. Sanitize it so the subsequent embedding and
        // retrieval requests never fail with a "Malformed UTF-8" json_encode
        // error and so chunks are always stored as valid UTF-8.
        $text = $this->sanitizeText($text);

        if (trim($text) === '') {
            throw new \RuntimeException($this->isImage($mimeType)
                ? 'No text could be read from the image. Upload a clearer image or a PDF/DOCX version.'
                : 'No extractable text was found in the file. Scanned PDFs may require OCR.');
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
     * Whether the MIME type represents a bitmap image handled by the OCR
     * pipeline rather than the text extractors.
     */
    protected function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * Replace invalid UTF-8 byte sequences so extracted text never breaks the
     * json_encode of downstream HTTP requests (embedding, chat retrieval) and
     * stored chunks are always valid UTF-8.
     */
    protected function sanitizeText(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
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
