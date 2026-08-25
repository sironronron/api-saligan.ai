<?php

namespace App\Http\Controllers;

use App\Ai\Tools\CreateTodoTool;
use App\Ai\Tools\FlagAdvisoriesTool;
use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\MatterMemory;
use App\Models\Message;
use App\Services\Ai\PythonConversationContext;
use App\Services\MatterMemory\MatterMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request as ToolRequest;

class InternalAiController extends Controller
{
    public function __construct(
        private readonly PythonConversationContext $context,
        private readonly MatterMemoryService $memory,
    ) {}

    public function context(Conversation $conversation): JsonResponse
    {
        return response()->json($this->context->for($conversation));
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'message_id' => ['required', 'uuid'],
            'provider' => ['nullable', Rule::enum(ChatProvider::class)],
            'user' => ['required', 'array'],
            'user.content' => ['required', 'string', 'max:8000'],
            'user.attachment_ids' => ['sometimes', 'array', 'max:10'],
            'user.attachment_ids.*' => ['uuid'],
            'assistant' => ['required', 'array'],
            'assistant.content' => ['present', 'string'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $result = DB::transaction(function () use ($conversation, $validated): array {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            $existing = Message::query()->find($validated['message_id']);

            if ($existing !== null) {
                return [
                    'ok' => true,
                    'message_id' => $existing->id,
                    'idempotent' => true,
                ];
            }

            $attachmentIds = array_values($validated['user']['attachment_ids'] ?? []);
            $userMessage = Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $validated['user']['content'],
                'metadata' => $attachmentIds === [] ? null : ['attachment_ids' => $attachmentIds],
            ]);

            $metadata = $validated['metadata'] ?? [];
            $assistantMessage = Message::create([
                'id' => $validated['message_id'],
                'conversation_id' => $conversation->id,
                'role' => MessageRole::Assistant,
                'content' => trim($validated['assistant']['content']),
                'provider' => ChatProvider::tryFrom($validated['provider'] ?? '') ?? ChatProvider::Ollama,
                'cited_chunk_ids' => $metadata['document_chunk_ids'] ?? [],
                'cited_legal_chunk_ids' => $metadata['legal_chunk_ids'] ?? [],
                'cited_standard_chunk_ids' => $metadata['standard_chunk_ids'] ?? [],
                'metadata' => collect($metadata)
                    ->except(['document_chunk_ids', 'legal_chunk_ids', 'standard_chunk_ids'])
                    ->all(),
            ]);

            Advisory::query()
                ->where('conversation_id', $conversation->id)
                ->whereNull('message_id')
                ->update(['message_id' => $assistantMessage->id]);

            if ($conversation->title === null) {
                $title = collect(preg_split('/\R/', $assistantMessage->content) ?: [])
                    ->map(fn (string $line): string => trim($line, " \t\n\r#*"))
                    ->first(fn (string $line): bool => $line !== '');

                $conversation->update([
                    'title' => Str::limit($title ?: $validated['user']['content'], 60),
                ]);
            }

            return [
                'ok' => true,
                'user_message_id' => $userMessage->id,
                'message_id' => $assistantMessage->id,
                'idempotent' => false,
            ];
        });

        return response()->json($result);
    }

    public function todos(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:25'],
            'items.*' => ['array'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->toolResult(
            $conversation,
            'todos',
            $validated['tool_call_id'] ?? null,
            fn (): string => (new CreateTodoTool($conversation->id))->handle(
                new ToolRequest(['items' => $validated['items']], $validated['tool_call_id'] ?? null),
            ),
        ));
    }

    public function advisories(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:12'],
            'items.*' => ['array'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->toolResult(
            $conversation,
            'advisories',
            $validated['tool_call_id'] ?? null,
            fn (): string => (new FlagAdvisoriesTool($conversation->id))->handle(
                new ToolRequest(['items' => $validated['items']], $validated['tool_call_id'] ?? null),
            ),
        ));
    }

    public function letters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required_without:template_fields', 'array'],
            'content.type' => ['required_with:content', 'in:doc'],
            'content.content' => ['required_with:content', 'array'],
            'template_fields' => ['required_without:content', 'array'],
            'template_fields.*' => ['string', 'max:10000'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(array_filter([
            'ok' => true,
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'template_fields' => $validated['template_fields'] ?? null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function memory(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'facts' => ['required', 'array', 'max:50'],
            'facts.*' => ['required', 'string', 'max:5000'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        $case = $conversation->case;
        $user = $conversation->user;

        if ($case === null || $user === null || ! $this->memory->canWrite($case)) {
            return response()->json(['ok' => true, 'accepted' => 0, 'items' => []]);
        }

        $created = [];

        foreach ($validated['facts'] as $fact) {
            [$type, $content] = $this->memoryFact($fact, $case->id);

            if ($content === '' || ! in_array($type, MatterMemory::TYPES, true)) {
                continue;
            }

            if ($this->memory->existsSimilar($case, $type, $content)) {
                continue;
            }

            $entry = $this->memory->store($case, $user, $type, $content);
            $created[] = ['id' => $entry->id, 'type' => $entry->type, 'content' => $entry->content];
        }

        return response()->json([
            'ok' => true,
            'accepted' => count($created),
            'items' => $created,
        ]);
    }

    /**
     * @param  callable(): string  $callback
     * @return array<string, mixed>
     */
    protected function toolResult(
        Conversation $conversation,
        string $operation,
        ?string $toolCallId,
        callable $callback,
    ): array {
        $execute = function () use ($callback): array {
            $decoded = json_decode($callback(), true);

            return is_array($decoded) ? $decoded : ['accepted' => 0];
        };

        if (blank($toolCallId)) {
            return $execute();
        }

        $key = 'ai-callback:'.$operation.':'.$conversation->id.':'.sha1($toolCallId);

        return Cache::remember($key, now()->addDay(), $execute);
    }

    /** @return array{0: string, 1: string} */
    protected function memoryFact(string $fact, string $caseId): array
    {
        $fact = trim($fact);

        if (preg_match('/^(?:matter=(\S+)\s+)?type=(\S+)\s+content:\s*(.+)$/s', $fact, $matches) === 1) {
            if (($matches[1] ?? '') !== '' && $matches[1] !== $caseId) {
                return ['', ''];
            }

            return [$matches[2], trim($matches[3])];
        }

        if (preg_match('/^\[([a-z_]+)\]\s*(.+)$/s', $fact, $matches) === 1) {
            return [$matches[1], trim($matches[2])];
        }

        return ['fact', $fact];
    }
}
