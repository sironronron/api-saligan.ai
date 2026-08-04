<?php

namespace Database\Factories;

use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Support\Vector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalChunk>
 */
class LegalChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crawled_page_id' => CrawledPage::factory(),
            'chunk_index' => fake()->numberBetween(0, 100),
            'content' => fake()->paragraph(),
            'embedding' => Vector::random(),
        ];
    }
}
