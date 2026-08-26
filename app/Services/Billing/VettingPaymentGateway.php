<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;

interface VettingPaymentGateway
{
    public function name(): string;

    /**
     * @return array{checkout_url: string, payment_intent_id: string}
     */
    public function authorize(VettingRequest $request, User $submitter): array;

    /**
     * @return array{gateway_payment_id: string|null, metadata: array<string, mixed>}
     */
    public function authorizeApproved(VettingPayment $payment): array;

    /**
     * @return array<string, mixed>
     */
    public function capture(VettingPayment $payment, VettingRequest $request): array;

    public function void(VettingPayment $payment): void;

    /**
     * @return array<string, mixed>
     */
    public function refund(VettingPayment $payment): array;
}
