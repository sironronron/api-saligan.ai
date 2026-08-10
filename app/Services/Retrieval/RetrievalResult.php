<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use App\Models\LegalChunk;
use App\Support\CitationTokens;
use App\Support\PromptGuard;
use Illuminate\Support\Collection;

class RetrievalResult
{
    /**
     * @param  Collection<int, LegalChunk>  $legalChunks
     * @param  Collection<int, DocumentChunk>  $documentChunks
     */
    public function __construct(
        public readonly Collection $legalChunks,
        public readonly Collection $documentChunks,
    ) {
        //
    }

    /**
     * Whether neither source produced any relevant context.
     */
    public function isEmpty(): bool
    {
        return $this->legalChunks->isEmpty() && $this->documentChunks->isEmpty();
    }

    /**
     * IDs of the retrieved legal chunks, for citation persistence.
     *
     * @return array<int, string>
     */
    public function legalChunkIds(): array
    {
        return $this->legalChunks->pluck('id')->all();
    }

    /**
     * IDs of the retrieved user-document chunks, for citation persistence.
     *
     * @return array<int, string>
     */
    public function documentChunkIds(): array
    {
        return $this->documentChunks->pluck('id')->all();
    }

    /**
     * Format the retrieved context for the prompt, respecting source priority
     * (legal knowledge base first, then the user's own documents).
     *
     * Each source is a labeled document: a legal page or an uploaded document
     * presented as its own block headed by a stable citation token
     * (`[SRC <token>]` / `[DOC <token>]`) the model copies verbatim when it
     * cites a word, phrase, or sentence. The parser recomputes the same token
     * from the source identity, so no position mapping is involved.
     */
    public function contextBlock(): string
    {
        $tokens = CitationTokens::assign($this->identities());

        $lines = [];

        if ($this->legalChunks->isNotEmpty()) {
            $lines[] = '### OFFICIAL SOURCES (PRIORITY 1)';

            foreach ($this->legalChunks->groupBy(fn (LegalChunk $chunk): string => (string) ($chunk->crawled_page_id ?? $chunk->id)) as $identity => $chunks) {
                $chunk = $chunks->first();
                $page = $chunk->crawledPage;
                $source = $page?->legalSource;

                $label = self::sanitizeLabel($page?->law_name ?: ($page?->gr_number ?: $source?->name ?: 'Legal source'));

                $meta = [];
                if ($page?->gr_number) {
                    $meta[] = $page->gr_number;
                }
                if ($page?->promulgation_date) {
                    $meta[] = 'promulgated '.$page->promulgation_date->format('Y-m-d');
                }
                if ($source?->name) {
                    $meta[] = $source->name;
                }

                $lines[] = $this->unitBlock(
                    CitationTokens::SRC,
                    $tokens[$identity],
                    $label.($meta === [] ? '' : ' ('.implode(', ', $meta).')'),
                    $page?->url,
                    $chunks->map(fn (LegalChunk $chunk) => PromptGuard::wrap((string) $chunk->content))->filter(),
                );
            }
        }

        if ($this->documentChunks->isNotEmpty()) {
            $lines[] = '### USER UPLOADED DOCUMENTS (SUPPORTING CONTEXT)';

            foreach ($this->documentChunks->groupBy(fn (DocumentChunk $chunk): string => (string) ($chunk->document_id ?? $chunk->id)) as $identity => $chunks) {
                $chunk = $chunks->first();

                $lines[] = $this->unitBlock(
                    CitationTokens::DOC,
                    $tokens[$identity],
                    self::sanitizeLabel($chunk->document?->original_filename ?? 'Uploaded document'),
                    null,
                    $chunks->map(fn (DocumentChunk $chunk) => PromptGuard::wrap((string) $chunk->content))->filter(),
                );
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * The distinct source identities in play (legal page ids and uploaded
     * document ids), so citation tokens are assigned across the whole set and
     * match the identities MessageSources reconstructs from a message.
     *
     * @return array<int, string>
     */
    protected function identities(): array
    {
        $identities = [];

        foreach ($this->legalChunks as $chunk) {
            $identities[] = (string) ($chunk->crawled_page_id ?? $chunk->id);
        }

        foreach ($this->documentChunks as $chunk) {
            $identities[] = (string) ($chunk->document_id ?? $chunk->id);
        }

        return $identities;
    }

    /**
     * @param  Collection<int, string>  $contents
     */
    protected function unitBlock(string $kind, string $token, string $label, ?string $url, Collection $contents): string
    {
        $url = $url !== null ? "\nURL: {$url}" : '';

        return CitationTokens::marker($kind, $token)." {$label}{$url}\n{$contents->implode("\n\n")}";
    }

    /**
     * Sanitize a source label to prevent prompt injection via filenames or
     * crawled page metadata. Strips newlines, control characters, and
     * truncates to a reasonable length.
     */
    private static function sanitizeLabel(string $label): string
    {
        $cleaned = preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', ' ', $label);
        $cleaned = trim((string) $cleaned);
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);

        return mb_strimwidth($cleaned, 0, 255, '…');
    }
}
