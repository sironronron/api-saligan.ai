<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentProcessingException;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\TextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
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
     * Guard against two workers ingesting the same document at once.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('document:'.$this->document->id))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    /**
     * Extract, chunk, and embed the uploaded document.
     */
    public function handle(
        TextExtractor $extractor,
        ImageOcrExtractor $ocr,
        DocumentChunker $chunker,
        EmbeddingService $embeddings,
        DocumentEncryptor $encryptor,
        DocumentClassifier $classifier,
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

        // Encrypted documents are decrypted to a temporary local file for
        // extraction and removed again as soon as extraction completes, so
        // plaintext never persists on disk.
        $decryptedPath = $encryptor->decryptToTemp($document->storage_path);
        $path = $decryptedPath ?? Storage::path($document->storage_path);

        try {
            $text = $this->isImage($mimeType)
                ? $ocr->extract($path, $mimeType)
                : $extractor->extract($path, $mimeType);

            // A scanned PDF has no text layer, so the parser returns nothing.
            // The pages are images, which is exactly what the OCR model reads,
            // so fall through to it rather than rejecting the upload.
            if (trim($this->sanitizeText($text)) === '' && ImageOcrExtractor::handles($mimeType) && ! $this->isImage($mimeType)) {
                $text = $ocr->extract($path, $mimeType);
            }
        } finally {
            if ($decryptedPath !== null) {
                @unlink($decryptedPath);
            }
        }

        // Extracted text (especially OCR output from photos) can carry invalid
        // UTF-8 byte sequences. Sanitize it so the subsequent embedding and
        // retrieval requests never fail with a "Malformed UTF-8" json_encode
        // error and so chunks are always stored as valid UTF-8.
        $text = $this->sanitizeText($text);

        if (trim($text) === '') {
            throw new DocumentProcessingException($this->isImage($mimeType)
                ? 'No text could be read from the image. Upload a clearer image or a PDF/DOCX version.'
                : 'No text could be read from this file, including by scanning it. Upload a clearer copy or a text-based PDF/DOCX version.');
        }

        $chunks = $chunker->chunk(
            $text,
            config('saligan.documents.chunk_size'),
            config('saligan.documents.chunk_overlap'),
        );

        if ($chunks === []) {
            throw new DocumentProcessingException('The extracted text produced no chunks.');
        }

        $vectors = $embeddings->embedMany($chunks);

        if (count($vectors) !== count($chunks)) {
            throw new \RuntimeException(sprintf(
                'Embedding count mismatch: %d chunks in, %d vectors out.',
                count($chunks),
                count($vectors),
            ));
        }

        foreach ($chunks as $index => $content) {
            DocumentChunk::create([
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $vectors[$index],
            ]);
        }

        // File the document into the case file. This runs before the document
        // is marked ready so it lands already sorted, and it never throws: a
        // failed suggestion leaves the document unfiled, which is a state the
        // Unfiled queue already handles.
        $classifier->classify($document, $text);

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
     *
     * mb_convert_encoding() between two 'UTF-8' encodings is a no-op for
     * malformed input, so instead we scrub with mb_scrub() (which replaces
     * invalid sequences) and explicitly drop null bytes, which Postgres
     * rejects outright and which no mb function will remove.
     */
    protected function sanitizeText(string $text): string
    {
        $text = str_replace("\0", '', $text);

        return mb_scrub($text, 'UTF-8');
    }

    /**
     * Mark the document as failed once retries are exhausted. The real
     * exception is logged; only a safe, user-facing message is persisted.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $message = $exception instanceof DocumentProcessingException
            ? $exception->getMessage()
            : 'The document could not be processed. Please try uploading it again or contact support.';

        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $message,
        ]);
    }
}
