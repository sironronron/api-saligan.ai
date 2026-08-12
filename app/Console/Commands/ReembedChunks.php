<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Models\LegalChunk;
use App\Services\Ai\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Regenerate every stored embedding with the currently configured provider.
 *
 * Embeddings from different models are not comparable: a query vector from one
 * model scored against document vectors from another produces similarity
 * numbers that look valid and mean nothing, so retrieval degrades silently
 * rather than failing. Any change to AI_EMBED_PROVIDER or AI_EMBED_MODEL
 * therefore has to be followed by a full re-embed.
 */
class ReembedChunks extends Command
{
    protected $signature = 'saligan:reembed
        {--documents : Only re-embed uploaded document chunks}
        {--legal : Only re-embed legal knowledge-base chunks}
        {--batch=100 : Rows loaded and written per iteration}';

    protected $description = 'Re-embed stored chunks with the configured embedding provider';

    public function handle(EmbeddingService $embeddings): int
    {
        $both = ! $this->option('documents') && ! $this->option('legal');

        $this->line(sprintf(
            'Embedding with <info>%s</info> / <info>%s</info> at <info>%d</info> dimensions.',
            config('saligan.embedding.provider'),
            config('saligan.embedding.model'),
            (int) config('saligan.embedding.dimensions'),
        ));

        $failed = 0;

        if ($both || $this->option('legal')) {
            $failed += $this->reembed(LegalChunk::query(), 'legal chunks', $embeddings);
        }

        if ($both || $this->option('documents')) {
            $failed += $this->reembed(DocumentChunk::query(), 'document chunks', $embeddings);
        }

        if ($failed > 0) {
            $this->warn("{$failed} batch(es) failed. Re-run to retry — completed rows are already saved.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  Builder<DocumentChunk|LegalChunk>  $query
     * @return int Number of batches that failed
     */
    protected function reembed(Builder $query, string $label, EmbeddingService $embeddings): int
    {
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->line("No {$label} to re-embed.");

            return 0;
        }

        $this->line("Re-embedding {$total} {$label}…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $failed = 0;

        // Ordered by key so the cursor stays stable while rows are updated;
        // chunk() on an unordered query can skip or repeat rows mid-run.
        $query->orderBy('id')->chunkById((int) $this->option('batch'), function ($rows) use ($embeddings, $bar, &$failed): void {
            $contents = $rows->pluck('content')->map(fn ($c) => (string) $c)->all();

            try {
                $vectors = $embeddings->embedMany($contents);
            } catch (Throwable $exception) {
                // One bad batch must not abandon the rest: the run is
                // resumable, and a partial re-embed is still better than none.
                $failed++;
                $this->newLine();
                $this->error("Batch failed: {$exception->getMessage()}");
                $bar->advance($rows->count());

                return;
            }

            foreach ($rows->values() as $index => $row) {
                $row->forceFill(['embedding' => $vectors[$index]])->save();
            }

            $bar->advance($rows->count());
        });

        $bar->finish();
        $this->newLine(2);

        return $failed;
    }
}
