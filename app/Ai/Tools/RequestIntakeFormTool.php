<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RequestIntakeFormTool implements Tool
{
    /**
     * Get the tool name used in the schema and model conversations.
     */
    public function name(): string
    {
        return 'request_intake_form';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'MANDATORY FIRST STEP when the user asks you to draft, prepare, write, or create ANY legal document (complaint, demand letter, contract, affidavit, special power of attorney, deed of sale, etc.). Call this tool to request the needed facts via a structured form. Never draft a legal document without calling this tool first, and never ask the user for facts inline in chat.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $fields = $request->array('fields') ?? [];

        return json_encode([
            'fields' => $fields,
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
            'fields' => $schema->array()
                ->description('Array of form fields to collect from the user')
                ->items(
                    $schema->object([
                        'key' => $schema->string()->description('Unique field identifier (e.g., plaintiff_name, incident_date)'),
                        'label' => $schema->string()->description('Human-readable label for the field'),
                        'type' => $schema->string()->description('Field type: text, date, number, select, textarea, radio, or checkbox'),
                        'options' => $schema->array()->description('For select type: array of option values'),
                        'required' => $schema->boolean()->description('Whether this field is required'),
                    ])
                ),
        ];
    }
}
