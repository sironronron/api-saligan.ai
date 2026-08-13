<?php

namespace Database\Factories;

use App\Models\TrialCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrialCode>
 */
class TrialCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => TrialCode::generateCode(),
            'plan_id' => null,
            'trial_days' => 14,
            'max_redemptions' => null,
            'redeemed_count' => 0,
            'expires_at' => null,
            'owner_user_id' => null,
            'is_active' => true,
            'note' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A code with no redemptions left.
     */
    public function exhausted(int $max = 1): static
    {
        return $this->state(fn (): array => [
            'max_redemptions' => $max,
            'redeemed_count' => $max,
        ]);
    }

    public function referralFor(User $user): static
    {
        return $this->state(fn (): array => [
            'owner_user_id' => $user->id,
            'code' => TrialCode::generateCode(prefix: TrialCode::referralPrefixFor($user)),
        ]);
    }
}
