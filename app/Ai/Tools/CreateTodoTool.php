<?php

namespace App\Ai\Tools;

use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateTodoTool implements Tool
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
            .'fabricate a placeholder item just to have something to call it with.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $this->onStatus?->__invoke('preparing_next_steps');

        $items = $request->array('items') ?? [];
        $created = [];

        foreach ($items as $item) {
            $todo = Todo::create([
                'conversation_id' => $this->conversationId,
                'title' => $this->sanitizeTitle($item['title']),
                'status' => $item['status'] ?? 'pending',
                'priority' => $item['priority'] ?? null,
                'due_hint' => $item['due_hint'] ?? null,
            ]);

            $created[] = [
                'id' => $todo->id,
                'title' => $todo->title,
                'status' => $todo->status,
                'priority' => $todo->priority,
                'due_hint' => $todo->due_hint,
            ];
        }

        return json_encode([
            'items' => $created,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Strip markdown checkbox formatting and other artifacts from the title
     * so stored tasks are clean plain text.
     *
     * Handles: "- [ ]", "- [x]", "[ ]", "[x]", "**[ ]**", "**_**", etc.
     */
    protected function sanitizeTitle(string $title): string
    {
        $cleaned = trim($title);

        // Strip leading bullet/dash and checkbox patterns
        // Matches: "- [ ] ", "- [x] ", "* [ ] ", "[ ] ", "[x] ", "**[ ]** ", "**_** "
        $cleaned = preg_replace('/^[-**\s]*\[?\]?\s*\*{0,2}\s*/u', '', $cleaned);

        // Strip bold/italic wrapping around checkbox placeholders
        // Matches: **[ ]**, **[_]**, *[ ]*, *[_]*
        $cleaned = preg_replace('/^\*{1,2}\[_?\]\*{1,2}\s*/u', '', $cleaned);

        // Strip any remaining leading/trailing markdown artifacts
        $cleaned = preg_replace('/^\*{1,2}/', '', $cleaned);
        $cleaned = preg_replace('/\*{1,2}$/', '', $cleaned);

        return trim($cleaned);
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
                ->description('Array of todo items to create')
                ->items(
                    $schema->object([
                        'title' => $schema->string()->description('Short, verb-first title of one concrete action item (e.g., "Pay the filing fees"). Keep it scannable; do not paste whole paragraphs.'),
                        'status' => $schema->string()->description('Initial status: pending, on-going, or completed'),
                        'priority' => $schema->string()->description('Priority level: low, medium, or high. Base it on deadlines or the consequence of missing the step.'),
                        'due_hint' => $schema->string()->description('Timeframe only when the document states one (e.g., "Within 15 days of receipt", "Before the August 5 hearing"). Omit if no deadline or period is given.'),
                    ])
                ),
        ];
    }
}
