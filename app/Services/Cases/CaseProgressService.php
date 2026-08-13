<?php

namespace App\Services\Cases;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\MatterMemory;
use App\Models\Message;
use App\Models\Todo;
use App\Services\MatterMemory\MatterMemoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Assembles the whole-of-case progress view: where the matter stands, what has
 * been produced for it, and everything that has happened to it in order.
 */
class CaseProgressService
{
    /**
     * How many recent chat messages are folded into the timeline. The full
     * transcript lives in the thread view — the progress feed only needs enough
     * to show what the matter has been working on lately.
     */
    protected const TIMELINE_MESSAGE_LIMIT = 30;

    /**
     * The merged timeline is capped so a long-running matter cannot return an
     * unbounded payload; `timeline_truncated` tells the client it was cut.
     */
    protected const TIMELINE_LIMIT = 120;

    /**
     * The stages a matter moves through, in order. `on_hold` is not a stage of
     * its own — a held matter is still mid-work, so it sits on the same step
     * and is surfaced through the `on_hold` flag instead.
     */
    protected const STAGES = [
        ['key' => 'open', 'label' => 'Opened'],
        ['key' => 'in_progress', 'label' => 'In Progress'],
        ['key' => 'closed', 'label' => 'Closed'],
    ];

    public function __construct(protected MatterMemoryService $memories) {}

    /**
     * Build the progress payload for a case.
     *
     * @return array<string, mixed>
     */
    public function build(LegalCase $case): array
    {
        $conversations = $case->conversations()
            ->withCount('messages')
            ->withMin('messages', 'created_at')
            ->withMax('messages', 'created_at')
            ->get();

        $tasks = $case->tasks()->get();
        $documents = $case->documents()->orderBy('created_at')->get();
        $generated = $case->generatedDocuments()->orderBy('messages.created_at')->get();
        $memories = $this->memories->getMemories($case);

        $userMessages = $case->messages()->where('messages.role', MessageRole::User)->count();
        $assistantMessages = $case->messages()->where('messages.role', MessageRole::Assistant)->count();

        $recentMessages = $case->messages()
            ->orderByDesc('messages.created_at')
            ->limit(self::TIMELINE_MESSAGE_LIMIT)
            ->get();

        $timeline = $this->timeline($case, $conversations, $tasks, $documents, $generated, $memories, $recentMessages);

        return [
            'case' => $this->caseSummary($case),
            'stages' => $this->stages($case),
            'progress' => $this->progress($case, $tasks),
            'deadline' => $this->deadline($case),
            'stats' => $this->stats($case, $conversations, $tasks, $documents, $generated, $memories, $userMessages, $assistantMessages),
            'threads' => $this->threads($conversations, $tasks),
            'tasks' => $this->tasks($tasks, $conversations),
            'documents' => $this->documents($documents),
            'generated_documents' => $this->generatedDocuments($generated, $conversations),
            'key_facts' => $this->keyFacts($memories),
            'timeline' => array_slice($timeline, 0, self::TIMELINE_LIMIT),
            'timeline_truncated' => count($timeline) > self::TIMELINE_LIMIT,
        ];
    }

    /**
     * The case header fields the progress view repeats, so it can stand alone.
     *
     * @return array<string, mixed>
     */
    protected function caseSummary(LegalCase $case): array
    {
        return [
            'id' => $case->id,
            'title' => $case->title,
            'reference' => $case->reference,
            'case_type' => $case->case_type,
            'status' => $case->status,
            'priority' => $case->priority,
            'description' => $case->description,
            'related_parties' => $case->related_parties ?? [],
            'tags' => $case->tags ?? [],
            'due_date' => $case->due_date?->toDateString(),
            'archived_at' => $case->archived_at?->toIso8601String(),
            'created_at' => $case->created_at?->toIso8601String(),
            'updated_at' => $case->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The stage track, with the current stage marked active.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function stages(LegalCase $case): array
    {
        // A held matter has still left the "opened" step, so it shares the
        // in-progress position rather than falling back to the start.
        $current = match ($case->status) {
            'in_progress', 'on_hold' => 1,
            'closed' => 2,
            default => 0,
        };

        return collect(self::STAGES)
            ->map(fn (array $stage, int $index): array => [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'state' => match (true) {
                    $index < $current => 'done',
                    $index === $current => 'active',
                    default => 'pending',
                },
            ])
            ->all();
    }

    /**
     * How far along the matter is. Tasks are the honest measure when the case
     * has any; otherwise the stage the case sits on is the only signal.
     *
     * @param  Collection<int, Todo>  $tasks
     * @return array<string, mixed>
     */
    protected function progress(LegalCase $case, Collection $tasks): array
    {
        $total = $tasks->count();
        $completed = $tasks->where('status', 'completed')->count();

        if ($total > 0) {
            return [
                'percent' => (int) round($completed / $total * 100),
                'basis' => 'tasks',
                'label' => "{$completed} of {$total} tasks done",
            ];
        }

        $percent = match ($case->status) {
            'in_progress' => 50,
            'on_hold' => 50,
            'closed' => 100,
            default => 10,
        };

        return [
            'percent' => $percent,
            'basis' => 'status',
            'label' => 'No tasks yet — measured by case status',
        ];
    }

    /**
     * The deadline picture: null when the case carries no due date.
     *
     * @return array<string, mixed>|null
     */
    protected function deadline(LegalCase $case): ?array
    {
        if ($case->due_date === null) {
            return null;
        }

        $days = (int) Carbon::today()->diffInDays($case->due_date->copy()->startOfDay(), false);

        return [
            'due_date' => $case->due_date->toDateString(),
            'days_remaining' => $days,
            'overdue' => $days < 0 && $case->status !== 'closed',
        ];
    }

    /**
     * The headline counters.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @param  Collection<int, Todo>  $tasks
     * @param  Collection<int, Document>  $documents
     * @param  Collection<int, Message>  $generated
     * @param  Collection<int, MatterMemory>  $memories
     * @return array<string, mixed>
     */
    protected function stats(
        LegalCase $case,
        Collection $conversations,
        Collection $tasks,
        Collection $documents,
        Collection $generated,
        Collection $memories,
        int $userMessages,
        int $assistantMessages,
    ): array {
        $lastActivity = collect([
            $case->created_at,
            $conversations->max('messages_max_created_at'),
            $documents->max('created_at'),
            $tasks->max('updated_at'),
            $generated->max('created_at'),
        ])
            ->filter()
            ->map(fn ($value) => $value instanceof Carbon ? $value : Carbon::parse($value))
            ->max();

        return [
            'days_open' => (int) $case->created_at->startOfDay()->diffInDays(Carbon::today()),
            'threads' => $conversations->count(),
            'messages' => $userMessages + $assistantMessages,
            'user_messages' => $userMessages,
            'assistant_messages' => $assistantMessages,
            'documents' => [
                'total' => $documents->count(),
                'ready' => $documents->filter(fn (Document $doc) => $doc->status->value === 'ready')->count(),
                'processing' => $documents->filter(fn (Document $doc) => in_array($doc->status->value, ['queued', 'processing'], true))->count(),
                'failed' => $documents->filter(fn (Document $doc) => $doc->status->value === 'failed')->count(),
            ],
            'generated_documents' => $generated->count(),
            'tasks' => [
                'total' => $tasks->count(),
                'completed' => $tasks->where('status', 'completed')->count(),
                'in_progress' => $tasks->where('status', 'on-going')->count(),
                'pending' => $tasks->where('status', 'pending')->count(),
                'overdue' => $tasks->filter(fn (Todo $task) => $this->isOverdue($task))->count(),
            ],
            'key_facts' => $memories->count(),
            'last_activity_at' => $lastActivity?->toIso8601String(),
        ];
    }

    /**
     * Per-thread rollup, so the reader can see which line of work is moving.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @param  Collection<int, Todo>  $tasks
     * @return array<int, array<string, mixed>>
     */
    protected function threads(Collection $conversations, Collection $tasks): array
    {
        $byConversation = $tasks->groupBy('conversation_id');

        return $conversations
            ->map(function (Conversation $conversation) use ($byConversation): array {
                $threadTasks = $byConversation->get($conversation->id, collect());

                return [
                    'id' => $conversation->id,
                    'label' => $conversation->purpose ?: ($conversation->title ?: 'Untitled thread'),
                    'messages_count' => (int) $conversation->messages_count,
                    'first_message_at' => $this->iso($conversation->messages_min_created_at),
                    'last_message_at' => $this->iso($conversation->messages_max_created_at),
                    'total_tasks' => $threadTasks->count(),
                    'open_tasks' => $threadTasks->where('status', '!=', 'completed')->count(),
                    'created_at' => $conversation->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Every task on the matter, labelled with the thread it came from.
     *
     * @param  Collection<int, Todo>  $tasks
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, array<string, mixed>>
     */
    protected function tasks(Collection $tasks, Collection $conversations): array
    {
        return $tasks
            ->map(fn (Todo $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_hint' => $task->due_hint,
                'due_date' => $task->due_date?->toDateString(),
                'overdue' => $this->isOverdue($task),
                'thread' => $this->threadLabel($conversations, $task->conversation_id),
                'conversation_id' => $task->conversation_id,
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The files uploaded to the matter.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<int, array<string, mixed>>
     */
    protected function documents(Collection $documents): array
    {
        return $documents
            ->map(fn (Document $doc): array => [
                'id' => $doc->id,
                'title' => $doc->title,
                'original_filename' => $doc->original_filename,
                'status' => $doc->status->value,
                'created_at' => $doc->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The drafts Batayan produced inside the matter.
     *
     * @param  Collection<int, Message>  $generated
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, array<string, mixed>>
     */
    protected function generatedDocuments(Collection $generated, Collection $conversations): array
    {
        return $generated
            ->map(fn (Message $message): array => [
                'id' => $message->id,
                'title' => $message->draftTitle(),
                'thread' => $this->threadLabel($conversations, $message->conversation_id),
                'conversation_id' => $message->conversation_id,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Matter memory grouped by type — the facts, deadlines, strategy, and
     * preferences the assistant carries between threads.
     *
     * @param  Collection<int, MatterMemory>  $memories
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function keyFacts(Collection $memories): array
    {
        $grouped = [];

        foreach (MatterMemory::TYPES as $type) {
            $grouped[$type] = $memories
                ->where('type', $type)
                ->map(fn (MatterMemory $memory): array => [
                    'id' => $memory->id,
                    'content' => $memory->content,
                    'created_at' => $memory->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return $grouped;
    }

    /**
     * Everything that happened to the matter, newest first.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @param  Collection<int, Todo>  $tasks
     * @param  Collection<int, Document>  $documents
     * @param  Collection<int, Message>  $generated
     * @param  Collection<int, MatterMemory>  $memories
     * @param  Collection<int, Message>  $recentMessages
     * @return array<int, array<string, mixed>>
     */
    protected function timeline(
        LegalCase $case,
        Collection $conversations,
        Collection $tasks,
        Collection $documents,
        Collection $generated,
        Collection $memories,
        Collection $recentMessages,
    ): array {
        $events = collect();

        $events->push($this->event('case_created', $case->created_at, 'Case opened', $case->reference));

        foreach ($conversations as $conversation) {
            $events->push($this->event(
                'thread_created',
                $conversation->created_at,
                'Thread started',
                $conversation->purpose ?: $conversation->title,
            ));
        }

        foreach ($documents as $doc) {
            $events->push($this->event(
                'document_uploaded',
                $doc->created_at,
                'Document uploaded',
                $doc->original_filename,
                ['status' => $doc->status->value],
            ));
        }

        foreach ($generated as $draft) {
            $events->push($this->event(
                'document_generated',
                $draft->created_at,
                'Draft produced',
                $draft->draftTitle(),
                ['thread' => $this->threadLabel($conversations, $draft->conversation_id)],
            ));
        }

        foreach ($tasks as $task) {
            $events->push($this->event('task_created', $task->created_at, 'Task added', $task->title));

            // Todos carry no completion timestamp, so the last write is the
            // closest honest stand-in for when the task was ticked off.
            if ($task->status === 'completed') {
                $events->push($this->event('task_completed', $task->updated_at, 'Task completed', $task->title));
            }
        }

        foreach ($memories as $memory) {
            $events->push($this->event(
                'memory_recorded',
                $memory->created_at,
                'Noted for this matter',
                Str::limit($memory->content, 160),
                ['memory_type' => $memory->type],
            ));
        }

        foreach ($recentMessages as $message) {
            $events->push($this->event(
                $message->role === MessageRole::User ? 'message_sent' : 'message_received',
                $message->created_at,
                $message->role === MessageRole::User ? 'You asked' : 'Batayan replied',
                Str::limit(trim(strip_tags($message->content)), 160),
                ['thread' => $this->threadLabel($conversations, $message->conversation_id)],
            ));
        }

        if ($case->archived_at !== null) {
            $events->push($this->event('case_archived', $case->archived_at, 'Case archived'));
        }

        return $events
            ->filter(fn (?array $event): bool => $event !== null)
            ->sortByDesc('sort_key')
            ->values()
            ->map(function (array $event): array {
                unset($event['sort_key']);

                return $event;
            })
            ->all();
    }

    /**
     * Shape one timeline entry, or null when it has no timestamp to place it.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    protected function event(string $type, ?Carbon $at, string $title, ?string $description = null, array $meta = []): ?array
    {
        if ($at === null) {
            return null;
        }

        return [
            'type' => $type,
            'at' => $at->toIso8601String(),
            'title' => $title,
            'description' => $description !== null && $description !== '' ? $description : null,
            'meta' => $meta,
            'sort_key' => $at->getTimestamp(),
        ];
    }

    /**
     * A task is overdue once its due date has passed and it is still open.
     */
    protected function isOverdue(Todo $task): bool
    {
        return $task->due_date !== null
            && $task->status !== 'completed'
            && $task->due_date->startOfDay()->lt(Carbon::today());
    }

    /**
     * Resolve the readable name of the thread something belongs to.
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    protected function threadLabel(Collection $conversations, ?string $conversationId): ?string
    {
        $conversation = $conversations->firstWhere('id', $conversationId);

        if ($conversation === null) {
            return null;
        }

        return $conversation->purpose ?: ($conversation->title ?: null);
    }

    /**
     * Aggregate columns come back as raw strings, so normalize before output.
     */
    protected function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->toIso8601String();
    }
}
