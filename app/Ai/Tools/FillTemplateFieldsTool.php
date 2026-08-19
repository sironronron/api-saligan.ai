<?php

namespace App\Ai\Tools;

use App\Support\ToolInput;
use App\Support\ToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class FillTemplateFieldsTool implements Tool
{
    /** A template with more placeholders than this is not a letter. */
    private const MAX_FIELDS = 80;

    /**
     * @param  (callable(string, ?string): void)|null  $onStatus  Fired the moment the model calls
     *                                                            this tool, so status reflects
     *                                                            actual tool execution.
     * @param  (callable(array<int, array{key?: string, value?: string}>): void)|null  $onFields  Receives the values the
     *                                                                                            model supplied. Without this the tool result would go
     *                                                                                            back to the model and nowhere else, leaving the export
     *                                                                                            with nothing to fill the template with.
     */
    public function __construct(
        private readonly mixed $onStatus = null,
        private readonly mixed $onFields = null,
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

        $accepted = [];
        $rejected = [];

        foreach (ToolInput::items($request->array('fields'), self::MAX_FIELDS) as $position => $field) {
            $key = ToolInput::text($field, 'key', 200);
            // The replacement itself may legitimately run to several lines —
            // an address block, a recital — so it is not collapsed the way a
            // placeholder key is.
            $value = ToolInput::multilineText($field, 'value', 4000);

            if ($key === '') {
                $rejected[] = 'Field '.($position + 1).' had no placeholder key.';

                continue;
            }

            if ($value === '') {
                // An empty replacement would silently blank a placeholder in
                // the user's own letterhead, which reads as a corrupted export
                // rather than as a fact nobody supplied.
                $rejected[] = '"'.$key.'" had no value, so the placeholder was left as it is.';

                continue;
            }

            $accepted[] = ['key' => $key, 'value' => $value];
        }

        if ($accepted === []) {
            return ToolResult::none(
                'No usable field values were supplied.',
                'The template was not filled. Do not tell the user their document is ready. Say which details you '
                    .'still need, and call this tool again once you have them.',
            );
        }

        $this->onFields?->__invoke($accepted);

        return ToolResult::ok([
            'status' => 'filled',
            'accepted' => count($accepted),
            'fields' => $accepted,
        ], $rejected);
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
