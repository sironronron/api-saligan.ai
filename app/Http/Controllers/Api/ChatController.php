<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Todo;
use App\Services\Chat\ChatService;
use App\Support\DraftingIntent;
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

            try {
                $stream = $this->chatService->stream(
                    $conversation,
                    $message,
                    fn (string $status) => $this->sse('status', ['status' => $status]),
                );

                $this->sse('status', ['status' => 'composing']);

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

                        $this->sse('delta', ['delta' => $event->delta]);
                    }

                    if ($event instanceof ToolCall) {
                        $this->sse('tool_call', [
                            'name' => $event->toolCall->name,
                            'arguments' => $event->toolCall->arguments,
                        ]);

                        if ($event->toolCall->name === 'request_intake_form') {
                            $intakeRequested = true;

                            // Stop the stream so the user can fill in the
                            // intake form before the draft continues.
                            break;
                        }

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

                $error = 'The AI provider could not complete the response. Please try again.';
            }

            if ($error !== null) {
                $this->sse('error', ['message' => $error]);
            } else {
                if (! $intakeRequested && ! $isIntakeSubmission && $isDraftingRequest) {
                    // The model skipped the mandatory intake step. Emit a
                    // synthetic tool call so the form still appears.
                    $this->sse('tool_call', [
                        'name' => 'request_intake_form',
                        'arguments' => ['fields' => DraftingIntent::defaultFields()],
                    ]);
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
