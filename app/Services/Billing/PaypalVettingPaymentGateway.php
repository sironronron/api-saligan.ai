<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;

final class PaypalVettingPaymentGateway implements VettingPaymentGateway
{
    public function __construct(
        private readonly PaypalClient $paypal,
    ) {
        //
    }

    public function name(): string
    {
        return 'paypal';
    }

    public function authorize(VettingRequest $request, User $submitter): array
    {
        $order = $this->paypal->createOrder(
            amount: $request->totalFee(),
            description: $this->intentDescription($request),
            customId: (string) $request->id,
            returnUrl: url('/api/paypal/vetting/return'),
            cancelUrl: url("/api/paypal/vetting/cancel?request={$request->id}"),
        );

        $approvalUrl = collect($order['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        abort_if(
            ! is_string($order['id'] ?? null) || ! is_string($approvalUrl),
            422,
            'The PayPal checkout could not be initialized. Please try again.',
        );

        return [
            'checkout_url' => $approvalUrl,
            'payment_intent_id' => $order['id'],
        ];
    }

    public function authorizeApproved(VettingPayment $payment): array
    {
        $order = $this->paypal->authorizeOrder((string) $payment->gateway_payment_intent_id);
        $authorizationId = data_get($order, 'purchase_units.0.payments.authorizations.0.id');

        abort_if(
            ! is_string($authorizationId) || $authorizationId === '',
            422,
            'The PayPal payment authorization could not be confirmed. Please try again.',
        );

        return [
            'gateway_payment_id' => $authorizationId,
            'metadata' => ['authorization_id' => $authorizationId],
        ];
    }

    public function capture(VettingPayment $payment, VettingRequest $request): array
    {
        $authorizationId = (string) ($payment->metadata['authorization_id'] ?? $payment->gateway_payment_id);
        $capture = $this->paypal->captureAuthorization($authorizationId, $request->totalFee());

        return [
            'metadata' => array_filter([
                'capture_id' => data_get($capture, 'id'),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    public function void(VettingPayment $payment): void
    {
        $authorizationId = (string) ($payment->metadata['authorization_id'] ?? $payment->gateway_payment_id);

        $this->paypal->voidAuthorization($authorizationId);
    }

    public function refund(VettingPayment $payment): array
    {
        $captureId = $payment->metadata['capture_id'] ?? null;

        abort_if(
            ! is_string($captureId) || $captureId === '',
            422,
            'The PayPal capture could not be found for refund.',
        );

        $refund = $this->paypal->refundCapture($captureId);

        return [
            'gateway_refund_id' => data_get($refund, 'id'),
        ];
    }

    protected function intentDescription(VettingRequest $request): string
    {
        return "Batayan — {$request->service_type->label()} of {$request->document_type} ({$request->summary})";
    }
}
