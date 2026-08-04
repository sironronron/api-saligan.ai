<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use App\Models\LegalChunk;
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
     */
    public function contextBlock(): string
    {
        $lines = [];

        if ($this->legalChunks->isNotEmpty()) {
            $lines[] = '### OFFICIAL SOURCES (PRIORITY 1)';

            $index = 0;

            foreach ($this->legalChunks->groupBy('crawled_page_id') as $chunks) {
                $index++;

                $chunk = $chunks->first();
                $page = $chunk->crawledPage;
                $source = $page?->legalSource;

                $label = $page?->law_name ?: ($page?->gr_number ?: $source?->name ?: 'Legal source');

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

                $lines[] = sprintf(
                    "[Source %d] %s%s\nURL: %s\n%s",
                    $index,
                    $label,
                    $meta === [] ? '' : ' ('.implode(', ', $meta).')',
                    $page?->url ?? 'n/a',
                    $chunks->pluck('content')->implode("\n\n"),
                );
            }
        }

        if ($this->documentChunks->isNotEmpty()) {
            $lines[] = '### USER UPLOADED DOCUMENTS (SUPPORTING CONTEXT)';

            $index = 0;

            foreach ($this->documentChunks->groupBy('document_id') as $chunks) {
                $index++;

                $chunk = $chunks->first();

                $lines[] = sprintf(
                    "[User Doc %d] %s\n%s",
                    $index,
                    $chunk->document?->original_filename ?? 'Uploaded document',
                    $chunks->pluck('content')->implode("\n\n"),
                );
            }
        }

        return implode("\n\n", $lines);
    }
}
