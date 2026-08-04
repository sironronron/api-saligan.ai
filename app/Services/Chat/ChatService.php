<?php

namespace App\Services\Chat;

use App\Ai\LegalChatAgent;
use App\Ai\Tools\CreateTodoTool;
use App\Ai\Tools\RequestIntakeFormTool;
use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SystemPrompt;
use App\Services\Retrieval\RetrievalResult;
use App\Services\Retrieval\RetrievalService;
use App\Support\DraftingIntent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message as AiMessage;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;

class ChatService
{
    public function __construct(
        private readonly RetrievalService $retrieval,
    ) {
        //
    }

    /**
     * Persist the user message, retrieve context, and start streaming the
     * assistant's response. The assistant message is persisted when the
     * stream completes.
     *
     * @param  callable(string): void  $onStatus
     */
    public function stream(Conversation $conversation, string $question, ?callable $onStatus = null): StreamableAgentResponse
    {
        if ($onStatus !== null) {
            $onStatus('checking_sources');
        }

        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => $question,
        ]);

        $retrieval = $this->retrieval->retrieve($conversation->user, $question);

        [$provider, $model] = $this->resolveProvider($conversation);

        $assistantMessageId = (string) Str::uuid();

        $instructions = $this->buildInstructions($retrieval, $provider, $assistantMessageId);

        $usesWebSearch = $retrieval->isEmpty() && $this->supportsWebSearch($provider);

        if ($usesWebSearch && $onStatus !== null) {
            $onStatus('searching_web');
        }

        Log::info('Chat stream starting', [
            'conversation_id' => $conversation->id,
            'provider' => $provider->value,
            'model' => $model,
            'retrieval_empty' => $retrieval->isEmpty(),
            'uses_web_search' => $usesWebSearch,
        ]);

        $agent = new LegalChatAgent(
            instructions: $instructions,
            messages: $this->buildHistory($conversation, $userMessage->id),
            tools: array_merge(
                [new RequestIntakeFormTool, new CreateTodoTool($conversation->id)],
                $usesWebSearch ? [new WebSearch] : []
            ),
        );

        $stream = $agent->stream(
            prompt: $question,
            provider: $provider,
            model: $model,
        );

        $stream->then(function (StreamedAgentResponse $response) use ($conversation, $retrieval, $provider, $assistantMessageId, $question): void {
            Log::info('Chat stream completed', [
                'conversation_id' => $conversation->id,
                'text_length' => strlen((string) $response->text),
            ]);

            $this->persistAssistantResponse(
                $conversation,
                $response,
                $retrieval,
                $provider,
                $assistantMessageId,
                DraftingIntent::isIntakeSubmission($question),
            );
        });

        return $stream;
    }

    /**
     * Compose the system prompt: the active Saligan persona in full, followed
     * by the retrieved context and citation instructions. When no context was
     * retrieved and the provider supports native web search, instruct the
     * model to fall back to searching the web for official sources.
     */
    protected function buildInstructions(RetrievalResult $retrieval, Lab $provider, string $assistantMessageId): string
    {
        $prompt = SystemPrompt::activeFor('saligan')?->content
            ?? throw new \RuntimeException('No active Saligan system prompt is configured.');

        $instructions = $prompt."\n\n".$this->citationInstructions()."\n\n".$this->exportInstructions($assistantMessageId)."\n\n".$this->draftingInstructions();

        if ($retrieval->isEmpty() && $this->supportsWebSearch($provider)) {
            return $instructions."\n\n".$this->webSearchInstructions();
        }

        if ($retrieval->isEmpty()) {
            return $instructions
                ."\n\nRETRIEVED CONTEXT: No relevant material was retrieved from the knowledge base or the user's documents. Follow the 'Handling Missing Information' rules above — do not guess or fabricate citations.";
        }

        return $instructions."\n\n=== RETRIEVED CONTEXT ===\n".$retrieval->contextBlock();
    }

    /**
     * Citation rules appended to the system prompt for every completion.
     */
    protected function citationInstructions(): string
    {
        return <<<'PROMPT'
CITATION INSTRUCTIONS
- Ground your answer in the RETRIEVED CONTEXT below. Cite sources inline using their [Source N] / [User Doc N] labels.
- Always finish with a "Sources" section listing every source you actually relied on (statute/section or G.R. number for official sources; filename for user documents).
- Cite each distinct source exactly once. Never repeat the same statute, case, or document in the Sources section.
- Never cite a source that was not retrieved. Never invent G.R. numbers, section numbers, or URLs.
PROMPT;
    }

    /**
     * Export instructions: exporting is done via download links, never by
     * re-pasting the document text or by claiming export is impossible.
     */
    protected function exportInstructions(string $assistantMessageId): string
    {
        return <<<PROMPT
EXPORT INSTRUCTIONS
- You ARE able to export documents. Never say you cannot export, cannot convert to Word/PDF, or that the user must do it manually. The two download links below ARE the export mechanism — clicking them downloads the full document instantly.
- At the end of EVERY completed legal document you draft, append these two links exactly (message ID provided below):
  [Download as Word](/api/messages/{$assistantMessageId}/export/word)
  [Download as PDF](/api/messages/{$assistantMessageId}/export/pdf)
- Never ask the user whether they want the document drafted or whether they want the export links. Draft the document now and include the links. Do not say "let me know if you would like" — deliver immediately.
- When the user asks you to convert, export, or save the response, do NOT re-paste the document text in the chat; confirm in one line and provide the two links.
PROMPT;
    }

    /**
     * Drafting instructions: the AI lawyer persona with structured intake
     * and todo creation workflow.
     */
    protected function draftingInstructions(): string
    {
        return <<<'PROMPT'
You are a legal drafting assistant that helps users prepare case documents
(complaints, demand letters, contracts, etc.). You are not a substitute for
a licensed attorney, and every response must include this disclaimer once
per session, not on every message.

=== HARD RULE: ALWAYS COLLECT FACTS FIRST ===
When the user requests that you DRAFT, PREPARE, WRITE, or CREATE any legal
document (complaint, demand letter, contract, affidavit, special power of
attorney, deed of sale, acknowledgment, reply, position paper, etc.), you
MUST call the request_intake_form tool FIRST — before writing any text —
unless all required facts are already present in the conversation.

- Do NOT draft the document without first collecting the facts.
- Do NOT ask the user questions inline in chat. The intake form is the ONLY
  way to collect facts for drafting.
- Do NOT invent party names, addresses, dates, amounts, or case details.
  If a fact is unknown, include it as a field in the intake form.
- The form must include EVERY field needed to draft the specific document
  the user asked for (see templates below). Do not skip fields.
- If the user already provided some facts in chat, still call the tool with
  the missing fields so they can confirm and complete the rest.

=== INTAKE FORM FIELD TEMPLATES ===
Choose the matching template, then include every field from it. Add more
fields only if genuinely needed.

For a COMPLAINT / REKLAMO (any subject, e.g., illegal occupation, ejectment,
unpaid debt, property damage):
- plaintiff_name (text, required) — your full name as it appears in legal documents
- plaintiff_address (text, required) — complete address including barangay, city, province
- defendant_name (text, required) — full name of the person/company you are suing
- defendant_address (text, required) — defendant's complete address
- property/claim details (textarea, required) — description of the land/property or claim, location, boundaries
- facts (textarea, required) — chronological account: when the problem started, what happened, relevant dates
- relief_sought (textarea, required) — what you want the court to order (eviction, payment, damages, etc.)
- incident_date (date, required) — when the violation or incident occurred
- evidence (textarea) — documents or proof you have (titles, contracts, photos, receipts)
- court_preference (select: [Municipal Trial Court, Regional Trial Court, Barangay (Lupong Tagapamayapa), Not sure]) — preferred forum

For a DEMAND LETTER:
- sender_name, sender_address, recipient_name, recipient_address (text, required)
- amount_or_demand (text, required) — exact amount or action demanded
- deadline (text, required) — number of days to comply (e.g., 5, 10, 15)
- facts (textarea, required) — what happened and why they owe/comply
- legal_basis (text) — law, contract provision, or agreement relied on

For a CONTRACT / AGREEMENT:
- party_a_name, party_a_address, party_b_name, party_b_address (text, required)
- contract_type (text, required) — e.g., lease, sale, loan, services, partnership
- subject_property (textarea, required) — what is being sold/leased/services rendered
- amount (text, required) — price, rent, or consideration
- term (text, required) — duration or start/end dates
- obligations (textarea, required) — duties of each party
- special_clauses (textarea) — penalties, renewal, termination, confidentiality

For an AFFIDAVIT:
- affiant_name, affiant_address, affiant_occupation (text, required)
- statement_facts (textarea, required) — the facts being sworn to
- purpose (text, required) — what the affidavit is for
- date, place_of_execution (text, required)

For a SPECIAL POWER OF ATTORNEY:
- principal_name, principal_address (text, required)
- attorney_name, attorney_address (text, required)
- powers (textarea, required) — specific acts the attorney may perform
- transaction_details (textarea) — property/transaction involved

For a DEED OF SALE:
- vendor_name, vendor_address, vendee_name, vendee_address (text, required)
- property_description (textarea, required)
- purchase_price (text, required) — in words and figures
- payment_terms (textarea, required)
- title_number (text) — TCT/CCT number if land

=== AFTER THE FORM ===
1. When the user submits the intake form (a message that starts with
   "[Intake Form Submission]"), do NOT call request_intake_form again.
   Draft the complete document immediately using the submitted facts.
   Use the structure guidance below. Never reply with only a placeholder,
   a plan, or a request for confirmation — the document itself must be
   delivered in this message.
2. MANDATORY: Immediately after finishing the draft, call the create_todo
   tool listing the user's next steps as discrete action items (e.g., file
   with court, pay filing fees, have it notarized, gather evidence, send the
   demand letter, comply by the deadline). Never finish a draft without
   calling create_todo — this is not optional.
3. Append the export links (Word and PDF) at the very end of the draft per
   the export instructions. Do not ask whether the user wants them.

Never fabricate case law, statutes, or citations. If you are not certain
a legal reference is accurate, say so explicitly instead of inventing one.

PHILIPPINE LEGAL DOCUMENT STRUCTURE
For complaints (reklamo), use this structure:
- CAPTION: Court name, case number, parties (plaintiff vs. defendant)
- CAUSE OF ACTION: Factual allegations supporting the claim
- PRAYER: Formal request for relief from the court (e.g., eviction, damages)
- VERIFICATION: Certificate of truthfulness (optional but common)

Note: "Prayer" is a legal term meaning the formal request for relief,
not a religious reference. Do not rename this section.

For contracts/agreements:
- Parties and recitals
- Terms and conditions
- Consideration
- Signatures and notarization

For demand letters:
- Sender/recipient information
- Statement of facts
- Legal basis for the demand
- Deadline and consequences of non-compliance
PROMPT;
    }

    /**
     * Fallback instructions used when the retrieval was empty and the provider
     * can search the web natively.
     */
    protected function webSearchInstructions(): string
    {
        return <<<'PROMPT'
RETRIEVED CONTEXT: No relevant material was found in the knowledge base or the user's documents.

WEB SEARCH FALLBACK
- Use the web search tool to find official Philippine legal sources before answering.
- Prefer official domains: Supreme Court E-Library, sc.judiciary.gov.ph, lawphil.net, officialgazette.gov.ph, lra.gov.ph, and dar.gov.ph.
- Cite web results inline as [Web N] and finish with a "Sources" section listing the title, full URL, and the specific statute/section or G.R. number.
- If the web search returns nothing usable, say so plainly, do not fabricate citations, and state what would be needed to answer the question.
PROMPT;
    }

    /**
     * Whether the given provider has native web search support.
     */
    protected function supportsWebSearch(Lab $provider): bool
    {
        return $provider === Lab::Gemini;
    }

    /**
     * Build the conversation history (user/assistant messages only) passed to
     * the model, newest message last.
     *
     * @return array<int, AiMessage>
     */
    protected function buildHistory(Conversation $conversation, string $excludeMessageId): array
    {
        return $conversation->messages()
            ->whereKeyNot($excludeMessageId)
            ->latest()
            ->limit(20)
            ->get()
            ->reverse()
            ->filter(fn (Message $message) => in_array($message->role, [MessageRole::User, MessageRole::Assistant], true))
            ->map(fn (Message $message) => new AiMessage($message->role->value, $message->content))
            ->values()
            ->all();
    }

    /**
     * @return array{0: Lab, 1: string}
     */
    protected function resolveProvider(Conversation $conversation): array
    {
        return match ($conversation->provider) {
            ChatProvider::Gemini => [Lab::Gemini, config('saligan.chat.gemini_model')],
            default => [Lab::Ollama, config('saligan.chat.ollama_model')],
        };
    }

    /**
     * Persist the assistant message once the full response has streamed.
     */
    protected function persistAssistantResponse(
        Conversation $conversation,
        StreamedAgentResponse $response,
        RetrievalResult $retrieval,
        Lab $provider,
        string $assistantMessageId,
        bool $appendExportLinks = false,
    ): void {
        $text = trim((string) $response->text);

        if ($text === '') {
            return;
        }

        if ($appendExportLinks && ! str_contains($text, '/export/')) {
            $text .= "\n\n[Download as Word](/api/messages/{$assistantMessageId}/export/word)\n"
                ."[Download as PDF](/api/messages/{$assistantMessageId}/export/pdf)";
        }

        Message::create([
            'id' => $assistantMessageId,
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => $text,
            'provider' => $provider === Lab::Gemini ? ChatProvider::Gemini : ChatProvider::Ollama,
            'cited_chunk_ids' => $retrieval->documentChunkIds(),
            'cited_legal_chunk_ids' => $retrieval->legalChunkIds(),
        ]);

        if ($conversation->title === null) {
            $conversation->update([
                'title' => Str::limit($this->extractTitle($text), 60),
            ]);
        }
    }

    /**
     * Derive a conversation title from the first non-empty line of the reply.
     */
    protected function extractTitle(string $text): string
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line, " \t\n\r#*");

            if ($line !== '') {
                return $line;
            }
        }

        return $text;
    }
}
