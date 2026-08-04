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
        return 'MANDATORY after drafting any legal document. Create one or more todo items representing concrete next steps the user must take for their case (e.g., file the complaint, pay filing fees, have the document notarized, gather evidence, send the demand letter, comply by the deadline). Call this tool immediately after you finish drafting — never skip it. If there are no follow-up actions, call it with a single item describing the next step anyway.';
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
                        'title' => $schema->string()->description('Title or description of the action item'),
                        'status' => $schema->string()->description('Initial status: pending, on-going, or completed'),
                        'priority' => $schema->string()->description('Priority level: low, medium, or high'),
                        'due_hint' => $schema->string()->description('Suggested timeframe (e.g., "Within 15 days", "Before hearing date")'),
                    ])
                ),
        ];
    }
}
