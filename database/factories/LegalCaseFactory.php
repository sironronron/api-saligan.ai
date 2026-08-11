<?php

namespace Database\Factories;

use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalCase>
 */
class LegalCaseFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = LegalCase::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'title' => fake()->sentence(4),
            'case_type' => fake()->randomElement(['legal', 'hr', 'customer_support', 'administrative', 'general']),
            'reference' => 'CASE-'.date('Y').'-'.fake()->unique()->numberBetween(1, 9999),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'urgent']),
            'status' => fake()->randomElement(['open', 'in_progress', 'on_hold', 'closed']),
            'description' => fake()->optional()->paragraph(),
            'related_parties' => fake()->optional()->randomElement([
                ['Juan Dela Cruz (claimant)', 'Maria Santos (respondent)'],
                ['Ana Reyes (client)', 'LTO (opposing party)'],
            ]),
            'due_date' => fake()->optional()->date(),
            'tags' => fake()->optional()->randomElement([
                ['debt', 'collections'],
                ['labor', 'unlawful-dismissal'],
                ['barangay'],
            ]),
        ];
    }

    /**
     * Indicate that the case is archived.
     */
    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    /**
     * Indicate that the case is closed.
     */
    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'closed']);
    }
}
