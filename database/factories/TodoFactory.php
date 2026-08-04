<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Todo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'title' => $this->faker->sentence(6),
            'status' => 'pending',
            'priority' => $this->faker->randomElement(['low', 'medium', 'high']),
            'due_hint' => $this->faker->optional()->sentence(4),
        ];
    }

    /**
     * Indicate that the todo is completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }
}
