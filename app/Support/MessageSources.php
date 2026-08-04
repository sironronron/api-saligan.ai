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
     * Only sources referenced inline by the model as [Source N] (official
     * legal pages) or [User Doc N] (uploaded documents) are returned, so the
     * UI never shows retrieved context the model did not rely on.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(Message $message): array
    {
        $citations = self::citedIndices((string) $message->content);

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

            if (! in_array($index, $citations['source'], true)) {
                continue;
            }

            $sources[] = self::legalSource($chunk);
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

            if (! in_array($index, $citations['doc'], true)) {
                continue;
            }

            $sources[] = self::documentSource($chunk);
        }

        return $sources;
    }

    /**
     * Parse the inline citation markers the model is instructed to use.
     *
     * @return array{source: array<int>, doc: array<int>}
     */
    protected static function citedIndices(string $content): array
    {
        $citations = [
            'source' => [],
            'doc' => [],
        ];

        if (preg_match_all('/\[Source\s+(\d+)\]/i', $content, $matches)) {
            $citations['source'] = array_map('intval', $matches[1]);
        }

        if (preg_match_all('/\[User\s+Doc\s+(\d+)\]/i', $content, $matches)) {
            $citations['doc'] = array_map('intval', $matches[1]);
        }

        return $citations;
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
    protected static function legalSource(LegalChunk $chunk): array
    {
        $page = $chunk->crawledPage;

        return [
            'type' => 'legal',
            'label' => $page?->law_name ?: ($page?->gr_number ?: $page?->legalSource?->name ?: 'Legal source'),
            'title' => $page?->title,
            'law_name' => $page?->law_name,
            'gr_number' => $page?->gr_number,
            'promulgation_date' => $page?->promulgation_date?->toDateString(),
            'source_name' => $page?->legalSource?->name,
            'url' => $page?->url,
            'excerpt' => Str::limit($chunk->content, 300),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function documentSource(DocumentChunk $chunk): array
    {
        return [
            'type' => 'document',
            'label' => $chunk->document?->original_filename ?? 'Uploaded document',
            'title' => $chunk->document?->title,
            'url' => null,
            'excerpt' => Str::limit($chunk->content, 300),
        ];
    }
}
