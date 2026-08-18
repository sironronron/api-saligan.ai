<?php

namespace App\Services\Billing;

use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Jobs\MatchVettingRequest;
use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Handles the payment side of vetting/notarization requests: authorizing the
 * fee at request time (manual capture, so the lawyer is not paid until the
 * work is done), capturing on completion, and refunding or voiding when a
 * request dies before completion.
 */
final class VettingPaymentService
{
    public function __construct(
        private readonly PaymongoClient $paymongo,
    ) {
        //
    }

    /**
     * Authorize the request's total fee. The buyer pays in PayMongo's hosted
     * checkout; with manual capture the funds are held, not moved.
     *
     * @return array{checkout_url: string, payment_intent_id: string}
     */
    public function authorize(VettingRequest $request, User $submitter): array
    {
        $intent = $this->paymongo->createPaymentIntent(
            amount: $request->totalFee(),
            description: $this->intentDescription($request),
            metadata: ['vetting_request_id' => $request->id],
            captureType: 'manual',
        );

        $intentId = data_get($intent, 'id');

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        $checkout = $this->paymongo->createCheckoutSession(
            paymentIntentId: (string) $intentId,
            customerId: $this->resolveCustomerId($submitter),
            description: $this->intentDescription($request),
            amount: $request->totalFee(),
            successUrl: "{$frontendUrl}/vetting/{$request->id}?payment=return",
            cancelUrl: "{$frontendUrl}/vetting/{$request->id}?payment=cancelled",
            metadata: ['vetting_request_id' => $request->id],
        );

        $request->update([
            'gateway_payment_intent_id' => $intentId,
            'gateway_checkout_url' => data_get($checkout, 'attributes.checkout_url'),
        ]);

        VettingPayment::create([
            'vetting_request_id' => $request->id,
            'submitter_id' => $submitter->id,
            'gateway' => 'paymongo',
            'kind' => $request->includesNotarization()
                ? VettingPayment::KIND_NOTARIZATION
                : VettingPayment::KIND_VETTING,
            'status' => VettingPaymentStatus::Pending,
            'amount' => $request->totalFee(),
            'gateway_payment_intent_id' => $intentId,
        ]);

        return [
            'checkout_url' => (string) $request->gateway_checkout_url,
            'payment_intent_id' => (string) $intentId,
        ];
    }

    /**
     * Handle a `payment.paid` webhook: the buyer has authorized the fee in the
     * checkout, so the request can move to matching.
     */
    public function markAuthorized(string $paymentIntentId, ?string $gatewayPaymentId): bool
    {
        $request = $this->requestByIntent($paymentIntentId);

        if ($request === null) {
            return false;
        }

        $payment = $request->payments()
            ->where('gateway_payment_intent_id', $paymentIntentId)
            ->latest('id')
            ->first();

        if ($payment !== null) {
            $payment->update([
                'status' => VettingPaymentStatus::Authorized,
                'gateway_payment_id' => $gatewayPaymentId,
                'metadata' => array_merge($payment->metadata ?? [], ['authorized_at' => now()->toIso8601String()]),
            ]);
        }

        $request->update(['payment_status' => VettingPaymentStatus::Authorized]);

        if ($request->status === VettingRequestStatus::Declined) {
            // A request that was marked declined reopens once the payment
            // clears: the buyer paid to proceed, so matching starts again.
            $request->update(['status' => VettingRequestStatus::PaymentPending]);
        }

        if ($request->status === VettingRequestStatus::PaymentPending) {
            // A separate job, not a direct service call: VettingRequestService
            // depends on this service, so resolving it here would recurse.
            MatchVettingRequest::dispatch($request);
        }

        return true;
    }

    /**
     * Mark a payment failed (the checkout did not complete).
     */
    public function markFailed(string $paymentIntentId): bool
    {
        $request = $this->requestByIntent($paymentIntentId);

        if ($request === null) {
            return false;
        }

        $request->payments()
            ->where('gateway_payment_intent_id', $paymentIntentId)
            ->update(['status' => VettingPaymentStatus::Failed]);

        $request->update(['payment_status' => VettingPaymentStatus::Failed]);

        return true;
    }

    /**
     * Capture the held fee once the notarization (or vetting) is completed.
     * Fails gracefully if the gateway is unreachable, leaving the request in
     * its current state for a retry rather than losing the capture.
     */
    public function capture(VettingRequest $request): bool
    {
        $intentId = $request->gateway_payment_intent_id;

        if ($intentId === null || $request->payment_status !== VettingPaymentStatus::Authorized) {
            return false;
        }

        try {
            // The intent was created for the request's total fee, so passing it
            // captures the full authorized amount (PayMongo requires the amount).
            $this->paymongo->capturePaymentIntent($intentId, $request->totalFee());
        } catch (ConnectionException $e) {
            Log::warning('Could not capture vetting payment intent.', [
                'vetting_request_id' => $request->id,
                'payment_intent_id' => $intentId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $request->payments()
            ->where('gateway_payment_intent_id', $intentId)
            ->update([
                'status' => VettingPaymentStatus::Captured,
                'lawyer_id' => $request->assigned_lawyer_id,
                'captured_at' => now(),
            ]);

        $request->update(['payment_status' => VettingPaymentStatus::Captured]);

        return true;
    }

    /**
     * Refund or void a request's payment. A held (authorized) intent is
     * cancelled, releasing the funds without a charge; a captured one is
     * refunded. No-op when nothing was ever authorized.
     */
    public function refundOrVoid(VettingRequest $request): void
    {
        $intentId = $request->gateway_payment_intent_id;

        if ($intentId === null) {
            return;
        }

        $status = $request->payment_status;

        if ($status === VettingPaymentStatus::Captured) {
            $this->paymongo->refundPaymentIntent($intentId);

            $request->payments()
                ->where('gateway_payment_intent_id', $intentId)
                ->update([
                    'status' => VettingPaymentStatus::Refunded,
                    'refunded_at' => now(),
                ]);

            $request->update(['payment_status' => VettingPaymentStatus::Refunded]);

            return;
        }

        if ($status === VettingPaymentStatus::Authorized) {
            try {
                $this->paymongo->cancelPaymentIntent($intentId);
            } catch (\Throwable $e) {
                Log::warning('Could not cancel vetting payment intent.', [
                    'vetting_request_id' => $request->id,
                    'payment_intent_id' => $intentId,
                    'error' => $e->getMessage(),
                ]);
            }

            $request->payments()
                ->where('gateway_payment_intent_id', $intentId)
                ->update([
                    'status' => VettingPaymentStatus::Void,
                    'voided_at' => now(),
                ]);

            $request->update(['payment_status' => VettingPaymentStatus::Void]);
        }
    }

    /**
     * Handle a `payment_refund.*` webhook, recording the gateway's refund id.
     */
    public function markRefunded(string $paymentIntentId, ?string $refundId): bool
    {
        $request = $this->requestByIntent($paymentIntentId);

        if ($request === null) {
            return false;
        }

        $request->payments()
            ->where('gateway_payment_intent_id', $paymentIntentId)
            ->update([
                'status' => VettingPaymentStatus::Refunded,
                'gateway_refund_id' => $refundId,
                'refunded_at' => now(),
            ]);

        $request->update(['payment_status' => VettingPaymentStatus::Refunded]);

        return true;
    }

    /**
     * The request behind a gateway payment intent, if any.
     */
    public function requestByIntent(string $paymentIntentId): ?VettingRequest
    {
        return VettingRequest::query()
            ->where('gateway_payment_intent_id', $paymentIntentId)
            ->first();
    }

    /**
     * A human-readable line item for the payment intent and checkout.
     */
    protected function intentDescription(VettingRequest $request): string
    {
        $service = $request->service_type->label();
        $type = $request->document_type;

        return "Batayan — {$service} of {$type} ({$request->summary})";
    }

    /**
     * The submitter's PayMongo customer, created on demand.
     */
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
