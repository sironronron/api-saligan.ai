<?php

namespace App\Services\Crawler;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Throwable;

/**
 * Generates the short digest shown at the top of a source in the reader.
 *
 * Digests are produced once, at crawl time, and stored on the page — every
 * reader of a given case sees the same digest, so generating it per view would
 * repeat identical work and put a model call in the path of opening a source.
 */
class LegalDigestService
{
    /**
     * Roughly how much of the document the model is given. Philippine Supreme
     * Court decisions run long; the opening carries the caption, parties, and
     * facts, and the closing carries the disposition, so both ends are sent
     * rather than a single truncated prefix.
     */
    private const HEAD_CHARS = 14000;

    private const TAIL_CHARS = 6000;

    /**
     * A digest for the given authority, or null when one could not be produced.
     *
     * Never throws: a missing digest degrades the reader to full text, which is
     * still perfectly usable, so a model outage must not fail the crawl that
     * carries the actual legal text.
     */
    public function generate(string $text, ?string $title = null): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        [$provider, $model] = $this->resolveProvider();

        if ($provider === null) {
            return null;
        }

        $instructions = $this->instructions();

        $agent = new class($instructions) implements Agent
        {
            use Promptable;

            public function __construct(private readonly string $prompt)
            {
                //
            }

            public function instructions(): string
            {
                return $this->prompt;
            }
        };

        try {
            $response = $agent->prompt(
                $this->promptFor($text, $title),
                [],
                $provider,
                $model,
            );
        } catch (Throwable) {
            return null;
        }

        return $this->read((string) $response->text);
    }

    /**
     * The standing instructions every digest is written against, inline or
     * batched. Held here rather than inside the agent so a batch — which sends
     * the instruction block itself rather than an agent — writes digests to
     * exactly the same specification.
     */
    public function instructions(): string
    {
        return <<<'PROMPT'
You write digests of Philippine legal authorities for practising lawyers.

For a COURT DECISION, use exactly these labelled lines, each on its own line:
Nature: the kind of case and how it reached this court, in one sentence.
Facts: the material facts, in two or three sentences.
Issue: the question the court actually decided, phrased as a question.
Ruling: how the court resolved that issue, and the disposition.
Doctrine: the rule this case is cited for, stated so it can be applied to other facts.

For a STATUTE, RULE, or ADMINISTRATIVE ISSUANCE, use exactly these lines:
Nature: what the instrument is and what it governs.
Scope: who and what it applies to.
Key provisions: the operative rules, as up to four short bullet lines beginning with "- ".
Notes: amendments, repeals, or effectivity dates that appear in the text.

Rules:
- Use only what the supplied text states. Never add a holding, a date, a section
  number, or a party the text does not contain.
- If the text is too fragmentary to digest (a navigation page, an index, an
  error page), reply with exactly: NO_DIGEST
- Write plainly, in English, with no preamble and no closing commentary.
- Do not use markdown headings, bold, or the peso sign; write "PHP" for amounts.
PROMPT;
    }

    /**
     * The user turn for one authority: its title, and as much of its text as
     * is worth sending.
     */
    public function promptFor(string $text, ?string $title = null): string
    {
        return "Digest the following authority.\n\n"
            .($title !== null && $title !== '' ? "Title: {$title}\n\n" : '')
            .$this->excerpt($text);
    }

    /**
     * A model answer as a stored digest, or null when there is nothing worth
     * storing. NO_DIGEST is the model saying the page was an index or an error
     * page rather than an authority; an empty answer means the same thing.
     */
    public function read(string $answer): ?string
    {
        $digest = trim($answer);

        return $digest === '' || $digest === 'NO_DIGEST' ? null : $digest;
    }

    /**
     * Whether digests should be batched rather than written inline.
     *
     * Batching only applies to work nobody is waiting on — the crawl and the
     * bulk backfill. A digest generated on first read stays inline whatever
     * this says: a reader is watching, and a batch takes up to a day.
     */
    public function batches(): bool
    {
        if (! config('saligan.crawler.digest.batch.enabled', false)) {
            return false;
        }

        return $this->batchProvider() !== null;
    }

    /**
     * The provider batched digests run on, or null when this deployment's
     * digest provider has no batch API.
     */
    public function batchProvider(): ?Lab
    {
        $provider = $this->resolveProvider()[0];

        return in_array($provider, [Lab::Anthropic, Lab::Gemini], true) ? $provider : null;
    }

    /**
     * The model batched digests run on, or null when this deployment is not
     * digesting on a provider that batches.
     */
    public function batchModel(): ?string
    {
        [$provider, $model] = $this->resolveProvider();

        return in_array($provider, [Lab::Anthropic, Lab::Gemini], true) ? $model : null;
    }

    /**
     * The head and tail of a long document, joined by an elision marker so the
     * model is not led into treating the two halves as contiguous.
     */
    protected function excerpt(string $text): string
    {
        if (mb_strlen($text) <= self::HEAD_CHARS + self::TAIL_CHARS) {
            return $text;
        }

        return mb_substr($text, 0, self::HEAD_CHARS)
            ."\n\n[... middle of the document omitted ...]\n\n"
            .mb_substr($text, -self::TAIL_CHARS);
    }

    /**
     * The provider used for digests, or [null, ''] when none is configured.
     * Digesting is optional work, so an unconfigured provider is a skip rather
     * than an error.
     *
     * @return array{0: Lab|null, 1: string}
     */
    protected function resolveProvider(): array
    {
        $provider = config('saligan.crawler.digest.provider', 'gemini');
        $model = config('saligan.crawler.digest.model');

        return match ($provider) {
            'none', '', null => [null, ''],
            'openai' => filled(config('ai.providers.openai.key'))
                ? [Lab::OpenAI, $model ?: 'gpt-4o-mini']
                : [null, ''],
            'ollama' => [Lab::Ollama, $model ?: config('saligan.chat.ollama_model')],
            default => filled(config('ai.providers.gemini.key'))
                ? [Lab::Gemini, $model ?: config('saligan.chat.gemini_model')]
                : [null, ''],
        };
    }
}
