<?php

namespace App\Jobs;

use App\Enums\CrawlStatus;
use App\Exceptions\DocumentProcessingException;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\LegalDigestService;
use App\Services\Documents\DocumentChunker;
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

class ProcessLegalDocumentUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly CrawledPage $page)
    {
        $this->onQueue(config('saligan.documents.queue'));
    }

    /**
     * Guard against two workers ingesting the same upload at once.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('legal-upload:'.$this->page->id))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    /**
     * Extract, chunk, embed, and digest an admin-uploaded legal document, the
     * same pipeline a crawled page goes through, so the upload becomes a fully
     * retrievable and citable authority.
     */
    public function handle(
        TextExtractor $extractor,
        ImageOcrExtractor $ocr,
        DocumentChunker $chunker,
        EmbeddingService $embeddings,
        LegalDigestService $digests,
    ): void {
        $page = $this->page;

        if ($page->crawl_status === CrawlStatus::Ok) {
            return;
        }

        $page->update([
            'crawl_status' => CrawlStatus::Pending->value,
            'last_error' => null,
        ]);

        if ($page->storage_path === null || ! Storage::disk('local')->exists($page->storage_path)) {
            throw new DocumentProcessingException('The uploaded file is no longer available.');
        }

        // Clear any chunks from a previous partial attempt.
        $page->chunks()->delete();

        $path = Storage::disk('local')->path($page->storage_path);
        $mimeType = $page->mime_type ?? '';

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
        } catch (DocumentProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new DocumentProcessingException('This file could not be read. Upload a text-based PDF, DOCX, or TXT copy instead.');
        }

        $text = $this->sanitizeText($text);

        if (trim($text) === '') {
            throw new DocumentProcessingException('No text could be read from this file. Upload a clearer or text-based copy.');
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
            LegalChunk::create([
                'crawled_page_id' => $page->id,
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $vectors[$index],
            ]);
        }

        $title = $page->title;
        $digest = $digests->generate($text, $title);

        $page->update([
            'crawl_status' => CrawlStatus::Ok->value,
            'digest' => $digest,
            'digest_generated_at' => $digest !== null ? now() : null,
        ]);
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
        $text = str_replace("\0", '', $text);

        return mb_scrub($text, 'UTF-8');
    }

    /**
     * Mark the upload as failed once retries are exhausted. The real exception
     * is logged; only a safe, user-facing message is persisted.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $message = $exception instanceof DocumentProcessingException
            ? $exception->getMessage()
            : 'The document could not be processed. Please try uploading it again or contact support.';

        $this->page->update([
            'crawl_status' => CrawlStatus::Failed->value,
            'last_error' => $message,
        ]);
    }
}
