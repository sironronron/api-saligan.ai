<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\LetterDrafts\LetterDraftService;
use App\Support\ToolResult;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Drafts a letter as a Tiptap/ProseMirror document so the result loads straight
 * into the in-app letter editor. The model delegates to this tool instead of
 * writing the letter itself; the heavy lifting is done by the dedicated
 * LetterDraftAgent + LetterDraftService sanitizer, whose only job is emitting
 * valid editor JSON.
 */
class DraftLetterTool implements Tool
{
    /**
     * @param  string  $caseContext  The conversation's case-context block, appended to the
     *                               model-authored request so a case-scoped draft keeps the case facts.
     * @param  User|null  $user  The drafting user, for the sender block and provider choice.
     * @param  Closure(array{content: array<string, mixed>, title: string, raw: string}): void  $onDrafted
     *                                                                                                      Records the finished draft on the service so it can be persisted
     *                                                                                                      onto the assistant message metadata.
     */
    public function __construct(
        private readonly string $caseContext,
        private readonly ?User $user,
        private readonly Closure $onDrafted,
    ) {}

    /**
     * The draft already produced this turn, keyed by the provider's tool-call
     * id. Drafting a letter is a second model call that takes seconds and
     * costs tokens; a provider retry must not run it twice, and a model that
     * calls the tool again mid-turn must not silently replace the document the
     * editor has already opened.
     *
     * @var array<string, string>
     */
    protected array $served = [];

    /**
     * Get the tool name used in the schema and model conversations.
     */
    public function name(): string
    {
        return 'draft_letter';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Draft a complete letter as a structured, editable document. Call this ONCE when the user asks you to '
            .'draft, prepare, write, or create a LETTER — a formal letter, demand letter, notice, reply, or any '
            .'correspondence addressed to a recipient, including a government office — and you have the facts you '
            .'need. Pass the complete drafting request in `request`: who the letter is to and from, the subject, every '
            .'known fact (names, addresses, dates, amounts, reference numbers), what the recipient must do, and any '
            .'deadline. Do NOT use this tool for complaints, deeds, affidavits, contracts, agreements, or powers of '
            .'attorney — draft those directly. Do NOT write the letter yourself in chat and do NOT wrap it in '
            .'document markers.';
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

        $draftRequest = trim((string) $request->string('request'));

        if ($draftRequest === '') {
            // Not an early return: the case context and the conversation the
            // agent already has are usually enough, and refusing here would
            // lose a letter over a missing argument the model can recover from.
            $draftRequest = 'Draft a complete letter using every fact available.';
        }

        if ($this->caseContext !== '') {
            $draftRequest .= "\n\n=== CASE CONTEXT ===\n".$this->caseContext;
        }

        try {
            $draft = app(LetterDraftService::class)->generate($draftRequest, $this->user);
        } catch (Throwable $exception) {
            // The letter agent is a second provider call inside this turn: it
            // can time out, be rate-limited, or return something the sanitizer
            // rejects. None of that is a reason to lose the answer the user is
            // already reading — the model is told what happened and writes the
            // letter in chat instead, where the inline-recovery path picks it
            // up into the editor.
            Log::warning('Letter drafting failed inside the chat turn', [
                'user_id' => $this->user?->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->remember($callId, ToolResult::none(
                'The letter editor could not produce a draft.',
                'No letter was created and the editor did not open — do not tell the user to check it. '
                    .'Write the complete letter directly in your reply instead, wrapped in [[DOCUMENT_START]] and '
                    .'[[DOCUMENT_END]], and do not call this tool again this turn.',
            ));
        }

        if (! is_array($draft['content'] ?? null)) {
            Log::warning('Letter drafting returned no usable document', [
                'user_id' => $this->user?->id,
            ]);

            return $this->remember($callId, ToolResult::none(
                'The letter draft came back empty.',
                'No letter was created and the editor did not open — do not tell the user to check it. '
                    .'Write the complete letter directly in your reply instead, wrapped in [[DOCUMENT_START]] and '
                    .'[[DOCUMENT_END]], and do not call this tool again this turn.',
            ));
        }

        ($this->onDrafted)($draft);

        // The document itself goes back so the model can summarize it, but it
        // is told plainly not to reproduce it: the letter lives in the editor,
        // and a reply that also contains the whole letter doubles the length of
        // the turn and disagrees with the editor the moment either is edited.
        return $this->remember($callId, ToolResult::ok([
            'title' => $draft['title'] ?? null,
            'content' => $draft['content'],
        ], directive: 'The letter is drafted and open in the editor. Write one or two sentences telling the user '
            .'it is ready and what it says — do NOT reproduce the letter in your reply, and do not call this tool '
            .'again this turn.'));
    }

    /**
     * Cache a call's result against its provider tool-call id, so a retry
     * returns the first attempt's draft rather than generating another.
     */
    protected function remember(?string $callId, string $result): string
    {
        if ($callId !== null) {
            $this->served[$callId] = $result;
        }

        return $result;
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'request' => $schema->string()
                ->required()
                ->description('The complete letter-drafting request, including every known fact: who the letter is to and from, the subject, names, addresses, dates, amounts, reference numbers, what the recipient should do, and any deadline.'),
        ];
    }
}
