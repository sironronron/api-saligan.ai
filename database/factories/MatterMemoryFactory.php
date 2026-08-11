<?php

namespace Database\Factories;

use App\Models\LegalCase;
use App\Models\MatterMemory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterMemory>
 */
class MatterMemoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'case_id' => LegalCase::factory(),
            'user_id' => User::factory(),
            'type' => $this->faker->randomElement(['fact', 'preference', 'deadline', 'strategy']),
            'content' => $this->faker->sentence(),
            'metadata' => null,
            'is_active' => true,
        ];
    }
}
