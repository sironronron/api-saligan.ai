<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Todo;
use App\Services\Chat\AdvisoryRecorder;
use App\Services\Chat\ChatService;
use App\Support\AdvisoryParser;
use App\Support\ChatStatus;
use App\Support\ChoicePrompt;
use App\Support\DraftingIntent;
use App\Support\PlanLimits;
use App\Support\WebCitationParser;
use Closure;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Octane\Contracts\Client as OctaneClient;
use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {
        //
    }

    /**
     * Answer a message in a conversation, streaming the response as SSE.
     */
    public function store(Request $request, Conversation $conversation): StreamedResponse
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'attachment_ids' => ['array', 'max:10'],
            'attachment_ids.*' => ['uuid'],
        ]);

        PlanLimits::consumeMessage($request->user());

        $message = $validated['message'];

        $attachmentIds = $this->ownedAttachmentIds($request, $validated['attachment_ids'] ?? []);

        $isDraftingRequest = DraftingIntent::matches($message);
        $isIntakeSubmission = DraftingIntent::isIntakeSubmission($message);

        $frames = $this->chatFrames($conversation, $message, $isDraftingRequest, $isIntakeSubmission, $attachmentIds);

        return response()->stream($this->streamEmitter($frames), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Keep only the attachment ids that belong to the requesting user, in the
     * order they were sent. Anything else is dropped rather than rejected: a
     * document deleted between upload and send should not fail the message.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    protected function ownedAttachmentIds(Request $request, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $owned = Document::query()
            ->whereIn('id', $ids)
            ->where('user_id', $request->user()->id)
            ->pluck('id')
            ->all();

        return array_values(array_intersect($ids, $owned));
    }

    /**
     * Emit the given SSE frames to the client.
     *
     * RoadRunner streams incrementally only when the StreamedResponse callback
     * returns a Generator (it ignores echo()/ob_flush()/flush()), so when the
     * request is served by Octane the callback yields each frame. Outside
     * Octane (tests, PHP-FPM) the same frames are echoed and flushed so the
     * response body is still produced.
     *
     * @param  Generator<int, string>  $frames
     * @return Closure(): Generator<int, string>|Closure(): void
     */
    protected function streamEmitter(Generator $frames): Closure
    {
        if (app()->bound(OctaneClient::class)) {
            return function () use ($frames): Generator {
                yield from $frames;
            };
        }

        return function () use ($frames): void {
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');

            foreach ($frames as $frame) {
                echo $frame;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        };
    }

    /**
     * Generate the SSE frames for a chat message.
     *
     * @param  array<int, string>  $attachmentIds
     * @return Generator<int, string>
     */
    protected function chatFrames(Conversation $conversation, string $message, bool $isDraftingRequest, bool $isIntakeSubmission, array $attachmentIds = []): Generator
    {
        // Status frames raised by the chat service (e.g. "checking sources")
        // cannot be yielded from inside its callback, so they are queued and
        // prepended to the next frame emitted from this generator.
        $pending = [];

        $emit = function (string $event, array $data) use (&$pending): string {
            $frame = implode('', $pending).$this->sseFrame($event, $data);
            $pending = [];

            return $frame;
        };

        $error = null;
        $completed = false;
        $intakeRequested = false;
        $choiceRequested = false;
        $todoRequested = false;
        $advisoriesRequested = false;
        $fillTemplateRequested = false;
        $textLength = 0;
        $lastText = '';
        $buffering = $isDraftingRequest && ! $isIntakeSubmission;
        $bufferedText = '';
        $draftStarted = false;

        // Text held back from an unbuffered turn while it could still turn out
        // to be the start of [[NEED_INFO]], plus the block once the marker
        // arrives. Buffered drafting turns hold the whole reply and need none
        // of this, but every other turn — a plain chat answer, and the intake
        // submission itself — streams live, and the model does sometimes write
        // the marker there. Without the gate the raw marker and its questions
        // land in the user's chat bubble as a dead end they cannot answer.
        $gateHeld = '';
        $needInfoText = '';

        // Emit-safe portion of a delta: everything except a trailing fragment
        // that could still grow into the marker. Returns '' once the marker
        // has been seen — from there the rest of the turn is the question
        // block, which becomes the intake form instead of chat text.
        $gate = function (string $delta) use (&$gateHeld, &$needInfoText): string {
            if ($needInfoText !== '') {
                $needInfoText .= $delta;

                return '';
            }

            $gateHeld .= $delta;

            $index = strpos($gateHeld, DraftingIntent::NEED_INFO_MARKER);

            if ($index !== false) {
                $needInfoText = substr($gateHeld, $index);
                $released = substr($gateHeld, 0, $index);
                $gateHeld = '';

                return $released;
            }

            $keep = 0;
            $marker = DraftingIntent::NEED_INFO_MARKER;

            for ($length = min(strlen($marker) - 1, strlen($gateHeld)); $length > 0; $length--) {
                if (substr($gateHeld, -$length) === substr($marker, 0, $length)) {
                    $keep = $length;

                    break;
                }
            }

            $released = substr($gateHeld, 0, strlen($gateHeld) - $keep);
            $gateHeld = $keep === 0 ? '' : substr($gateHeld, -$keep);

            return $released;
        };
        $webSeen = [];
        $webIndex = 0;

        // Web citations are numbered here, once, in first-seen order —
        // the same numbering the persisted web_citations metadata gets, so
        // the cards line up live and on reload. Both sources of citations
        // (the answering provider's own web search, and the delegated
        // web_search tool) are numbered by this one counter.
        //
        // @return array<int, array<string, mixed>> the card payloads to emit
        $collectCitations = function (array $citations) use (&$webSeen, &$webIndex): array {
            $cards = [];

            foreach ($citations as $citation) {
                $url = $citation['url'];

                if (isset($webSeen[$url])) {
                    $webSeen[$url]['snippet'] ??= $citation['snippet'] ?? null;

                    continue;
                }

                $webSeen[$url] = $citation;
                $webIndex++;

                $cards[] = WebCitationParser::source($citation, $webIndex);
            }

            return $cards;
        };
        // Derived once: it is the same for every frame of this turn.
        $topic = ChatStatus::topic($message);

        try {
            $stream = $this->chatService->stream(
                $conversation,
                $message,
                function (string $status, ?string $label = null) use (&$pending, $message, $topic): void {
                    $pending[] = $this->sseFrame('status', [
                        'status' => $status,
                        // Statuses fired by the tools carry only the status
                        // key, so the label is derived here.
                        'label' => $label ?? ChatStatus::label($status, $message),
                        // Sent on every frame so a client that joins late, or
                        // drops one, still knows what is being worked on.
                        'topic' => $topic,
                    ]);
                },
                $attachmentIds,
            );

            Log::info('Chat streaming started', [
                'conversation_id' => $conversation->id,
                'message_length' => strlen($message),
            ]);

            // "Composing" is deliberately NOT announced here. Retrieval runs
            // first and reports itself, so claiming to compose before a single
            // token exists put the steps in the wrong order — the user saw
            // "Composing your answer", then "Checking legal sources", then
            // "Composing" again. It is emitted on the first real delta below,
            // which is the moment it becomes true.
            $composingAnnounced = false;

            if ($buffering) {
                // The document must wait for the intake form, so the
                // client shows a "collecting facts" animation instead of
                // any premature text the model may produce.
                yield $emit('status', [
                    'status' => 'collecting_facts',
                    'label' => ChatStatus::label('collecting_facts', $message),
                    'topic' => $topic,
                ]);
            }

            foreach ($stream as $event) {
                // The delegated web search runs inside a tool call and emits no
                // provider events of its own, so its sources are drained from
                // the service on every event — the cards appear while the tool
                // is still feeding the model, not after the answer ends.
                foreach ($collectCitations($this->chatService->pullWebCitations()) as $card) {
                    yield $emit('citation', $card);
                }

                if ($event instanceof ErrorEvent) {
                    $error = $event->message;

                    continue;
                }

                if ($event instanceof StreamEnd) {
                    $completed = true;
                }

                // Web citations stream live, the instant the provider records
                // one, so the sources panel fills in before the answer ends.
                // Deduplicated by URL in first-seen order — the same numbering
                // the persisted web_citations metadata gets on reload.
                if ($event instanceof Citation || ($event instanceof ProviderToolEvent && $event->type === 'web_search_tool_result')) {
                    foreach ($collectCitations(WebCitationParser::fromEvent($event)) as $card) {
                        yield $emit('citation', $card);
                    }
                }

                if ($event instanceof TextDelta) {
                    // The first token is the earliest honest moment to say the
                    // answer is being written; before it, retrieval was still
                    // the truthful current step.
                    if (! $composingAnnounced) {
                        $composingAnnounced = true;

                        yield $emit('status', [
                            'status' => 'composing',
                            'label' => ChatStatus::label('composing', $message),
                            'topic' => $topic,
                        ]);
                    }

                    $textLength += strlen($event->delta);
                    $lastText .= $event->delta;

                    // The instant the model begins the actual document — the
                    // opening marker appears — report drafting as the current
                    // step. This is code-driven (the marker is real output),
                    // not the model narrating what it is about to do.
                    if (! $draftStarted && str_contains($lastText, '[[DOCUMENT_START]]')) {
                        $draftStarted = true;
                        // Once drafting begins, stop buffering and stream
                        // the document character-by-character for smoothness.
                        $buffering = false;

                        // Release any buffered text that preceded the marker
                        // (e.g. the marker itself and any preamble). A model
                        // that asked for facts and then drafted anyway has
                        // answered its own question — the document stands, so
                        // the question block is dropped rather than shown.
                        if ($bufferedText !== '') {
                            yield $emit('delta', ['delta' => DraftingIntent::stripNeedsInfoBlock($bufferedText)]);
                            $bufferedText = '';
                        }

                        yield $emit('status', [
                            'status' => 'drafting_document',
                            'label' => ChatStatus::label('drafting_document', $message),
                        ]);
                    }

                    if ($buffering) {
                        $bufferedText .= $event->delta;
                    } else {
                        $released = $gate($event->delta);

                        if ($released !== '') {
                            yield $emit('delta', ['delta' => $released]);
                        }
                    }
                }

                if ($event instanceof ToolCall) {
                    if ($event->toolCall->name === 'request_intake_form') {
                        // One form per turn, and never on the turn that
                        // answers one. A second frame re-opens the sheet on
                        // top of the user's answers and drops the draft the
                        // client had already started rendering — the "form
                        // appeared twice" bug. The tool still runs server-side
                        // and tells the model to draft instead.
                        if ($intakeRequested || $isIntakeSubmission) {
                            Log::info('Suppressed a repeat request_intake_form call', [
                                'conversation_id' => $conversation->id,
                                'intake_submission' => $isIntakeSubmission,
                            ]);

                            continue;
                        }

                        $documentType = $event->toolCall->arguments['document_type'] ?? null;

                        // The case context covers everything this document
                        // needs, so the tool has already answered the model
                        // with "INTAKE FORM SUPPRESSED: draft now". Showing a
                        // form here anyway would leave the user filling in
                        // facts for a draft the model is writing without them.
                        if ($this->chatService->intakeSuppressedFor($conversation, $message, $documentType)) {
                            continue;
                        }

                        // The form is shaped server-side so it matches the
                        // selected template, but the fields the model asked
                        // for are carried in rather than discarded: it read
                        // the conversation and knows what is missing. Fields
                        // the case context already covers are dropped here
                        // (see intakeFieldsFor).
                        $fields = $this->chatService->intakeFieldsFor(
                            $conversation,
                            $message,
                            $documentType,
                            $event->toolCall->arguments['fields'] ?? null,
                        );

                        // Nothing left to ask: the case context covers every
                        // field this document needs, so the form would open
                        // empty. The model must draft directly from the case
                        // context instead of interrupting the user. The stream
                        // continues and the tool executes server-side with a
                        // directive to that effect; the premature-draft
                        // fallback below still catches drafts that genuinely
                        // turned out to need more facts.
                        //
                        // Note this is field-level, not case-level: a case
                        // that supplies only the narrative still shows the
                        // form for the party names, addresses, and amounts a
                        // description never carries.
                        if ($fields === []) {
                            continue;
                        }

                        $intakeRequested = true;

                        // The stream is cut off right after the tool call,
                        // before the tool's handle() ever runs, so the status
                        // is reported here — the model just chose the intake
                        // step, which is the real signal.
                        yield $emit('status', [
                            'status' => 'gathering_facts',
                            'label' => ChatStatus::label('gathering_facts', $message),
                        ]);

                        // Previously submitted intake values are carried over
                        // so a repeated drafting request reuses the user's
                        // original answers.
                        yield $emit('tool_call', [
                            'name' => 'request_intake_form',
                            'arguments' => [
                                'document_type' => $documentType,
                                'fields' => $fields,
                                'default_values' => $this->chatService->recentIntakeValues($conversation),
                            ],
                        ]);

                        // The facts have not been collected yet, so the
                        // draft must wait until the intake form is
                        // submitted. Breaking out of the stream keeps any
                        // premature text from reaching the client and from
                        // being persisted as an assistant message.
                        break;
                    }

                    if ($event->toolCall->name === 'ask_user_question') {
                        // The model's arguments are never rendered as they
                        // arrive: a one-option question is not a choice, and a
                        // model-authored "Other" would collide with the escape
                        // the UI already offers.
                        $questions = ChoicePrompt::normalize($event->toolCall->arguments['questions'] ?? null);

                        // Nothing answerable survived normalization. Cutting the
                        // stream here would strand the user on a question they
                        // were never shown, so the turn simply carries on and
                        // the model's own text stands as the answer.
                        if ($questions === []) {
                            Log::info('Discarded an unanswerable ask_user_question call', [
                                'conversation_id' => $conversation->id,
                            ]);

                            continue;
                        }

                        $choiceRequested = true;

                        yield $emit('status', [
                            'status' => 'awaiting_choice',
                            'label' => ChatStatus::label('awaiting_choice', $message),
                        ]);

                        yield $emit('tool_call', [
                            'name' => 'ask_user_question',
                            'arguments' => ['questions' => $questions],
                        ]);

                        // The rest of the turn depends on an answer nobody has
                        // given yet. Breaking keeps whatever the model wrote on
                        // the far side of the question — a draft that assumes a
                        // choice — off the wire and out of the conversation.
                        break;
                    }

                    yield $emit('tool_call', [
                        'name' => $event->toolCall->name,
                        'arguments' => $event->toolCall->arguments,
                    ]);

                    if ($event->toolCall->name === 'create_todo') {
                        $todoRequested = true;
                    }

                    if ($event->toolCall->name === 'flag_advisories') {
                        $advisoriesRequested = true;
                    }

                    if ($event->toolCall->name === 'fill_template_fields') {
                        $fillTemplateRequested = true;
                    }
                }

                if ($event instanceof ToolResult) {
                    yield $emit('tool_result', [
                        'name' => $event->toolResult->name,
                        'result' => $event->toolResult->result,
                    ]);

                    if ($event->toolResult->name === 'create_todo') {
                        $todoRequested = true;
                    }

                    if ($event->toolResult->name === 'flag_advisories') {
                        $advisoriesRequested = true;
                    }
                }
            }

            // A search that ran on the last events of the stream leaves sources
            // recorded but never drained; without this they would be persisted
            // on the message and appear only on reload.
            foreach ($collectCitations($this->chatService->pullWebCitations()) as $card) {
                yield $emit('citation', $card);
            }
        } catch (StreamStoppedException $exception) {
            // The client went away mid-stream (navigated off the page, hit
            // stop, or a proxy closed the connection). Nothing is wrong with
            // the provider or this worker, and there is no socket left to
            // write to — emitting another frame would only throw again and
            // log a second, misleading "finalization failed".
            Log::info('Chat stream cancelled by client', [
                'conversation_id' => $conversation->id,
            ]);

            $this->chatService->discardCurrentUserMessage();

            return;
        } catch (Throwable $exception) {
            Log::error('Chat streaming failed', [
                'conversation_id' => $conversation->id,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            // Roll back the user message persisted before streaming so a
            // client retry does not duplicate it in the conversation.
            $this->chatService->discardCurrentUserMessage();

            $error = 'The AI provider could not complete the response. Please try again.';
        }

        try {
            if ($error !== null) {
                yield $emit('error', ['message' => $error]);
            } else {
                // Whatever the gate was still holding back never became the
                // marker, so it is ordinary reply text and is released now.
                if ($needInfoText === '' && $gateHeld !== '') {
                    yield $emit('delta', ['delta' => $gateHeld]);
                    $gateHeld = '';
                }

                // The model asked for the missing facts in chat using the
                // [[NEED_INFO]] marker contract. Those questions become the
                // intake form — the user answers exactly what the model asked
                // for — so the reply is not delivered as a dead-end
                // clarification. The question text itself never reaches the
                // chat: on a buffered drafting turn the whole reply is held
                // back, and on any other turn the gate withheld everything
                // from the marker onward.
                $questionSource = $buffering ? $bufferedText : $needInfoText;

                if (! $intakeRequested && ! $choiceRequested && DraftingIntent::needsInfo($questionSource)) {
                    // A buffered turn wrote nothing to the client, so its
                    // persisted reply is pure question text and goes away
                    // entirely. A gated turn already delivered the lead-in
                    // that preceded the marker, so that message stays — the
                    // service strips the block from it before persisting.
                    if ($buffering && $bufferedText !== '') {
                        $this->chatService->discardLastAssistantMessage();
                    }

                    $asked = DraftingIntent::intakeFieldsFromNeedsInfo($questionSource);

                    // dropCaseCoveredFields is a no-op when the case
                    // supplies nothing, so it is safe to call either way.
                    // If dropping empties the form, the model asked ONLY
                    // for facts the case is meant to cover — its explicit
                    // ask wins over the heuristic, since an empty form is
                    // a dead end the user cannot answer.
                    $fields = $this->chatService->dropCaseCoveredFields($conversation, $asked);

                    if ($fields === []) {
                        $fields = $asked;
                    }

                    if ($fields !== []) {
                        $intakeRequested = true;

                        yield $emit('tool_call', [
                            'name' => 'request_intake_form',
                            'arguments' => [
                                'document_type' => DraftingIntent::documentTypeFor($message),
                                'fields' => $fields,
                                'default_values' => $this->chatService->recentIntakeValues($conversation),
                            ],
                        ]);
                    }
                } elseif ($buffering && ! $intakeRequested && ! $choiceRequested && $bufferedText !== '') {
                    // Any other buffered reply is the model's answer — a
                    // legitimate direct draft (markers waived when the
                    // case supplies the facts) or a plain chat reply.
                    yield $emit('delta', ['delta' => $bufferedText]);
                }

                // A turn cut short by a question has no finished document, so
                // its partial text must not be mined for next steps or caveats.
                if (! $todoRequested && ! $choiceRequested && $textLength > 0
                    && ($isIntakeSubmission || DraftingIntent::hasTodoBlock($lastText))) {
                    // The model skipped the mandatory todo step. Persist the
                    // next steps extracted from the draft so tasks still appear.
                    // A reply that wrote out a checklist (TODO markers or a
                    // next-steps heading) without calling create_todo gets the
                    // same treatment, whatever turn it came from.
                    try {
                        $created = [];

                        foreach (DraftingIntent::fallbackTodos($lastText) as $item) {
                            $todo = Todo::create([
                                'conversation_id' => $conversation->id,
                                'title' => $item['title'],
                                'status' => $item['status'],
                            ]);

                            $created[] = [
                                'id' => $todo->id,
                                'title' => $todo->title,
                                'status' => $todo->status,
                                'priority' => $todo->priority,
                                'due_hint' => $todo->due_hint,
                            ];
                        }

                        if ($created !== []) {
                            yield $emit('tool_result', [
                                'name' => 'create_todo',
                                'result' => json_encode(['items' => $created], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]);
                        }
                    } catch (Throwable $exception) {
                        Log::warning('Fallback todo creation failed', [
                            'conversation_id' => $conversation->id,
                            'exception' => $exception->getMessage(),
                        ]);
                    }
                }

                if (! $advisoriesRequested && ! $choiceRequested && $textLength > 0 && AdvisoryParser::hasSection($lastText)) {
                    // The model wrote its caveats as prose instead of calling
                    // flag_advisories. Skipping a tool call is ordinary model
                    // behaviour — create_todo has carried the same fallback
                    // since before advisories existed — and these are the part
                    // of the answer the user most needs to see, so they are
                    // recovered rather than lost. The recorder applies the same
                    // boilerplate and duplicate guards as the tool path.
                    try {
                        $created = app(AdvisoryRecorder::class)->record(
                            $conversation->id,
                            AdvisoryParser::fromReply($lastText),
                        );

                        if ($created !== []) {
                            Log::info('Recovered advisories from reply text', [
                                'conversation_id' => $conversation->id,
                                'count' => count($created),
                            ]);

                            yield $emit('tool_result', [
                                'name' => 'flag_advisories',
                                'result' => json_encode(['items' => $created], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]);
                        }
                    } catch (Throwable $exception) {
                        Log::warning('Fallback advisory creation failed', [
                            'conversation_id' => $conversation->id,
                            'exception' => $exception->getMessage(),
                        ]);
                    }
                }

                yield $emit('done', ['ok' => $completed]);
            }
        } catch (StreamStoppedException) {
            // Same as above: the client is gone, so there is nothing to report
            // and nothing to report it on.
            Log::info('Chat stream cancelled by client during finalization', [
                'conversation_id' => $conversation->id,
            ]);
        } catch (Throwable $exception) {
            // A failure in the finalization phase must never surface as a bare
            // 500 (which drops the SSE connection and the CORS headers), so it
            // is reported through the stream instead.
            Log::error('Chat stream finalization failed', [
                'conversation_id' => $conversation->id,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            yield $emit('error', ['message' => 'The response could not be finalized. Please try again.']);
        }
    }

    /**
     * Build a single SSE frame string.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sseFrame(string $event, array $data): string
    {
        return "event: {$event}\n"
            .'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }
}
