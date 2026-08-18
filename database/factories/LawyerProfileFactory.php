<?php

namespace Database\Factories;

use App\Enums\LawyerVerificationStatus;
use App\Models\LawyerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LawyerProfile>
 */
class LawyerProfileFactory extends Factory
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
            'full_name' => fake()->name(),
            'bar_number' => (string) fake()->unique()->numberBetween(10000, 99999),
            'bar_jurisdiction' => 'Integrated Bar of the Philippines',
            'ptr_number' => (string) fake()->numberBetween(1000000, 9999999),
            'practice_areas' => [fake()->randomElement(array_column(config('vetting.practice_areas'), 'value'))],
            'region' => fake()->randomElement(array_column(config('vetting.regions'), 'value')),
            'city' => fake()->city(),
            'phone' => fake()->phoneNumber(),
            'is_notary' => false,
            'notarial_commission_number' => null,
            'notarial_commission_issuer' => null,
            'notarial_commission_expires_at' => null,
            'id_document_path' => null,
            'bar_membership_document_path' => null,
            'verification_status' => LawyerVerificationStatus::Pending,
            'verification_reason' => null,
            'verification_reviewed_at' => null,
            'verified_at' => null,
            'available' => false,
            'max_concurrent_assignments' => config('vetting.max_concurrent_assignments', 3),
            'notify_email' => true,
            'notify_sms' => false,
            'notify_push' => false,
            'notify_in_app' => true,
            'profile_changed_at' => null,
        ];
    }

    /**
     * A verified, available lawyer who accepts new requests.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => LawyerVerificationStatus::Verified,
            'verification_reviewed_at' => now()->subDay(),
            'verified_at' => now()->subDay(),
            'available' => true,
        ]);
    }

    /**
     * A verified lawyer who is not currently accepting requests.
     */
    public function unavailable(): static
    {
        return $this->verified()->state(fn (array $attributes) => [
            'available' => false,
        ]);
    }

    /**
     * A rejected registration.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => LawyerVerificationStatus::Rejected,
            'verification_reason' => 'Credentials could not be verified.',
            'verification_reviewed_at' => now(),
        ]);
    }

    /**
     * A verified, available notary public whose commission is in force.
     */
    public function notary(): static
    {
        return $this->verified()->state(fn (array $attributes) => [
            'is_notary' => true,
            'notarial_commission_number' => (string) fake()->unique()->numberBetween(100000, 999999),
            'notarial_commission_issuer' => 'Office of the Court Administrator',
            'notarial_commission_expires_at' => now()->addYear(),
        ]);
    }
}
