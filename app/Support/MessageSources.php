<?php

namespace App\Support;

use App\Models\DocumentChunk;
use App\Models\LegalChunk;
use App\Models\Message;
use Illuminate\Support\Str;

final class MessageSources
{
    /**
     * Resolve the source cards actually cited by a message.
     *
     * Legal pages and uploaded documents are tied to inline [SRC <token>] /
     * [DOC <token>] markers: a source only surfaces when the model actually
     * cited its token, so the UI never shows retrieved context it did not rely
     * on. Web search results are always surfaced — the provider only records
     * the web results the answer was grounded in, and the UI renders them in
     * their own section. When the model cited nothing inline at all (e.g. a
     * plain summary of facts), every retrieved chunk stored on the message is
     * surfaced instead, so the UI still shows what the answer was grounded in.
     *
     * Every card carries the citation token (or, for legacy messages using
     * [Source N] / [User Doc N] markers, the 1-based index) it is tied to so
     * inline badges and sidebar cards can be linked.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(Message $message): array
    {
        $content = (string) $message->content;

        $tokenCited = self::citedTokens($content);
        $legacyCited = self::citedIndices($content);

        $tokenMode = $tokenCited['src'] !== [] || $tokenCited['doc'] !== [];
        $legacyMode = ! $tokenMode
            && ($legacyCited['source'] !== [] || $legacyCited['doc'] !== [] || $legacyCited['web'] !== []);

        $strict = $tokenMode || $legacyMode;

        // Group the resolved chunks into per-page / per-document units, the
        // same grouping the prompt's context block uses, so citation tokens
        // assigned here match the tokens the model was given.
        $legalUnits = [];
        // Every retrieved chunk index per page, not just the first. The reader
        // highlights exactly the passages the answer drew on, and a page is
        // routinely cited through several of them.
        $legalChunkIndexes = [];

        foreach ($message->cited_legal_chunk_ids ?? [] as $chunkId) {
            $chunk = self::resolve(LegalChunk::class, $chunkId);

            if ($chunk === null) {
                continue;
            }

            $identity = (string) ($chunk->crawled_page_id ?? $chunk->id);

            $legalUnits[$identity] ??= $chunk;
            $legalChunkIndexes[$identity][] = (int) $chunk->chunk_index;
        }

        $docUnits = [];
        // As with legal pages, every retrieved chunk index per document — the
        // citation reader highlights each passage the answer drew on, and a
        // long upload is routinely cited through several of them.
        $docChunkIndexes = [];

        foreach ($message->cited_chunk_ids ?? [] as $chunkId) {
            $chunk = self::resolve(DocumentChunk::class, $chunkId);

            if ($chunk === null) {
                continue;
            }

            $identity = (string) ($chunk->document_id ?? $chunk->id);

            $docUnits[$identity] ??= $chunk;
            $docChunkIndexes[$identity][] = (int) $chunk->chunk_index;
        }

        $tokens = CitationTokens::assign(array_merge(array_keys($legalUnits), array_keys($docUnits)));

        $sources = [];

        $index = 0;

        foreach ($legalUnits as $identity => $chunk) {
            $index++;

            if ($strict) {
                $cited = $tokenMode
                    ? in_array($tokens[$identity], $tokenCited['src'], true)
                    : in_array($index, $legacyCited['source'], true);

                if (! $cited) {
                    continue;
                }
            }

            $sources[] = self::legalSource($chunk, $index, $tokens[$identity], $legalChunkIndexes[$identity] ?? []);
        }

        $index = 0;

        foreach ($docUnits as $identity => $chunk) {
            $index++;

            if ($strict) {
                $cited = $tokenMode
                    ? in_array($tokens[$identity], $tokenCited['doc'], true)
                    : in_array($index, $legacyCited['doc'], true);

                if (! $cited) {
                    continue;
                }
            }

            $sources[] = self::documentSource($chunk, $index, $tokens[$identity], $docChunkIndexes[$identity] ?? []);
        }

        return array_merge($sources, self::webSources($message));
    }

    /**
     * Parse the inline citation tokens the model is instructed to use.
     *
     * @return array{src: array<int, string>, doc: array<int, string>, web: array<int, int>}
     */
    protected static function citedTokens(string $content): array
    {
        $citations = [
            'src' => [],
            'doc' => [],
            'web' => [],
        ];

        if (preg_match_all('/\[SRC\s+([A-Z0-9]+)\]/i', $content, $matches)) {
            $citations['src'] = array_map('strtoupper', $matches[1]);
        }

        if (preg_match_all('/\[DOC\s+([A-Z0-9]+)\]/i', $content, $matches)) {
            $citations['doc'] = array_map('strtoupper', $matches[1]);
        }

        if (preg_match_all('/\[Web\s+(\d+)\]/i', $content, $matches)) {
            $citations['web'] = array_map('intval', $matches[1]);
        }

        return $citations;
    }

    /**
     * Parse legacy position-based markers ([Source N] / [User Doc N]) written
     * before the token format, resolved against the order sources appear in
     * context for older messages.
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
        $query = $class::query();

        if ($class === LegalChunk::class) {
            $query->with('crawledPage.legalSource');
        } else {
            $query->with('document.labels');
        }

        return $query->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<int, int>  $citedChunkIndexes  Chunks of this page the answer drew on.
     */
    protected static function legalSource(LegalChunk $chunk, int $index, string $token, array $citedChunkIndexes = []): array
    {
        $page = $chunk->crawledPage;

        sort($citedChunkIndexes);

        return [
            'type' => 'legal',
            'index' => $index,
            'token' => $token,
            'id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'cited_chunk_indexes' => array_values(array_unique($citedChunkIndexes)),
            // Present when the authority is in the knowledge base, which is
            // what lets the citation open in the reader instead of the
            // original site.
            'page_id' => $page?->id,
            'has_digest' => filled($page?->digest),
            'label' => $page?->law_name ?: ($page?->gr_number ?: ($page?->title ?: ($page?->original_filename ?: ($page?->legalSource?->name ?: 'Legal source')))),
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
     * @param  array<int, int>  $citedChunkIndexes  Chunks of this document the answer drew on.
     * @return array<string, mixed>
     */
    protected static function documentSource(DocumentChunk $chunk, int $index, string $token, array $citedChunkIndexes = []): array
    {
        $document = $chunk->document;

        sort($citedChunkIndexes);

        return [
            'type' => 'document',
            'index' => $index,
            'token' => $token,
            'id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'cited_chunk_indexes' => array_values(array_unique($citedChunkIndexes)),
            'document_id' => $chunk->document_id,
            'label' => $document?->original_filename ?? 'Uploaded document',
            'title' => $document?->title,
            'mime_type' => $document?->mime_type,
            'has_digest' => filled($document?->digest),
            'uploaded_at' => $document?->created_at?->toIso8601String(),
            // The case-file categories the upload is filed under, so a citation
            // says what kind of exhibit it came from without opening it.
            'tags' => $document === null ? [] : $document->labels
                ->map(fn ($label) => [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                ])
                ->values()
                ->all(),
            'url' => null,
            'domain' => null,
            'excerpt' => Str::limit($chunk->content, 300),
        ];
    }
}
