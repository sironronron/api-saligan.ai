<?php

namespace App\Ai\Tools;

use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateTodoTool implements Tool
{
    public function __construct(
        public readonly string $conversationId,
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
        return 'MANDATORY after drafting any legal document. Create todo items for the concrete next steps the user must take for this specific document or case — one item per real action, verb-first and self-contained (e.g., "File the complaint with the RTC", "Pay the filing fees", "Serve the demand letter with proof of receipt", "Have the deed notarized"). Mirror the document\'s own "Next Steps" checklist item for item; do not replace it with generic advice. Set priority (low/medium/high) and due_hint only when the document states a deadline or period (e.g., "Within 15 days of receipt") — never invent them. Order by urgency and merge near-duplicate steps. Call this tool immediately after drafting — never skip it; if there are no follow-up actions, call it with a single item describing the next step anyway.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $items = $request->array('items') ?? [];
        $created = [];

        foreach ($items as $item) {
            $todo = Todo::create([
                'conversation_id' => $this->conversationId,
                'title' => $item['title'],
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
