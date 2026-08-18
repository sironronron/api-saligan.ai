<?php

namespace Database\Factories;

use App\Enums\UrgencyLevel;
use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Enums\VettingServiceType;
use App\Models\Document;
use App\Models\User;
use App\Models\VettingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VettingRequest>
 */
class VettingRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submitter_id' => User::factory(),
            'document_id' => null,
            'document_type' => fake()->randomElement([
                'Deed of Sale',
                'Contract of Lease',
                'Affidavit of Loss',
                'Special Power of Attorney',
                'Extrajudicial Settlement',
            ]),
            'summary' => fake()->sentence(12),
            'jurisdiction' => fake()->randomElement(array_column(config('vetting.regions'), 'value')),
            'service_type' => VettingServiceType::Vetting,
            'urgency' => UrgencyLevel::Normal,
            'status' => VettingRequestStatus::Pending,
            'assigned_lawyer_id' => null,
            'vetting_fee' => config('vetting.default_vetting_fee'),
            'notarization_fee' => 0,
            'payment_status' => VettingPaymentStatus::None,
            'gateway_payment_intent_id' => null,
            'gateway_checkout_url' => null,
            'deadline_at' => null,
            'locked_at' => null,
            'session_scheduled_at' => null,
            'session_link' => null,
            'session_provider' => null,
            'certificate_number' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'metadata' => [],
        ];
    }

    /**
     * A paid request that is waiting for or being matched to lawyers.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VettingRequestStatus::Pending,
            'gateway_payment_intent_id' => 'pi_'.fake()->uuid(),
        ]);
    }

    /**
     * A request a specific lawyer has been offered.
     */
    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VettingRequestStatus::Matched,
        ]);
    }

    /**
     * A request the given lawyer accepted and is working.
     */
    public function assignedTo(User $lawyer): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VettingRequestStatus::Accepted,
            'assigned_lawyer_id' => $lawyer->id,
        ]);
    }

    /**
     * A request that includes the notarization leg and its fee.
     */
    public function notarization(): static
    {
        return $this->state(fn (array $attributes) => [
            'service_type' => VettingServiceType::Both,
            'notarization_fee' => config('vetting.default_notarization_fee'),
        ]);
    }

    /**
     * A request tied to an existing document record.
     */
    public function withDocument(Document $document): static
    {
        return $this->state(fn (array $attributes) => [
            'document_id' => $document->id,
        ]);
    }
}
