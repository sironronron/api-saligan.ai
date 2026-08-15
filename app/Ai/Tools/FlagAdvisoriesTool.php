<?php

namespace App\Ai\Tools;

use App\Services\Chat\AdvisoryRecorder;
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
        $this->onStatus?->__invoke('reviewing_gaps');

        $created = app(AdvisoryRecorder::class)->record(
            $this->conversationId,
            $request->array('items') ?? [],
        );

        return json_encode([
            'items' => $created,
            // The model does not need to repeat these in the reply — the app
            // shows them on their own. Saying so here keeps it from writing the
            // same list out twice.
            'note' => 'Filed. The user reviews these in the app; do not repeat them in your reply.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
