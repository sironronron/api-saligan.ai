<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Todo;
use App\Services\Chat\ChatService;
use App\Support\ChatStatus;
use App\Support\DraftingIntent;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
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

        return response()->stream(function () use ($conversation, $message, $isDraftingRequest, $isIntakeSubmission): void {
            // Disable output buffering so SSE frames reach the client immediately.
            // Buffers opened by the caller (e.g. the test runner) are left
            // intact; per-frame ob_flush()/flush() push our frames through.
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');

            $error = null;
            $completed = false;
            $intakeRequested = false;
            $todoRequested = false;
            $textLength = 0;
            $lastText = '';
            $buffering = $isDraftingRequest && ! $isIntakeSubmission;
            $bufferedText = '';

            try {
                $stream = $this->chatService->stream(
                    $conversation,
                    $message,
                    fn (string $status, ?string $label = null) => $this->sse('status', [
                        'status' => $status,
                        'label' => $label,
                    ]),
                );

                Log::info('Chat streaming started', [
                    'conversation_id' => $conversation->id,
                    'message_length' => strlen($message),
                    'message' => $message,
                ]);

                $this->sse('status', [
                    'status' => 'composing',
                    'label' => ChatStatus::label('composing', $message),
                ]);

                if ($buffering) {
                    // The document must wait for the intake form, so the
                    // client shows a "collecting facts" animation instead of
                    // any premature text the model may produce.
                    $this->sse('status', ['status' => 'collecting_facts']);
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

                        if ($buffering) {
                            $bufferedText .= $event->delta;
                        } else {
                            $this->sse('delta', ['delta' => $event->delta]);
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
                            $this->sse('tool_call', [
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

                        $this->sse('tool_call', [
                            'name' => $event->toolCall->name,
                            'arguments' => $event->toolCall->arguments,
                        ]);

                        if ($event->toolCall->name === 'create_todo') {
                            $todoRequested = true;
                        }
                    }

                    if ($event instanceof ToolResult) {
                        $this->sse('tool_result', [
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

            if ($error !== null) {
                $this->sse('error', ['message' => $error]);
            } else {
                if ($buffering && ! $intakeRequested) {
                    // The model skipped the mandatory intake step. Only a
                    // complete, fact-complete draft is a real document; a
                    // premature draft with bracketed placeholders (or no
                    // document at all) is discarded and re-collected through
                    // the intake form instead.
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

                        $this->sse('tool_call', [
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
                        $this->sse('delta', ['delta' => $bufferedText]);
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
                            $this->sse('tool_result', [
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

                $this->sse('done', ['ok' => $completed]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Write a single SSE frame and flush it to the client.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
