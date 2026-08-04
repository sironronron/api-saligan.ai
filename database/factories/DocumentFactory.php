<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'original_filename' => fake()->words(2, true).'.pdf',
            'storage_path' => 'documents/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'status' => DocumentStatus::Queued,
        ];
    }

    /**
     * The document has finished ingestion.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Ready,
        ]);
    }

    /**
     * The document failed ingestion.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Failed,
            'error_message' => 'Unable to extract text from the uploaded file.',
        ]);
    }
}
