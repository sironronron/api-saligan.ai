<?php

namespace Database\Factories;

use App\Enums\VettingMatchStatus;
use App\Models\User;
use App\Models\VettingRequest;
use App\Models\VettingRequestMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VettingRequestMatch>
 */
class VettingRequestMatchFactory extends Factory
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
            'lawyer_id' => User::factory(),
            'status' => VettingMatchStatus::Notified,
            'notified_at' => now(),
            'responded_at' => null,
            'expires_at' => now()->addDays(config('vetting.match_acceptance_hours', 24)),
        ];
    }

    /**
     * The lawyer accepted the offer and holds the request.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VettingMatchStatus::Accepted,
            'responded_at' => now(),
        ]);
    }

    /**
     * The lawyer declined the offer.
     */
    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VettingMatchStatus::Declined,
            'responded_at' => now(),
        ]);
    }
}
