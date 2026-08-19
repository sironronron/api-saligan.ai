<?php

namespace App\Ai\Tools;

use App\Services\Chat\AdvisoryRecorder;
use App\Support\ToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Files the caveats, gaps, assumptions, and deadlines a turn carries as rows
 * the user can answer, instead of leaving them buried in the prose at the
 * bottom of the reply where they are routinely scrolled past.
 *
 * The writing itself is AdvisoryRecorder's, so the server-side fallback that
 * recovers these from the reply text applies exactly the same guards.
 */
class FlagAdvisoriesTool implements Tool
{
    /**
     * Calls already served this turn, keyed by the provider's tool-call id.
     *
     * @var array<string, string>
     */
    protected array $served = [];

    /**
     * @param  (callable(string, ?string): void)|null  $onStatus  Fired the moment
     *                                                            the model calls
     *                                                            this tool.
     */
    public function __construct(
        public readonly string $conversationId,
        private readonly mixed $onStatus = null,
    ) {}

    public function name(): string
    {
        return 'flag_advisories';
    }

    public function description(): string
    {
        return 'Flag the caveats, gaps, unstated assumptions, legal exposure, and deadlines the user should see '
            .'before relying on this answer or document — the things they would otherwise miss. Call this exactly '
            .'once per turn, after the answer or document is finalized, and only when the turn genuinely carries '
            .'such points. Each item is one specific, self-contained point about THIS matter: a fact you had to '
            .'assume because it was never given, a provision whose application is unsettled or fact-dependent, a '
            .'prescriptive or reglementary period that is running, a step that voids the document if skipped '
            .'(notarization, registration, verification), or an exposure the chosen approach creates. Do NOT file '
            .'generic boilerplate ("consult a lawyer", "laws may change", "this is not legal advice"), do NOT '
            .'restate the next-step tasks you passed to create_todo, and do NOT file an item you already filed on '
            .'an earlier turn of this conversation. If the turn carries none of these, do not call this tool '
            .'at all — an empty call is worse than no call, and a caveat you invented so the call has content is '
            .'a fabricated fact about the user\'s matter.';
    }

    public function handle(Request $request): string
    {
        $callId = $request->toolCallId();

        // A provider retry, or a model calling twice for the same turn, would
        // otherwise re-file the whole list. The recorder's own duplicate guard
        // catches this too, but only after a second round of database reads.
        if ($callId !== null && isset($this->served[$callId])) {
            return $this->served[$callId];
        }

        $this->onStatus?->__invoke('reviewing_gaps');

        $submitted = $request->array('items');
        $created = app(AdvisoryRecorder::class)->record($this->conversationId, $submitted ?? []);

        if ($created === []) {
            return $this->remember($callId, ToolResult::none(
                'No flags were filed: every item was empty, generic boilerplate, or already raised on this conversation.',
                'Nothing was filed. Do not tell the user you flagged anything, and do not restate these points '
                    .'as a list in your reply — if a point genuinely matters, make it in the prose where it belongs.',
            ));
        }

        // The count is reported because the model is otherwise free to say
        // "I flagged five things" over a list of two — the app shows the real
        // list beside the reply, so the two contradict each other on screen.
        return $this->remember($callId, ToolResult::ok(
            ['accepted' => count($created), 'items' => $created],
            directive: 'Filed '.count($created).' item(s). The user reviews these in the app, so do not repeat '
                .'them as a list in your reply, and do not claim a different number than this.',
        ));
    }

    /**
     * Cache a call's result against its provider tool-call id, so a retry
     * returns what the first attempt did.
     */
    protected function remember(?string $callId, string $result): string
    {
        if ($callId !== null) {
            $this->served[$callId] = $result;
        }

        return $result;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->description('The caveats and gaps this turn carries, most consequential first.')
                ->items(
                    $schema->object([
                        'kind' => $schema->string()->description('One of: caveat (a qualification on the answer), gap (a fact that was never supplied and had to be worked around), risk (an exposure the approach creates), assumption (something you took as true without confirmation), deadline (a prescriptive or reglementary period that is running).'),
                        'title' => $schema->string()->description('The point in one scannable sentence, specific to this matter (e.g. "The date of the demand letter\'s receipt is unconfirmed, so the 15-day period cannot be computed"). Not a generic disclaimer.'),
                        'detail' => $schema->string()->description('One or two sentences on why it matters and what would resolve it. Omit if the title already says everything.'),
                        'severity' => $schema->string()->description('high (could void the document, lose a right, or miss a deadline), medium (materially affects the outcome), low (worth knowing).'),
                    ])
                ),
        ];
    }
}
