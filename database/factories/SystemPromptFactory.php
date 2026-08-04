<?php

namespace Database\Factories;

use App\Models\SystemPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemPrompt>
 */
class SystemPromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'saligan',
            'content' => 'You are Saligan, a Philippine legal research assistant.',
            'version' => 1,
            'is_active' => true,
        ];
    }
}
