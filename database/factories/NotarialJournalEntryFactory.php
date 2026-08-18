<?php

namespace Database\Factories;

use App\Models\NotarialJournalEntry;
use App\Models\User;
use App\Models\VettingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotarialJournalEntry>
 */
class NotarialJournalEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lawyer_id' => User::factory(),
            'vetting_request_id' => VettingRequest::factory(),
            'signer_name' => fake()->name(),
            'id_type' => fake()->randomElement(['Government Issued ID', 'Passport', 'Driver License']),
            'id_number' => (string) fake()->numberBetween(100000000, 999999999),
            'document_type' => 'Deed of Sale',
            'verification_method' => 'video',
            'certificate_number' => 'BAT-'.now()->format('Ymd').'-'.fake()->unique()->numberBetween(100000, 999999),
            'session_recording_ref' => null,
            'notarized_at' => now(),
            'metadata' => [],
        ];
    }
}
