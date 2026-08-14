<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Todo;
use App\Services\Chat\ChatService;
use App\Support\ChatStatus;
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
        abort_unless($conversation->user_id === $request->user()->id, 403);

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
        $todoRequested = false;
        $fillTemplateRequested = false;
        $textLength = 0;
        $lastText = '';
        $buffering = $isDraftingRequest && ! $isIntakeSubmission;
        $bufferedText = '';
        $draftStarted = false;
        $webSeen = [];
        $webIndex = 0;
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
                    foreach (WebCitationParser::fromEvent($event) as $citation) {
                        $url = $citation['url'];

                        if (isset($webSeen[$url])) {
                            $webSeen[$url]['snippet'] ??= $citation['snippet'] ?? null;

                            continue;
                        }

                        $webSeen[$url] = $citation;
                        $webIndex++;

                        yield $emit('citation', WebCitationParser::source($citation, $webIndex));
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
                        // (e.g. the marker itself and any preamble).
                        if ($bufferedText !== '') {
                            yield $emit('delta', ['delta' => $bufferedText]);
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
                        yield $emit('delta', ['delta' => $event->delta]);
                    }
                }

                if ($event instanceof ToolCall) {
                    if ($event->toolCall->name === 'request_intake_form') {
                        $documentType = $event->toolCall->arguments['document_type'] ?? null;

                        // The form fields are authoritative server-side so
                        // they match the selected template or the document
                        // category instead of whatever fields the model
                        // happened to invent. Fields the case context already
                        // covers are dropped here (see intakeFieldsFor).
                        $fields = $this->chatService->intakeFieldsFor(
                            $conversation,
                            $message,
                            $documentType,
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
                        if (! $isIntakeSubmission && $fields === []) {
                            continue;
                        }

                        $intakeRequested = true;

                        // Previously submitted intake values are carried over
                        // so a repeated drafting request reuses the user's
                        // original answers.
                        if (! $isIntakeSubmission) {
                            // The stream is cut off right after the tool
                            // call, before the tool's handle() ever runs, so
                            // the status is reported here — the model just
                            // chose the intake step, which is the real signal.
                            yield $emit('status', [
                                'status' => 'gathering_facts',
                                'label' => ChatStatus::label('gathering_facts', $message),
                            ]);
                        }

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
                        if (! $isIntakeSubmission) {
                            break;
                        }

                        continue;
                    }

                    yield $emit('tool_call', [
                        'name' => $event->toolCall->name,
                        'arguments' => $event->toolCall->arguments,
                    ]);

                    if ($event->toolCall->name === 'create_todo') {
                        $todoRequested = true;
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
                }
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
                if ($buffering && ! $intakeRequested) {
                    // The model asked for the missing facts in chat using the
                    // [[NEED_INFO]] marker contract. Those questions become
                    // the intake form — the user answers exactly what the
                    // model asked for — so the reply is not delivered as a
                    // dead-end clarification. Fields the case context already
                    // covers are dropped, and the model's question text is
                    // discarded rather than persisted as an assistant message.
                    if (DraftingIntent::needsInfo($bufferedText)) {
                        if ($bufferedText !== '') {
                            $this->chatService->discardLastAssistantMessage();
                        }

                        $asked = DraftingIntent::intakeFieldsFromNeedsInfo($bufferedText);

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

                        yield $emit('tool_call', [
                            'name' => 'request_intake_form',
                            'arguments' => [
                                'document_type' => DraftingIntent::documentTypeFor($message),
                                'fields' => $fields,
                                'default_values' => $this->chatService->recentIntakeValues($conversation),
                            ],
                        ]);
                    } elseif ($bufferedText !== '') {
                        // Any other buffered reply is the model's answer — a
                        // legitimate direct draft (markers waived when the
                        // case supplies the facts) or a plain chat reply.
                        yield $emit('delta', ['delta' => $bufferedText]);
                    }
                }

                if (! $todoRequested && $textLength > 0
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
