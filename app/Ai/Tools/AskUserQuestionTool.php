<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Asks the user to choose between concrete options instead of writing the
 * choice out as prose.
 *
 * A reply that ends "would you like a demand letter or a barangay complaint?"
 * is a dead end in a chat window: the user has to retype one of the options,
 * and the model has to re-derive what they meant. This tool turns the same
 * question into selectable cards, with an "Other" escape the user can explain
 * in their own words — so the answer comes back structured, and the turn that
 * follows knows exactly what was chosen.
 *
 * Like request_intake_form, the controller cuts the stream the moment this
 * tool is called: the answer cannot be known until the user gives it, so
 * anything the model wrote past the call would be built on a guess.
 */
class AskUserQuestionTool implements Tool
{
    /**
     * @param  (callable(string, ?string): void)|null  $onStatus  Fired the
     *                                                            moment the
     *                                                            model calls
     *                                                            this tool, so
     *                                                            status reflects
     *                                                            actual tool
     *                                                            execution.
     */
    public function __construct(
        private readonly mixed $onStatus = null,
    ) {}

    /**
     * Get the tool name used in the schema and model conversations.
     */
    public function name(): string
    {
        return 'ask_user_question';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Asks the user to pick between concrete options when the turn cannot proceed until they decide — '
            .'which document to prepare, which of several remedies to pursue, which party to address, whether to '
            .'proceed now or wait for a deadline. Use this INSTEAD of writing the choice out in chat ("would you '
            .'like A or B?", "what would you like to do?", "let me know which you prefer") — a question written as '
            .'prose leaves the user retyping an option you already knew. '
            .'Each question carries 2 to 4 mutually exclusive options, each with a short label and a one-line '
            .'description of what choosing it means. The user can always answer "Other" in their own words, so never '
            .'add an "Other", "Something else", or "None of these" option yourself. '
            .'Batch every decision you need into ONE call (up to 4 questions) rather than asking one at a time. '
            .'Call this AT MOST ONCE per turn, then stop and wait for the answer — never keep writing past the call, '
            .'and never re-ask something the user has already answered in this conversation. '
            .'Do NOT use this to collect facts for a document (names, addresses, dates, amounts) — that is what '
            .'request_intake_form is for. Do NOT use it when only one course of action is sensible, or when the '
            .'answer is already in the conversation, the case context, or an uploaded document: decide and proceed.';
    }

    /**
     * Execute the tool.
     *
     * In the streaming path this never runs — the controller breaks the stream
     * on the tool call so the question reaches the user before any answer is
     * assumed. It stays honest for any other caller: the directive says the
     * turn is over until the user answers.
     */
    public function handle(Request $request): string
    {
        $this->onStatus?->__invoke('awaiting_choice');

        $questions = $request->array('questions') ?? [];

        return json_encode([
            'status' => 'awaiting_user_choice',
            'questions' => $questions,
            'directive' => 'The question has been put to the user and their answer is not available yet. '
                .'Stop here — do not answer on their behalf, do not guess which option they will pick, and do not '
                .'call this tool again. The user\'s selection arrives as the next message, prefixed '
                .'"[Choice Selection]".',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'questions' => $schema->array()
                ->description('The decisions to put to the user, 1 to 4 of them, all in this single call')
                ->items(
                    $schema->object([
                        'question' => $schema->string()->description('The question as the user reads it, phrased in full and ending in a question mark (e.g. "Which document should I prepare first?")'),
                        'header' => $schema->string()->description('A 1-3 word label for what is being decided, shown as a chip above the options (e.g. "Document", "Venue", "Timing")'),
                        'multi_select' => $schema->boolean()->description('True only when the options genuinely combine and the user may pick several. Default false — most decisions are one-of.'),
                        'options' => $schema->array()
                            ->description('2 to 4 mutually exclusive choices. Never include an "Other" or "None of these" option — the user always has one.')
                            ->items(
                                $schema->object([
                                    'label' => $schema->string()->description('The choice itself, 1-5 words, distinct from every other label in this question (e.g. "Demand letter")'),
                                    'description' => $schema->string()->description('One line on what picking this means or leads to (e.g. "Formal demand sent before any filing"). Never restate the label.'),
                                ])
                            ),
                    ])
                ),
        ];
    }
}
