<?php

namespace App\Services\Ai;

use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

class EmbeddingService
{
    /**
     * Generate an embedding vector for the given text.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        return $this->embedMany([$text])[0];
    }

    /**
     * Generate embedding vectors for the given texts in a single batch.
     *
     * qwen3-embedding emits 768 dimensions in this setup; the stored prefix
     * length is configured via EMBEDDING_DIMENSIONS and must match the
     * halfvec(768) columns and their indexes.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedMany(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $batchSize = (int) config('saligan.embedding.batch_size', 16);
        $dimensions = (int) config('saligan.embedding.dimensions');

        $vectors = [];

        foreach (array_chunk(array_values($texts), $batchSize) as $batch) {
            $response = Embeddings::for($batch)
                ->timeout(config('saligan.embedding.timeout', 600))
                ->generate(
                    Lab::from(config('saligan.embedding.provider')),
                    config('saligan.embedding.model'),
                );

            foreach ($response->embeddings as $embedding) {
                $vectors[] = array_slice($embedding, 0, $dimensions);
            }
        }

        return $vectors;
    }
}
