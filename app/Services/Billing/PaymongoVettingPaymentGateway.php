<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;

final class PaymongoVettingPaymentGateway implements VettingPaymentGateway
{
    public function __construct(
        private readonly PaymongoClient $paymongo,
    ) {
        //
    }

    public function name(): string
    {
        return 'paymongo';
    }

    public function authorize(VettingRequest $request, User $submitter): array
    {
        $intent = $this->paymongo->createPaymentIntent(
            amount: $request->totalFee(),
            description: $this->intentDescription($request),
            metadata: ['vetting_request_id' => $request->id],
            captureType: 'manual',
        );

        $intentId = (string) data_get($intent, 'id');
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $checkout = $this->paymongo->createCheckoutSession(
            paymentIntentId: $intentId,
            customerId: $this->resolveCustomerId($submitter),
            description: $this->intentDescription($request),
            amount: $request->totalFee(),
            successUrl: "{$frontendUrl}/vetting/{$request->id}?payment=return",
            cancelUrl: "{$frontendUrl}/vetting/{$request->id}?payment=cancelled",
            metadata: ['vetting_request_id' => $request->id],
        );

        return [
            'checkout_url' => (string) data_get($checkout, 'attributes.checkout_url'),
            'payment_intent_id' => $intentId,
        ];
    }

    public function authorizeApproved(VettingPayment $payment): array
    {
        return [
            'gateway_payment_id' => $payment->gateway_payment_id,
            'metadata' => [],
        ];
    }

    public function capture(VettingPayment $payment, VettingRequest $request): array
    {
        $capture = $this->paymongo->capturePaymentIntent(
            (string) $payment->gateway_payment_intent_id,
            $request->totalFee(),
        );

        return [
            'metadata' => array_filter([
                'capture_id' => data_get($capture, 'id'),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    public function void(VettingPayment $payment): void
    {
        $this->paymongo->cancelPaymentIntent((string) $payment->gateway_payment_intent_id);
    }

    public function refund(VettingPayment $payment): array
    {
        $refund = $this->paymongo->refundPaymentIntent((string) $payment->gateway_payment_intent_id);

        return [
            'gateway_refund_id' => data_get($refund, 'id'),
        ];
    }

    protected function intentDescription(VettingRequest $request): string
    {
        return "Batayan — {$request->service_type->label()} of {$request->document_type} ({$request->summary})";
    }

    protected function resolveCustomerId(User $submitter): string
    {
        $existing = $this->paymongo->findCustomerByEmail($submitter->email);

        if ($existing !== null) {
            return (string) ($existing['id'] ?? '');
        }

        $customer = $this->paymongo->createCustomer($submitter->email, $submitter->name);

        return (string) data_get($customer, 'id');
    }
}
