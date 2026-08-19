<?php

namespace App\Ai\Tools;

use App\Models\Todo;
use App\Support\ToolInput;
use App\Support\ToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Files the concrete next steps a drafted document leaves the user with.
 *
 * Everything the model passes is treated as untrusted. `status` and `priority`
 * are database enums, so a value outside their sets is not a bad row but a
 * constraint violation that takes down a stream which has already delivered
 * half an answer — and a model writing "in progress" or "urgent" instead of
 * the listed values is ordinary, not exceptional. Items are normalized,
 * bounded, deduplicated, and written one at a time so a single bad item costs
 * that item rather than the whole list.
 */
class CreateTodoTool implements Tool
{
    /** More than this in one call is a model listing a document, not next steps. */
    private const MAX_ITEMS = 25;

    /**
     * Calls already served this turn, keyed by the provider's tool-call id.
     * A retried call — the provider re-sending after a timeout, or a model
     * calling twice for the same document — would otherwise file every task
     * a second time.
     *
     * @var array<string, string>
     */
    protected array $served = [];

    /**
     * @param  (callable(string, ?string): void)|null  $onStatus  Fired the moment the model
     *                                                            calls this tool, so status
     *                                                            reflects actual tool execution.
     */
    public function __construct(
        public readonly string $conversationId,
        private readonly mixed $onStatus = null,
    ) {}

    /**
     * Get the tool name used in the schema and model conversations.
     */
    public function name(): string
    {
        return 'create_todo';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Create todo items for the concrete next steps the user must take for the document just drafted — '
            .'one item per real action, verb-first and self-contained (e.g., "File the complaint with the RTC", '
            .'"Pay the filing fees", "Serve the demand letter with proof of receipt", "Have the deed notarized"). '
            .'Call this exactly once per drafted document, immediately after the document and its checklist are '
            .'finalized — never call it before the document is complete, and never call it more than once for the '
            .'same document. The items you pass here must be identical in wording and order to the checklist you '
            .'write in the [[TODO_START]]/[[TODO_END]] text block — build the checklist once and use it for both. '
            .'Set priority (low/medium/high) and due_hint only when the document itself states a deadline or period '
            .'(e.g., "Within 15 days of receipt") — never invent them. Order by urgency and merge near-duplicate '
            .'steps. If the document genuinely has no follow-up actions, do NOT call this tool at all — never '
            .'fabricate a placeholder item just to have something to call it with. '
            .'The result tells you exactly which items were accepted: describe those and no others.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $callId = $request->toolCallId();

        if ($callId !== null && isset($this->served[$callId])) {
            return $this->served[$callId];
        }

        $this->onStatus?->__invoke('preparing_next_steps');

        $items = ToolInput::items($request->array('items'), self::MAX_ITEMS);

        if ($items === []) {
            return $this->remember($callId, ToolResult::none(
                'No usable task items were supplied.',
                'No tasks were created. Do not tell the user you added anything to their task list. '
                    .'If the document really does have follow-up steps, write them in the reply instead.',
            ));
        }

        // Existing titles on this thread, so a re-drafted document does not
        // file the same checklist a second time. Read once rather than per
        // item: a drafting turn files five or six tasks together.
        $seen = Todo::query()
            ->where('conversation_id', $this->conversationId)
            ->pluck('title')
            ->map(fn (string $title): string => $this->comparisonKey($title))
            ->all();

        $created = [];
        $rejected = [];

        foreach ($items as $position => $item) {
            $title = $this->sanitizeTitle(ToolInput::text($item, 'title', 255));

            if ($title === '') {
                $rejected[] = 'Item '.($position + 1).' had no usable title.';

                continue;
            }

            $key = $this->comparisonKey($title);

            if (in_array($key, $seen, true)) {
                $rejected[] = '"'.$title.'" is already on this thread\'s task list.';

                continue;
            }

            try {
                $todo = Todo::create([
                    'conversation_id' => $this->conversationId,
                    'title' => $title,
                    'status' => ToolInput::enum($item, 'status', Todo::STATUSES, 'pending'),
                    'priority' => $this->optionalEnum($item, 'priority', Todo::PRIORITIES),
                    'due_hint' => ToolInput::text($item, 'due_hint', 120) ?: null,
                ]);
            } catch (Throwable $exception) {
                // One row failing must not cost the rest of the checklist, and
                // must not take down a stream that has already delivered the
                // document these steps belong to.
                Log::warning('Could not create a todo from a tool call', [
                    'conversation_id' => $this->conversationId,
                    'title' => $title,
                    'exception' => $exception->getMessage(),
                ]);

                $rejected[] = '"'.$title.'" could not be saved.';

                continue;
            }

            $seen[] = $key;

            $created[] = [
                'id' => $todo->id,
                'title' => $todo->title,
                'status' => $todo->status,
                'priority' => $todo->priority,
                'due_hint' => $todo->due_hint,
            ];
        }

        if ($created === []) {
            return $this->remember($callId, ToolResult::none(
                'Every item was rejected: '.implode(' ', $rejected),
                'No tasks were created. Do not tell the user you added anything to their task list.',
            ));
        }

        return $this->remember($callId, ToolResult::ok([
            'accepted' => count($created),
            'items' => $created,
        ], $rejected));
    }

    /**
     * Cache a call's result against its provider tool-call id, so a retry of
     * the same call returns what the first attempt did instead of writing the
     * tasks again.
     */
    protected function remember(?string $callId, string $result): string
    {
        if ($callId !== null) {
            $this->served[$callId] = $result;
        }

        return $result;
    }

    /**
     * An optional enum: absent stays absent rather than defaulting, because a
     * priority nobody set is not the same as a low priority.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $allowed
     */
    protected function optionalEnum(array $item, string $key, array $allowed): ?string
    {
        if (trim((string) ($item[$key] ?? '')) === '') {
            return null;
        }

        $value = ToolInput::enum($item, $key, $allowed, '');

        return $value !== '' ? $value : null;
    }

    /**
     * Strip the markdown a model writes around a checklist line, so a stored
     * task is plain text.
     *
     * The model builds the same checklist twice — once as the [[TODO_START]]
     * text block, once as these arguments — and routinely carries the text
     * block's bullet and checkbox syntax into the arguments with it.
     */
    protected function sanitizeTitle(string $title): string
    {
        $cleaned = trim($title);

        // Applied repeatedly rather than in one pass: the prefixes nest in
        // whatever order the model wrote them ("**2. Step**", "- [ ] **Step**",
        // "**- [x] Step**"), so a single ordered sweep always leaves one of the
        // arrangements half-stripped.
        for ($pass = 0; $pass < 4; $pass++) {
            $before = $cleaned;

            // Emphasis wrapping the whole line.
            $cleaned = (string) preg_replace('/^([*_]{1,3})(.+)\1$/us', '$2', trim($cleaned));

            // Leading list marker: "-", "*", "+", "•", "1.", "1)".
            $cleaned = (string) preg_replace('/^\s*(?:[-*+•]|\d+[.)])\s+/u', '', $cleaned);

            // Leading checkbox in any of the forms a model writes it.
            $cleaned = (string) preg_replace('/^\s*[*_]{0,2}\[\s*[xX_✓]?\s*\][*_]{0,2}\s*/u', '', $cleaned);

            $cleaned = trim($cleaned);

            if ($cleaned === $before) {
                break;
            }
        }

        // A trailing colon left behind when the model wrote the step as a
        // heading for a paragraph it then dropped.
        $cleaned = (string) preg_replace('/\s*:$/u', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * A normalized form of a title, for deciding whether two tasks say the
     * same thing. Case, punctuation, and spacing vary between drafts even when
     * the step does not.
     */
    protected function comparisonKey(string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(
            (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title)
        )));
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->description('The next steps to file, most urgent first. At most '.self::MAX_ITEMS.'.')
                ->items(
                    $schema->object([
                        'title' => $schema->string()->description('Short, verb-first title of one concrete action item (e.g., "Pay the filing fees"). Keep it scannable; do not paste whole paragraphs, and do not include markdown bullets or checkboxes.'),
                        'status' => $schema->string()->description('Initial status, exactly one of: pending, on-going, completed. Omit for pending.'),
                        'priority' => $schema->string()->description('Exactly one of: low, medium, high. Base it on deadlines or the consequence of missing the step. Omit when the document states neither.'),
                        'due_hint' => $schema->string()->description('Timeframe only when the document states one (e.g., "Within 15 days of receipt", "Before the August 5 hearing"). Omit if no deadline or period is given.'),
                    ])
                ),
        ];
    }
}
