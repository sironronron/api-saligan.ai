<?php

namespace App\Services\Cases;

use App\Models\LegalCase;
use App\Models\MatterMemory;
use App\Models\Todo;
use App\Services\Crawler\LegalDigestService;
use App\Services\MatterMemory\MatterMemoryService;
use Illuminate\Support\Collection;

/**
 * Builds and stores the AI-generated working brief for an entire case.
 *
 * The source is assembled here rather than in a controller so every generation
 * path uses the same case scope and the same revision check.
 */
final class CaseDigestService
{
    public function __construct(
        private readonly LegalDigestService $digests,
        private readonly MatterMemoryService $memories,
    ) {}

    /**
     * Queue a refresh after the current transaction commits.
     */
    public function queue(LegalCase $case): void
    {
        GenerateCaseDigest::dispatch($case->id)
            ->onConnection(config('saligan.case_digest.connection', 'database'))
            ->afterCommit();
    }

    /**
     * Lazily backfill cases created before whole-matter digests existed.
     */
    public function queueIfMissing(LegalCase $case): void
    {
        if (! filled($case->digest)) {
            $this->queue($case);
        }
    }

    /**
     * Generate the digest only when the persisted source has changed.
     */
    public function generateAndStore(string $caseId): void
    {
        $case = LegalCase::query()->find($caseId);

        if ($case === null) {
            return;
        }

        $source = $this->source($case);
        $sourceHash = $this->sourceHash($source);

        if (filled($case->digest) && hash_equals((string) $case->digest_source_hash, $sourceHash)) {
            return;
        }

        $digest = $this->digests->generateCase($source);

        if ($digest === null) {
            return;
        }

        // A chat, task, document, or memory may have changed while the model
        // was working. Never let that older answer replace the current brief.
        $latest = LegalCase::query()->find($caseId);

        if ($latest === null || $this->sourceHash($this->source($latest)) !== $sourceHash) {
            return;
        }

        $latest->forceFill([
            'digest' => $digest,
            'digest_generated_at' => now(),
            'digest_source_hash' => $sourceHash,
        ])->saveQuietly();
    }

    /**
     * Produce the complete, non-secret source brief sent to the digest model.
     * Uploaded file paths and embeddings are deliberately excluded.
     */
    public function source(LegalCase $case): string
    {
        $case->load([
            'conversations.messages',
            'documents.chunks',
        ]);

        $tasks = $case->tasks()->get();
        $memories = $this->memories->getMemories($case);

        return collect([
            $this->caseSection($case),
            $this->memorySection($memories),
            $this->taskSection($case, $tasks),
            $this->documentSection($case),
            $this->chatSection($case),
        ])->filter(fn (string $section): bool => $section !== '')->implode("\n\n");
    }

    protected function sourceHash(string $source): string
    {
        return hash('sha256', $source);
    }

    protected function caseSection(LegalCase $case): string
    {
        return collect([
            'CASE RECORD',
            "Title: {$case->title}",
            'Reference: '.($case->reference ?: 'not set'),
            "Type: {$case->case_type}",
            "Status: {$case->status}",
            "Priority: {$case->priority}",
            'Description: '.($case->description ?: 'not recorded'),
            'Related parties: '.(filled($case->related_parties)
                ? implode('; ', $case->related_parties)
                : 'not recorded'),
            'Case deadline: '.($case->due_date?->toDateString() ?? 'not set'),
            'Tags: '.(filled($case->tags) ? implode(', ', $case->tags) : 'none'),
        ])->implode("\n");
    }

    /**
     * @param  Collection<int, MatterMemory>  $memories
     */
    protected function memorySection(Collection $memories): string
    {
        if ($memories->isEmpty()) {
            return "MATTER MEMORY\nNo active matter memory recorded.";
        }

        return "MATTER MEMORY\n".$memories
            ->map(fn ($memory): string => "- [{$memory->type}] {$memory->content}")
            ->implode("\n");
    }

    /**
     * @param  Collection<int, Todo>  $tasks
     */
    protected function taskSection(LegalCase $case, Collection $tasks): string
    {
        $lines = ['TASKS AND DEADLINES'];
        $lines[] = 'Case deadline: '.($case->due_date?->toDateString() ?? 'not set');

        foreach ($tasks as $task) {
            $lines[] = '- [status: '.$task->status.'] '.$task->title
                .($task->priority ? " [priority: {$task->priority}]" : '')
                .($task->due_date ? " [due: {$task->due_date->toDateString()}]" : '')
                .($task->due_hint ? " [deadline note: {$task->due_hint}]" : '')
                .($task->description ? ": {$task->description}" : '');
        }

        if ($tasks->isEmpty()) {
            $lines[] = 'No tasks recorded.';
        }

        return implode("\n", $lines);
    }

    protected function documentSection(LegalCase $case): string
    {
        $lines = ['DOCUMENTS'];

        foreach ($case->documents as $document) {
            $lines[] = "Document: {$document->title} ({$document->status->value})";

            if (filled($document->digest)) {
                $lines[] = "Digest: {$document->digest}";
            } else {
                $content = $document->chunks
                    ->sortBy('chunk_index')
                    ->pluck('content')
                    ->implode("\n\n");

                $lines[] = $content !== '' ? "Extracted text:\n{$content}" : 'No extracted text available.';
            }
        }

        if ($case->documents->isEmpty()) {
            $lines[] = 'No documents attached.';
        }

        return implode("\n", $lines);
    }

    protected function chatSection(LegalCase $case): string
    {
        $lines = ['CASE CHATS'];

        foreach ($case->conversations as $conversation) {
            $lines[] = 'THREAD: '.($conversation->purpose ?: $conversation->title ?: 'Untitled');

            foreach ($conversation->messages as $message) {
                $lines[] = "[{$message->role->value}] {$message->content}";
            }
        }

        if ($case->conversations->isEmpty()) {
            $lines[] = 'No case chat messages recorded.';
        }

        return implode("\n", $lines);
    }
}
