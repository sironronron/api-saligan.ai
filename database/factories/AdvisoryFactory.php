<?php

namespace Database\Factories;

use App\Models\Advisory;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advisory>
 */
class AdvisoryFactory extends Factory
{
    protected $model = Advisory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'kind' => $this->faker->randomElement(Advisory::KINDS),
            'title' => $this->faker->sentence(8),
            'detail' => $this->faker->optional()->sentence(14),
            'severity' => $this->faker->randomElement(Advisory::SEVERITIES),
            'status' => 'open',
            'order' => 0,
        ];
    }

    /**
     * An advisory the user has already answered.
     */
    public function answered(string $status = 'not_a_problem'): static
    {
        return $this->state(fn () => ['status' => $status, 'responded_at' => now()]);
    }
}
