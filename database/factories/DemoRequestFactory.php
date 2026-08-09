<?php

namespace Database\Factories;

use App\Models\DemoRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoRequest>
 */
class DemoRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'organization' => fake()->optional()->company(),
            'message' => fake()->optional()->sentence(),
            'status' => 'pending',
            'recaptcha_score' => 0.9,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Pest',
        ];
    }
}
