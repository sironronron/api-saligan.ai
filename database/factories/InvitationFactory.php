<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
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
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => Str::random(64),
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => now()->addDays(Invitation::DEFAULT_EXPIRES_DAYS),
        ];
    }

    /**
     * An expired invitation that can no longer be accepted.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * An already-accepted invitation.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invitation::STATUS_ACCEPTED,
        ]);
    }

    /**
     * A revoked invitation.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invitation::STATUS_REVOKED,
        ]);
    }
}
