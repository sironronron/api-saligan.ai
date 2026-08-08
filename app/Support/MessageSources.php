<?php

namespace App\Support;

use App\Models\DocumentChunk;
use App\Models\LegalChunk;
use App\Models\Message;
use Illuminate\Support\Str;

final class MessageSources
{
    /**
     * In-memory per-request cache of resolved chunks keyed by chunk id.
     *
     * @var array<string, LegalChunk|DocumentChunk|null>
     */
    protected static array $resolved = [];

    /**
     * Resolve the source cards actually cited by a message.
     *
     * Legal pages and uploaded documents are tied to inline [Source N] /
     * [User Doc N] markers: they only surface when the model actually cited
     * them, so the UI never shows retrieved context it did not rely on. Web
     * search results are always surfaced — the provider only records the web
     * results the answer was grounded in, and the UI renders them in their
     * own section. When the model cited nothing inline at all (e.g. a plain
     * summary of facts), every retrieved chunk stored on the message is
     * surfaced instead, so the UI still shows what the answer was grounded
     * in. Every card carries the citation index it is tied to (1-based, in
     * context order) so inline badges and sidebar cards can be linked.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(Message $message): array
    {
        $citations = self::citedIndices((string) $message->content);

        $strict = $citations['source'] !== [] || $citations['doc'] !== [] || $citations['web'] !== [];

        $sources = [];

        $index = 0;
        $seenLegalPages = [];

        foreach ($message->cited_legal_chunk_ids ?? [] as $chunkId) {
            $chunk = self::resolve(LegalChunk::class, $chunkId);

            if ($chunk === null) {
                continue;
            }

            $key = $chunk->crawled_page_id ?? $chunk->id;

            if (isset($seenLegalPages[$key])) {
                continue;
            }

            $seenLegalPages[$key] = true;
            $index++;

            if ($strict && ! in_array($index, $citations['source'], true)) {
                continue;
            }

            $sources[] = self::legalSource($chunk, $index);
        }

        $index = 0;
        $seenDocuments = [];

        foreach ($message->cited_chunk_ids ?? [] as $chunkId) {
            $chunk = self::resolve(DocumentChunk::class, $chunkId);

            if ($chunk === null) {
                continue;
            }

            $key = $chunk->document_id ?? $chunk->id;

            if (isset($seenDocuments[$key])) {
                continue;
            }

            $seenDocuments[$key] = true;
            $index++;

            if ($strict && ! in_array($index, $citations['doc'], true)) {
                continue;
            }

            $sources[] = self::documentSource($chunk, $index);
        }

        $sources = array_merge($sources, self::webSources($message));

        return $sources;
    }

    /**
     * Parse the inline citation markers the model is instructed to use.
     *
     * @return array{source: array<int>, doc: array<int>, web: array<int>}
     */
    protected static function citedIndices(string $content): array
    {
        $citations = [
            'source' => [],
            'doc' => [],
            'web' => [],
        ];

        if (preg_match_all('/\[Source\s+(\d+)\]/i', $content, $matches)) {
            $citations['source'] = array_map('intval', $matches[1]);
        }

        if (preg_match_all('/\[User\s+Doc\s+(\d+)\]/i', $content, $matches)) {
            $citations['doc'] = array_map('intval', $matches[1]);
        }

        if (preg_match_all('/\[Web\s+(\d+)\]/i', $content, $matches)) {
            $citations['web'] = array_map('intval', $matches[1]);
        }

        return $citations;
    }

    /**
     * Web-search citations captured from the provider at stream time (stored
     * in message metadata). Unlike retrieved legal/document sources, these are
     * always surfaced: the provider only records the web results the answer
     * was actually grounded in, and the UI renders them in their own section,
     * so no inline marker is required for them to appear.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function webSources(Message $message): array
    {
        $webCitations = $message->metadata['web_citations'] ?? [];

        if (! is_array($webCitations) || $webCitations === []) {
            return [];
        }

        $sources = [];

        foreach ($webCitations as $index => $citation) {
            $number = $index + 1;

            $url = $citation['url'] ?? $citation['link'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $sources[] = [
                'type' => 'web',
                'index' => $number,
                'label' => $citation['title'] ?? null,
                'title' => $citation['title'] ?? null,
                'source_name' => null,
                'url' => $url,
                'domain' => parse_url($url, PHP_URL_HOST) ?: null,
                'excerpt' => isset($citation['snippet']) && is_string($citation['snippet'])
                    ? Str::limit($citation['snippet'], 300)
                    : null,
            ];
        }

        return $sources;
    }

    /**
     * @param  class-string<LegalChunk|DocumentChunk>  $class
     */
    protected static function resolve(string $class, string $id): LegalChunk|DocumentChunk|null
    {
        $key = $class.':'.$id;

        if (! array_key_exists($key, self::$resolved)) {
            $query = $class::query();

            if ($class === LegalChunk::class) {
                $query->with('crawledPage.legalSource');
            } else {
                $query->with('document');
            }

            self::$resolved[$key] = $query->find($id);
        }

        return self::$resolved[$key];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function legalSource(LegalChunk $chunk, int $index): array
    {
        $page = $chunk->crawledPage;

        return [
            'type' => 'legal',
            'index' => $index,
            'id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'label' => $page?->law_name ?: ($page?->gr_number ?: $page?->legalSource?->name ?: 'Legal source'),
            'title' => $page?->title,
            'law_name' => $page?->law_name,
            'gr_number' => $page?->gr_number,
            'promulgation_date' => $page?->promulgation_date?->toDateString(),
            'source_name' => $page?->legalSource?->name,
            'url' => $page?->url,
            'domain' => $page?->url !== null ? parse_url($page->url, PHP_URL_HOST) : null,
            'excerpt' => Str::limit($chunk->content, 300),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function documentSource(DocumentChunk $chunk, int $index): array
    {
        return [
            'type' => 'document',
            'index' => $index,
            'id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'document_id' => $chunk->document_id,
            'label' => $chunk->document?->original_filename ?? 'Uploaded document',
            'title' => $chunk->document?->title,
            'url' => null,
            'domain' => null,
            'excerpt' => Str::limit($chunk->content, 300),
        ];
    }
}
