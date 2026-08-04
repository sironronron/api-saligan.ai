<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Support\Vector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'user_id' => User::factory(),
            'chunk_index' => fake()->numberBetween(0, 100),
            'content' => fake()->paragraph(),
            'embedding' => Vector::random(),
        ];
    }
}
