<?php

namespace App\Ai\Tools;

use App\Ai\WebResearchAgent;
use App\Support\ChatStatus;
use App\Support\WebCitationParser;
use App\Support\WebSearchCollector;
use App\Support\WebSourceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Web search for the chat model, served by a Gemini Flash search agent instead
 * of the answering provider's own web search.
 *
 * The chat model asks for a search the ordinary way; the search itself runs on
 * Flash with Google Search grounding, and what comes back is the source
 * material plus the numbered cards the UI renders. The answering model never
 * issues a billed search of its own — the searching is delegated to the
 * cheapest model that can do it well, while the answer stays with the model
 * that writes the answers.
 *
 * The sources are recorded on the shared per-turn collector rather than only
 * returned to the model, because a tool result reaches the model and nothing
 * else: the collector is what the controller streams as citation cards and
 * what the service persists onto the message.
 */
class WebSearchTool implements Tool
{
    /**
     * How many searches this turn has already run.
     */
    protected int $searches = 0;

    /**
     * @param  WebSearchCollector  $citations  Per-turn record of the sources
     *                                         found, shared with the streaming
     *                                         and persistence paths.
     * @param  (callable(string, ?string): void)|null  $onStatus  Fired the moment the
     *                                                            model calls this tool, so
     *                                                            status reflects actual tool
     *                                                            execution.
     */
    public function __construct(
        private readonly WebSearchCollector $citations,
        private readonly mixed $onStatus = null,
        /**
         * How many searches this turn may run, when the caller has decided it
         * from the user's plan. Null falls back to the configured cap.
         */
        private readonly ?int $maxSearches = null,
    ) {}

    /**
     * Get the tool name used in the schema and model conversations.
     */
    public function name(): string
    {
        return 'web_search';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Search the public web for Philippine legal sources — statutes, rules, administrative issuances, '
            .'and Supreme Court decisions — and get back what those sources say, with numbered citations. '
            .'Call this when the retrieved context is empty, stale, or silent on the point; when the user asks you '
            .'to verify, investigate, or check a source; or when you need to confirm whether a provision has been '
            .'amended or repealed. Pass one specific, self-contained legal question or citation per call (e.g. '
            .'"prescriptive period to file an unlawful detainer case under Rule 70" or "has RA 6657 Section 6 been '
            .'amended"), never the user\'s raw message and never a bare keyword. Search again with a narrower query '
            .'if the first result is off-point, but do not repeat a search you have already run. '
            .'Cite what you use as "[Web N]" with the "cite_as" number this tool returned for that source, '
            .'immediately after the sentence it supports — never write the title, site name, or URL yourself, and '
            .'never list web results under "Sources"; the app renders them as clickable cards. Each source is '
            .'returned with the title of the page it actually is: attach a case name or G.R. number to a source '
            .'only when that source IS that decision. A later case quoting an earlier one is not that earlier case '
            .'— cite it under its own name ("as applied in ..."), or state the rule without a web marker. '
            .'If this tool returns no sources, say so plainly and do not fabricate a citation.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $query = trim((string) $request->string('query'));

        if ($query === '') {
            return $this->respond(null, 'No query was given. Call this tool again with a specific legal question.');
        }

        // Each search is a separate billed grounding request and a separate
        // wait the user sits through, so a model that decides to search its
        // way through a question is capped rather than left to run.
        if ($this->searches >= $this->maxSearches()) {
            return $this->respond($query, 'The web search limit for this answer has been reached. Answer from the '
                .'sources already returned, and say plainly what remains unverified.');
        }

        $this->searches++;

        $this->onStatus?->__invoke('searching_web', ChatStatus::label('searching_web', $query));

        try {
            $response = (new WebResearchAgent($this->maxResults()))->prompt(
                $query,
                provider: $this->provider(),
                model: (string) config('saligan.web_search.model'),
            );
        } catch (Throwable $e) {
            // A failed search must not fail the answer: the model is told the
            // search came back empty and answers from what it already has.
            Log::warning('Delegated web search failed', [
                'query' => $query,
                'provider' => $this->provider()->value,
                'model' => config('saligan.web_search.model'),
                'exception' => $e->getMessage(),
            ]);

            return $this->respond($query, 'The web search could not be completed. Answer from the retrieved context '
                .'and say plainly that the web could not be checked.');
        }

        $found = array_slice(
            WebCitationParser::fromMeta($response->meta->citations ?? new Collection),
            0,
            $this->maxResults(),
        );

        // Grounding reports a redirect titled with a bare domain, which names
        // the publisher and not the authority. Resolving each source to the
        // page itself is what lets the model tell one result from another —
        // and lets it see when a source is a later case quoting the one it is
        // writing about, rather than that case.
        $sources = $this->citations->record(WebSourceResolver::resolve($found));
        $findings = trim((string) $response->text);

        Log::info('Delegated web search completed', [
            'query' => $query,
            'model' => config('saligan.web_search.model'),
            'sources' => count($sources),
            'input_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
        ]);

        if ($sources === []) {
            return $this->respond($query, $findings === ''
                ? 'The web search returned no usable sources. Do not cite the web for this point.'
                : $findings.' — no citable source was returned, so do not cite the web for this point.');
        }

        return $this->encode([
            'query' => $query,
            'findings' => $findings,
            'sources' => array_map(fn (array $source): array => [
                'cite_as' => '[Web '.$source['index'].']',
                'title' => $source['title'],
                'url' => $source['url'],
            ], $sources),
        ]);
    }

    /**
     * A result carrying no citable source, with the instruction the model
     * should follow in its place.
     */
    protected function respond(?string $query, string $findings): string
    {
        return $this->encode([
            'query' => $query,
            'findings' => $findings,
            'sources' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The provider the search runs on.
     */
    protected function provider(): Lab
    {
        return Lab::tryFrom((string) config('saligan.web_search.provider')) ?? Lab::Gemini;
    }

    protected function maxResults(): int
    {
        return max(1, (int) config('saligan.web_search.max_results', 6));
    }

    protected function maxSearches(): int
    {
        return max(1, $this->maxSearches ?? (int) config('saligan.web_search.max_searches', 4));
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description(
                'One specific, self-contained legal research question or citation to look up, written as a full '
                .'phrase rather than keywords (e.g. "requirements for extrajudicial settlement of estate under '
                .'Rule 74 Section 1"). Name the statute, rule, issuance, or G.R. number when you know it. Never '
                .'pass the user\'s raw message, and never pass facts about the user or their client.'
            ),
        ];
    }
}
