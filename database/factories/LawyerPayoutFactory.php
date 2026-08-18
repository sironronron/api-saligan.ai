<?php

namespace Database\Factories;

use App\Models\LawyerPayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LawyerPayout>
 */
class LawyerPayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gross = fake()->numberBetween(1000, 100000);
        $platformFee = (int) round($gross * (config('vetting.platform_commission_percent', 15) / 100));

        return [
            'lawyer_id' => User::factory(),
            'period_start' => now()->startOfWeek(),
            'period_end' => now()->endOfWeek(),
            'gross_amount' => $gross,
            'platform_fee' => $platformFee,
            'lawyer_share' => $gross - $platformFee,
            'notarization_count' => fake()->numberBetween(1, 20),
            'status' => LawyerPayout::STATUS_PENDING,
            'payout_ref' => null,
            'paid_at' => null,
        ];
    }

    /**
     * A payout that has been disbursed to the lawyer.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LawyerPayout::STATUS_PAID,
            'payout_ref' => 'PO-'.fake()->numberBetween(100000, 999999),
            'paid_at' => now(),
        ]);
    }
}
