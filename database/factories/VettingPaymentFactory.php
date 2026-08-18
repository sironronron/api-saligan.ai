<?php

namespace Database\Factories;

use App\Enums\VettingPaymentStatus;
use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VettingPayment>
 */
class VettingPaymentFactory extends Factory
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
            'submitter_id' => User::factory(),
            'lawyer_id' => null,
            'gateway' => 'paymongo',
            'kind' => VettingPayment::KIND_VETTING,
            'status' => VettingPaymentStatus::Pending,
            'amount' => config('vetting.default_vetting_fee'),
            'gateway_payment_intent_id' => 'pi_'.fake()->uuid(),
            'gateway_payment_id' => null,
            'gateway_payment_method_id' => null,
            'gateway_refund_id' => null,
            'receipt_ref' => null,
            'captured_at' => null,
            'refunded_at' => null,
            'voided_at' => null,
            'metadata' => [],
        ];
    }

    /**
     * The buyer authorized the payment; matching can start.
     */
    public function authorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VettingPaymentStatus::Authorized,
            'gateway_payment_id' => 'pay_'.fake()->uuid(),
        ]);
    }

    /**
     * A captured (completed) payment.
     */
    public function captured(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VettingPaymentStatus::Captured,
            'gateway_payment_id' => 'pay_'.fake()->uuid(),
            'receipt_ref' => (string) fake()->numberBetween(100000, 999999),
            'captured_at' => now(),
        ]);
    }
}
