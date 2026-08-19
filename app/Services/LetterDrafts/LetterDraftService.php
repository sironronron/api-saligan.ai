<?php

namespace App\Services\LetterDrafts;

use App\Ai\LetterDraftAgent;
use App\Enums\ChatProvider;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;

/**
 * Generates a letter draft through the AI agent and turns its answer into a
 * clean Tiptap document. The model's JSON is treated as untrusted: every node
 * is checked against the editor's schema, unknown shapes are dropped, and the
 * signature placeholder is appended if the model forgot it.
 */
class LetterDraftService
{
    /**
     * Node types the editor accepts. Anything else a model emits is dropped.
     *
     * @var array<string, true>
     */
    protected const ALLOWED_NODES = [
        'doc' => true,
        'paragraph' => true,
        'heading' => true,
        'text' => true,
        'hardBreak' => true,
        'bulletList' => true,
        'orderedList' => true,
        'listItem' => true,
        'signature' => true,
    ];

    /**
     * Marks a text node may carry. Anything else is stripped.
     *
     * @var array<string, true>
     */
    protected const ALLOWED_MARKS = [
        'bold' => true,
        'italic' => true,
    ];

    /**
     * Ceiling on node nesting, so a pathological model answer cannot build a
     * document deep enough to blow the stack when it is serialized or saved.
     */
    protected const MAX_DEPTH = 20;

    /**
     * Generate a letter draft for the user's request.
     *
     * @return array{content: array<string, mixed>, title: string, raw: string}
     */
    public function generate(string $request, ?User $user): array
    {
        [$provider, $model] = $this->resolveProvider();

        $senderName = $user?->name ?? null;
        $organizationName = $user?->organization?->name ?? null;

        $agent = new LetterDraftAgent(
            request: $request,
            senderName: $senderName,
            organizationName: $organizationName,
        );

        $raw = '';
        $content = $this->blankDocument();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                Log::warning('Letter draft generation returned unusable output; retrying.', [
                    'provider' => $provider instanceof Lab ? $provider->value : (string) $provider,
                    'model' => $model,
                    'attempt' => $attempt,
                    'raw_excerpt' => mb_substr($raw, 0, 300),
                ]);
            }

            $raw = (string) $agent->prompt($request, [], $provider, $model)->text;
            $content = $this->parse($raw);

            if ($this->isUsableDraft($content)) {
                break;
            }
        }

        return [
            'content' => $content,
            'title' => $this->titleFrom($content, $request),
            'raw' => $raw,
        ];
    }

    /**
     * Build a complete draft from a markdown letter the model wrote inline
     * instead of calling draft_letter — the fallback when a provider is weak
     * at tool calling, so the letter still lands in the in-app editor instead
     * of living only in the chat text.
     *
     * @return array{content: array<string, mixed>, title: string, raw: string}
     */
    public function fromMarkdown(string $markdown, string $request): array
    {
        $doc = $this->docFromMarkdown($markdown);

        return [
            'content' => $doc,
            'title' => $this->titleFrom($doc, $request),
            'raw' => $markdown,
        ];
    }

    /**
     * Parse and sanitize the model's answer into a valid Tiptap document,
     * degrading gracefully: any answer that is not usable JSON becomes a
     * single-paragraph letter the user can edit from scratch.
     *
     * @return array<string, mixed>
     */
    protected function parse(string $raw): array
    {
        $decoded = json_decode($this->extractJson($raw), true);

        if (! is_array($decoded)) {
            Log::warning('Letter draft generation returned unparseable output; the raw text was used as the draft.', [
                'raw_excerpt' => mb_substr($raw, 0, 300),
            ]);

            return $this->docFromText($raw) ?? $this->blankDocument();
        }

        $doc = $this->sanitizeNode($decoded, 0);

        if ($doc === null || $doc['type'] !== 'doc') {
            return $this->blankDocument();
        }

        $this->ensureSignature($doc);

        return $doc;
    }

    /**
     * Whether the parsed document carries any real text. A model that returns
     * an empty reply, or JSON that sanitizes to nothing, produces the blank
     * fallback — textOf() of that is an empty string, so the caller knows to
     * retry instead of shipping an empty draft.
     *
     * @param  array<string, mixed>  $doc
     */
    protected function isUsableDraft(array $doc): bool
    {
        return $this->textOf($doc) !== '';
    }

    /**
     * Build a Tiptap document from the model's raw prose when it did not
     * answer as JSON. Whatever the model wrote still reaches the editor
     * instead of being discarded, so the user always has the draft text to
     * edit. Returns null when there is no usable text at all.
     *
     * @return array<string, mixed>|null
     */
    protected function docFromText(string $raw): ?array
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        $content = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $content[] = $this->paragraphFromLine($line);
        }

        if ($content === []) {
            return null;
        }

        $doc = [
            'type' => 'doc',
            'content' => $content,
        ];

        $this->ensureSignature($doc);

        return $doc;
    }

    /**
     * Turn one line of the model's prose into a paragraph or heading node,
     * tolerating a light markdown-ish "# " heading marker.
     *
     * @return array<string, mixed>
     */
    protected function paragraphFromLine(string $line): array
    {
        if (preg_match('/^(#{1,2})\s+(.*)$/', $line, $matches) === 1) {
            return [
                'type' => 'heading',
                'attrs' => ['level' => strlen($matches[1])],
                'content' => $this->markdownInline(trim($matches[2])),
            ];
        }

        return [
            'type' => 'paragraph',
            'content' => $this->markdownInline($line),
        ];
    }

    /**
     * Build a Tiptap document from the markdown body of a letter the model
     * wrote inline: headings, unordered/ordered lists, and **bold** emphasis
     * are preserved, plain lines become paragraphs, and the signature slot is
     * appended. The editor then accepts the letter exactly as the draft_letter
     * path would have produced it.
     *
     * @return array<string, mixed>
     */
    protected function docFromMarkdown(string $raw): array
    {
        $content = [];
        $pendingParagraph = [];

        $flushParagraph = function () use (&$content, &$pendingParagraph): void {
            if ($pendingParagraph === []) {
                return;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => $this->markdownInline(implode(' ', $pendingParagraph)),
            ];
            $pendingParagraph = [];
        };

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                $flushParagraph();

                continue;
            }

            if (preg_match('/^(#{1,2})\s+(.*)$/', $line, $matches) === 1) {
                $flushParagraph();
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => strlen($matches[1])],
                    'content' => $this->markdownInline(trim($matches[2])),
                ];

                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/', $line, $matches) === 1) {
                $flushParagraph();
                $content[] = $this->markdownListItem('bulletList', $matches[1]);

                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.*)$/', $line, $matches) === 1) {
                $flushParagraph();
                $content[] = $this->markdownListItem('orderedList', $matches[1]);

                continue;
            }

            $pendingParagraph[] = $line;
        }

        $flushParagraph();

        if ($content === []) {
            return $this->blankDocument();
        }

        $doc = ['type' => 'doc', 'content' => $content];

        $this->ensureSignature($doc);

        return $doc;
    }

    /**
     * @return array<string, mixed>
     */
    protected function markdownListItem(string $listType, string $text): array
    {
        return [
            'type' => $listType,
            'content' => [[
                'type' => 'listItem',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => $this->markdownInline(trim($text)),
                ]],
            ]],
        ];
    }

    /**
     * Split a line of markdown into text nodes, turning **bold** spans into
     * bold-marked text nodes so the editor keeps the emphasis.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function markdownInline(string $text): array
    {
        $parts = preg_split('/\*\*(.+?)\*\*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $nodes = [];

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            $nodes[] = $index % 2 === 1
                ? ['type' => 'text', 'text' => $part, 'marks' => [['type' => 'bold']]]
                : ['type' => 'text', 'text' => $part];
        }

        return $nodes !== [] ? $nodes : [['type' => 'text', 'text' => $text]];
    }

    /**
     * Pull the JSON object out of the model's reply, tolerating code-fence
     * wrappers and leading/trailing chatter. Returns the trimmed original when
     * no object can be found, so json_decode gets a fair shot at it.
     */
    protected function extractJson(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return $trimmed;
        }

        // Strip a ```json ... ``` or ``` ... ``` fence if one wraps the whole
        // answer.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        // Otherwise take the first {...} block if one exists.
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($trimmed, $start, $end - $start + 1);
        }

        return $trimmed;
    }

    /**
     * Validate and rebuild a single node. Returns null for anything outside
     * the schema, so the caller can drop it from its parent's content.
     *
     * @return array<string, mixed>|null
     */
    protected function sanitizeNode(mixed $node, int $depth): ?array
    {
        if (! is_array($node) || ! isset($node['type']) || ! is_string($node['type'])) {
            return null;
        }

        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        $type = $node['type'];

        if (! isset(self::ALLOWED_NODES[$type])) {
            return null;
        }

        return match ($type) {
            'doc' => [
                'type' => 'doc',
                'content' => $this->sanitizeContent($node['content'] ?? [], $depth),
            ],
            'heading' => [
                'type' => 'heading',
                'attrs' => ['level' => $this->headingLevel($node['attrs'] ?? null)],
                'content' => $this->sanitizeContent($node['content'] ?? [], $depth),
            ],
            'paragraph' => [
                'type' => 'paragraph',
                'content' => $this->sanitizeContent($node['content'] ?? [], $depth),
            ],
            'text' => $this->sanitizeText($node),
            'hardBreak' => ['type' => 'hardBreak'],
            'bulletList', 'orderedList' => [
                'type' => $type,
                'content' => $this->sanitizeContent($node['content'] ?? [], $depth),
            ],
            'listItem' => [
                'type' => 'listItem',
                'content' => $this->sanitizeContent($node['content'] ?? [], $depth),
            ],
            'signature' => ['type' => 'signature'],
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeContent(mixed $content, int $depth): array
    {
        if (! is_array($content)) {
            return [];
        }

        $clean = [];

        foreach ($content as $child) {
            $cleanChild = $this->sanitizeNode($child, $depth + 1);

            if ($cleanChild !== null) {
                $clean[] = $cleanChild;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    protected function sanitizeText(array $node): ?array
    {
        $text = $node['text'] ?? null;

        if (! is_string($text) || $text === '') {
            return null;
        }

        $clean = [
            'type' => 'text',
            'text' => $text,
        ];

        $marks = [];

        foreach ($node['marks'] ?? [] as $mark) {
            if (! is_array($mark) || ! isset($mark['type']) || ! is_string($mark['type'])) {
                continue;
            }

            if (isset(self::ALLOWED_MARKS[$mark['type']])) {
                $marks[] = ['type' => $mark['type']];
            }
        }

        if ($marks !== []) {
            $clean['marks'] = $marks;
        }

        return $clean;
    }

    /**
     * Constrain a heading level to the editor's 1-2 range.
     */
    protected function headingLevel(mixed $attrs): int
    {
        $level = is_array($attrs) ? (int) ($attrs['level'] ?? 1) : 1;

        return max(1, min(2, $level));
    }

    /**
     * Append a signature placeholder when the model omitted it. The editor
     * needs the anchor regardless: it is where the user signs, and relying on
     * the model to remember it every time is exactly the kind of thing that
     * silently breaks one draft in ten.
     *
     * @param  array<string, mixed>  $doc
     */
    protected function ensureSignature(array &$doc): void
    {
        $hasSignature = $this->containsSignature($doc['content'] ?? []);

        if (! $hasSignature) {
            $doc['content'][] = ['type' => 'signature'];
        }
    }

    /**
     * @param  array<int, mixed>  $content
     */
    protected function containsSignature(array $content): bool
    {
        foreach ($content as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'signature') {
                return true;
            }
        }

        return false;
    }

    /**
     * The letter's title: the first non-empty line of text in the document,
     * or a sensible fallback derived from the user's request.
     *
     * @param  array<string, mixed>  $doc
     */
    protected function titleFrom(array $doc, string $request): string
    {
        foreach ($doc['content'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $line = $this->textOf($node);

            if ($line !== '') {
                return mb_strlen($line) > 80 ? mb_substr($line, 0, 80).'…' : $line;
            }
        }

        $words = array_slice(str_word_count($request, 1), 0, 8);

        return implode(' ', $words) !== '' ? ucfirst(implode(' ', $words)) : 'Untitled letter';
    }

    /**
     * The concatenated text of a node's subtree.
     *
     * @param  array<string, mixed>  $node
     */
    protected function textOf(array $node): string
    {
        $text = $node['text'] ?? null;

        if (is_string($text)) {
            return trim($text);
        }

        $parts = [];

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $parts[] = $this->textOf($child);
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankDocument(): array
    {
        return [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => []],
                ['type' => 'signature'],
            ],
        ];
    }

    /**
     * The provider and model the letter draft is generated on, resolved the
     * same way the chat path resolves them: the configured provider when its
     * key is present, otherwise a local Ollama model.
     *
     * @return array{0: Lab|string, 1: string}
     */
    protected function resolveProvider(): array
    {
        return match (ChatProvider::fromConfig()) {
            ChatProvider::Anthropic => filled(config('ai.providers.anthropic.key'))
                ? [Lab::Anthropic, (string) config('saligan.chat.anthropic_model')]
                : $this->ollamaFallback('anthropic'),
            ChatProvider::Gemini => filled(config('ai.providers.gemini.key'))
                ? [Lab::Gemini, (string) config('saligan.chat.gemini_model')]
                : $this->ollamaFallback('gemini'),
            ChatProvider::OpenAI => filled(config('ai.providers.openai.key'))
                ? [Lab::OpenAI, (string) config('saligan.chat.openai_model')]
                : $this->ollamaFallback('openai'),
            ChatProvider::Meta => filled(config('ai.providers.meta.key'))
                ? ['meta', (string) config('saligan.chat.meta_model')]
                : $this->ollamaFallback('meta'),
            default => $this->ollamaFallback('ollama'),
        };
    }

    /**
     * @return array{0: Lab|string, 1: string}
     */
    protected function ollamaFallback(string $configured): array
    {
        if ($configured !== 'ollama') {
            Log::warning('Letter draft generation fell back to Ollama: the configured provider has no API key.', [
                'configured_provider' => $configured,
            ]);
        }

        return [Lab::Ollama, (string) config('saligan.chat.ollama_model')];
    }
}
