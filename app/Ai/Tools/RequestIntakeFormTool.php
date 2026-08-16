<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RequestIntakeFormTool implements Tool
{
    /**
     * @param  (callable(string, ?string): void)|null  $onStatus  Fired the
     *                                                            moment the
     *                                                            model calls
     *                                                            this tool, so
     *                                                            status reflects
     *                                                            actual tool
     *                                                            execution.
     * @param  bool  $suppressed  When true, the case context already supplies
     *                            the facts, so the tool does not collect a form
     *                            — it instructs the model to draft directly
     *                            from the case context instead.
     * @param  bool  $alreadySubmitted  When true, this turn IS the answer to a
     *                                  form the user already filled in. Opening
     *                                  a second one would discard the draft in
     *                                  progress and re-ask what was just given.
     */
    public function __construct(
        private readonly mixed $onStatus = null,
        private readonly bool $suppressed = false,
        private readonly bool $alreadySubmitted = false,
    ) {}

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
        return 'Requests missing facts from the user via a structured form, for drafting a legal document '
            .'(complaint, demand letter, contract, affidavit, special power of attorney, deed of sale, etc.). '
            .'Call this ONLY when required facts for the requested document are not yet known — e.g. the user gave '
            .'a bare instruction ("draft a complaint letter") with no supporting details, or a specific required '
            .'field for the chosen document type is still unknown after checking the conversation, the case '
            .'context, and any uploaded documents or template. '
            .'Do NOT call this if the needed facts are already available from prior chat messages, case context, '
            .'uploaded documents, or a previously submitted intake form — extract and reuse what is already known. '
            .'Call this AT MOST ONCE per drafting request. Once you have called it (or once you have determined no '
            .'call is needed), proceed straight to drafting — never call it again for the same request unless the '
            .'user explicitly asks to add or change facts afterward. '
            .'When you do call it, include ONLY the fields whose values you do not already have — never re-request '
            .'a fact you already know. Each field you pass is added to the form, so label it as the question you '
            .'would ask the user about THIS matter, and when the answer is one of a known set (how the heirs want a '
            .'lot divided, which agency the letter goes to), pass those as options so the user picks one instead of '
            .'typing a paraphrase.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if ($this->alreadySubmitted) {
            // The user just filled the form in; the values are in this turn's
            // message. A second form is never the answer here — if a fact is
            // still missing, the MISSING FACT LADDER covers it.
            return 'INTAKE FORM ALREADY SUBMITTED: the user answered this form in the message you are replying to. '
                .'Do NOT call request_intake_form again and do NOT ask for these facts in chat. '
                .'Draft the complete document now from the submitted values, the conversation history, the case context, '
                .'and any uploaded documents. For anything still genuinely missing, apply the MISSING FACT LADDER.';
        }

        if ($this->suppressed) {
            // The case context already supplies the facts, so the form is not
            // shown. The model receives this directive as the tool result and
            // must draft straight from the case context instead of interrupting
            // the user — and never call this tool again for the same request.
            return 'INTAKE FORM SUPPRESSED: the CASE CONTEXT above already contains the facts needed to draft this document. '
                .'Do NOT call request_intake_form again and do NOT ask the user for these facts in chat. '
                .'Draft the complete document now using the CASE CONTEXT, the conversation history, and any uploaded documents.';
        }

        $this->onStatus?->__invoke('gathering_facts');

        $documentType = $request->string('document_type') ?? null;
        $fields = $request->array('fields') ?? [];

        return json_encode([
            'document_type' => $documentType,
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
            'document_type' => $schema->string()
                ->description('The type of document being drafted, e.g. "government transaction letter", "formal letter", "agreement", "deed", "complaint", "affidavit", or "special power of attorney"'),
            'fields' => $schema->array()
                ->description('Array of form fields to collect from the user')
                ->items(
                    $schema->object([
                        'key' => $schema->string()->description('Unique field identifier (e.g., sender_name, incident_date)'),
                        'label' => $schema->string()->description('Human-readable label for the field'),
                        'type' => $schema->string()->description('Field type: text, date, number, select, textarea, radio, or checkbox'),
                        'options' => $schema->array()->description('For select type: array of option values'),
                        'required' => $schema->boolean()->description('Whether this field is required'),
                    ])
                ),
        ];
    }
}
