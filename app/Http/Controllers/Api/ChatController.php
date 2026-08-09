<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Todo;
use App\Services\Chat\ChatService;
use App\Support\ChatStatus;
use App\Support\DraftingIntent;
use App\Support\PlanLimits;
use Closure;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Octane\Contracts\Client as OctaneClient;
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
        ]);

        PlanLimits::consumeMessage($request->user());

        $message = $validated['message'];

        $isDraftingRequest = DraftingIntent::matches($message);
        $isIntakeSubmission = DraftingIntent::isIntakeSubmission($message);

        $frames = $this->chatFrames($conversation, $message, $isDraftingRequest, $isIntakeSubmission);

        return response()->stream($this->streamEmitter($frames), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
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
     * @return Generator<int, string>
     */
    protected function chatFrames(Conversation $conversation, string $message, bool $isDraftingRequest, bool $isIntakeSubmission): Generator
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
        $textLength = 0;
        $lastText = '';
        $buffering = $isDraftingRequest && ! $isIntakeSubmission;
        $bufferedText = '';
        $draftStarted = false;

        try {
            $stream = $this->chatService->stream(
                $conversation,
                $message,
                function (string $status, ?string $label = null) use (&$pending, $message): void {
                    $pending[] = $this->sseFrame('status', [
                        'status' => $status,
                        // Statuses fired by the tools carry only the status
                        // key, so the label is derived from the user's message
                        // here — that keeps every status personalized.
                        'label' => $label ?? ChatStatus::label($status, $message),
                    ]);
                },
            );

            Log::info('Chat streaming started', [
                'conversation_id' => $conversation->id,
                'message_length' => strlen($message),
            ]);

            yield $emit('status', [
                'status' => 'composing',
                'label' => ChatStatus::label('composing', $message),
            ]);

            if ($buffering) {
                // The document must wait for the intake form, so the
                // client shows a "collecting facts" animation instead of
                // any premature text the model may produce.
                yield $emit('status', ['status' => 'collecting_facts']);
            }

            foreach ($stream as $event) {
                if ($event instanceof ErrorEvent) {
                    $error = $event->message;

                    continue;
                }

                if ($event instanceof StreamEnd) {
                    $completed = true;
                }

                if ($event instanceof TextDelta) {
                    $textLength += strlen($event->delta);
                    $lastText .= $event->delta;

                    // The instant the model begins the actual document — the
                    // opening marker appears — report drafting as the current
                    // step. This is code-driven (the marker is real output),
                    // not the model narrating what it is about to do.
                    if (! $draftStarted && str_contains($lastText, '[[DOCUMENT_START]]')) {
                        $draftStarted = true;

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
                        $intakeRequested = true;

                        // The form fields are authoritative server-side so
                        // they match the selected template or the document
                        // category instead of whatever fields the model
                        // happened to invent. Previously submitted intake
                        // values are carried over so a repeated drafting
                        // request reuses the user's original answers.
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
                                'document_type' => $event->toolCall->arguments['document_type'] ?? null,
                                'fields' => $this->chatService->intakeFieldsFor(
                                    $conversation,
                                    $message,
                                    $event->toolCall->arguments['document_type'] ?? null,
                                ),
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
                    // The model drafts directly with the facts it already
                    // has. The intake form is only triggered when the draft
                    // shows the model still needs facts (bracketed
                    // placeholders, an empty reply, or no document markers);
                    // such a premature draft is discarded and re-collected
                    // through the intake form instead.
                    $premature = $bufferedText === ''
                        || DraftingIntent::containsBrackets($bufferedText)
                        || ! DraftingIntent::isCompleteDocument($bufferedText);

                    if ($premature) {
                        if ($bufferedText !== '') {
                            $this->chatService->discardLastAssistantMessage();
                        }

                        $documentType = DraftingIntent::documentTypeFor($message);

                        $fields = DraftingIntent::mergeIntakeFields(
                            $this->chatService->intakeFieldsFor($conversation, $message, $documentType),
                            DraftingIntent::extractBracketFields($bufferedText),
                        );

                        yield $emit('tool_call', [
                            'name' => 'request_intake_form',
                            'arguments' => [
                                'document_type' => $documentType,
                                'fields' => $fields,
                                'default_values' => $this->chatService->recentIntakeValues($conversation),
                            ],
                        ]);
                    } elseif ($bufferedText !== '') {
                        // The draft is complete and free of placeholders, so
                        // the buffered document is a legitimate direct draft.
                        yield $emit('delta', ['delta' => $bufferedText]);
                    }
                }

                if (! $todoRequested && $textLength > 0 && $isIntakeSubmission) {
                    // The model skipped the mandatory todo step. Persist the
                    // next steps extracted from the draft so tasks still appear.
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
            .'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
    }
}
