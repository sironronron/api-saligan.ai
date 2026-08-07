<?php

namespace Database\Factories;

use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageCounter>
 */
class UsageCounterFactory extends Factory
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
            'period_key' => UsageCounter::currentPeriodKey(),
            'messages_used' => 0,
            'documents_uploaded' => 0,
        ];
    }
}
