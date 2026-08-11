<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class FillTemplateFieldsTool implements Tool
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
        return 'fill_template_fields';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Supply the exact replacement value for every bracketed placeholder in the user\'s uploaded template, '
            .'based on the user\'s request, the conversation, and any intake form submission — without altering, '
            .'reordering, or rewriting anything else in the template. '
            .'Call this ONLY when the user has selected a Verbatim Template (their own uploaded .docx with '
            .'letterhead/logo/branding). Do NOT call this for system templates or templates without an original '
            .'file. Your only output for this turn is a call to fill_template_fields with the field values. '
            .'Do NOT write the letter as prose. Do NOT use [[DOCUMENT_START]]/[[DOCUMENT_END]] markers.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $this->onStatus?->__invoke('filling_template');

        $fields = $request->array('fields') ?? [];

        return json_encode([
            'fields' => $fields,
            'status' => 'filled',
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
                ->description('Array of field values to fill into the template placeholders')
                ->items(
                    $schema->object([
                        'key' => $schema->string()->description('The placeholder key as it appears in the template (e.g., "[Client Full Name]", "[Date]"). Include the brackets.'),
                        'value' => $schema->string()->description('The exact text that should replace this placeholder — nothing more, nothing less. Do not include the brackets in the value.'),
                    ])
                ),
        ];
    }
}
