<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VettingMessage;
use App\Models\VettingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VettingMessage>
 */
class VettingMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vetting_request_id' => VettingRequest::factory(),
            'author_id' => User::factory(),
            'body' => fake()->sentence(10),
        ];
    }
}
