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
use InvalidArgumentException;

/**
 * Handles the payment side of vetting/notarization requests: authorizing the
 * fee at request time (manual capture, so the lawyer is not paid until the
 * work is done), capturing on completion, and refunding or voiding when a
 * request dies before completion.
 */
final class VettingPaymentService
{
    public function __construct(
        private readonly PaymongoVettingPaymentGateway $paymongoGateway,
        private readonly PaypalVettingPaymentGateway $paypalGateway,
    ) {
        //
    }

    /**
     * Authorize the request's total fee through the configured gateway.
     *
     * @return array{checkout_url: string, payment_intent_id: string}
     */
    public function authorize(VettingRequest $request, User $submitter): array
    {
        $gateway = $this->newGateway();
        $checkout = $gateway->authorize($request, $submitter);
        $intentId = $checkout['payment_intent_id'];

        $request->update([
            'gateway_payment_intent_id' => $intentId,
            'gateway_checkout_url' => $checkout['checkout_url'],
        ]);

        VettingPayment::create([
            'vetting_request_id' => $request->id,
            'submitter_id' => $submitter->id,
            'gateway' => $gateway->name(),
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

        if ($payment?->status === VettingPaymentStatus::Authorized) {
            return true;
        }

        if ($payment !== null) {
            $payment->update([
                'status' => VettingPaymentStatus::Authorized,
                'gateway_payment_id' => $gatewayPaymentId ?? $payment->gateway_payment_id,
                'metadata' => array_merge(
                    $payment->metadata ?? [],
                    ['authorized_at' => now()->toIso8601String()],
                ),
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
     * Complete the browser return from an approved PayPal vetting order.
     */
    public function authorizePaypalOrder(string $orderId): ?VettingRequest
    {
        $request = $this->requestByIntent($orderId);

        if ($request === null) {
            return null;
        }

        $payment = $request->payments()
            ->where('gateway_payment_intent_id', $orderId)
            ->where('gateway', 'paypal')
            ->latest('id')
            ->first();

        if ($payment === null || $payment->status !== VettingPaymentStatus::Pending) {
            return $request;
        }

        $authorized = $this->paypalGateway->authorizeApproved($payment);
        $this->markAuthorized($orderId, $authorized['gateway_payment_id']);
        $payment->refresh()->update([
            'metadata' => array_merge($payment->metadata ?? [], $authorized['metadata']),
        ]);

        return $request->fresh();
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

        $payment = $request->payments()
            ->where('gateway_payment_intent_id', $intentId)
            ->latest('id')
            ->first();

        if ($payment === null) {
            return false;
        }

        try {
            $result = $this->gatewayFor($payment->gateway)->capture($payment, $request);
        } catch (ConnectionException $e) {
            Log::warning('Could not capture vetting payment intent.', [
                'vetting_request_id' => $request->id,
                'payment_intent_id' => $intentId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $payment->update([
            'status' => VettingPaymentStatus::Captured,
            'lawyer_id' => $request->assigned_lawyer_id,
            'captured_at' => now(),
            'metadata' => array_merge($payment->metadata ?? [], $result['metadata'] ?? []),
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
            $payment = $request->payments()
                ->where('gateway_payment_intent_id', $intentId)
                ->latest('id')
                ->first();

            if ($payment === null) {
                return;
            }

            $result = $this->gatewayFor($payment->gateway)->refund($payment);

            $payment->update([
                'status' => VettingPaymentStatus::Refunded,
                'refunded_at' => now(),
                'gateway_refund_id' => $result['gateway_refund_id'] ?? $payment->gateway_refund_id,
            ]);

            $request->update(['payment_status' => VettingPaymentStatus::Refunded]);

            return;
        }

        if ($status === VettingPaymentStatus::Authorized) {
            $payment = $request->payments()
                ->where('gateway_payment_intent_id', $intentId)
                ->latest('id')
                ->first();

            if ($payment === null) {
                return;
            }

            try {
                $this->gatewayFor($payment->gateway)->void($payment);
            } catch (\Throwable $e) {
                Log::warning('Could not cancel vetting payment intent.', [
                    'vetting_request_id' => $request->id,
                    'payment_intent_id' => $intentId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $payment->update([
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
     * Handle a PayPal capture webhook keyed by its order id.
     */
    public function markPaypalCaptured(string $orderId, string $captureId): bool
    {
        $payment = $this->paypalPayment($orderId);

        if ($payment === null) {
            return false;
        }

        if ($payment->status !== VettingPaymentStatus::Captured) {
            $payment->update([
                'status' => VettingPaymentStatus::Captured,
                'captured_at' => now(),
                'metadata' => array_merge($payment->metadata ?? [], ['capture_id' => $captureId]),
            ]);

            $payment->vettingRequest->update(['payment_status' => VettingPaymentStatus::Captured]);
        }

        return true;
    }

    /**
     * Handle a PayPal capture refund webhook keyed by its order id.
     */
    public function markPaypalRefunded(string $orderId, string $refundId): bool
    {
        $payment = $this->paypalPayment($orderId);

        if ($payment === null) {
            return false;
        }

        $payment->update([
            'status' => VettingPaymentStatus::Refunded,
            'gateway_refund_id' => $refundId,
            'refunded_at' => $payment->refunded_at ?? now(),
        ]);

        $payment->vettingRequest->update(['payment_status' => VettingPaymentStatus::Refunded]);

        return true;
    }

    /**
     * Handle a PayPal authorization void webhook keyed by its order id.
     */
    public function markPaypalVoided(string $orderId): bool
    {
        $payment = $this->paypalPayment($orderId);

        if ($payment === null) {
            return false;
        }

        $payment->update([
            'status' => VettingPaymentStatus::Void,
            'voided_at' => $payment->voided_at ?? now(),
        ]);

        $payment->vettingRequest->update(['payment_status' => VettingPaymentStatus::Void]);

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
     * Resolve the gateway for a new payment.
     */
    protected function newGateway(): VettingPaymentGateway
    {
        return match (config('vetting.payment_gateway')) {
            'paypal' => $this->paypalGateway,
            'paymongo' => $this->paymongoGateway,
            default => throw new InvalidArgumentException('Unsupported vetting payment gateway.'),
        };
    }

    /**
     * Resolve a persisted payment's gateway rather than the current default.
     */
    protected function gatewayFor(string $gateway): VettingPaymentGateway
    {
        return match ($gateway) {
            'paypal' => $this->paypalGateway,
            default => $this->paymongoGateway,
        };
    }

    protected function paypalPayment(string $orderId): ?VettingPayment
    {
        $request = $this->requestByIntent($orderId);

        return $request?->payments()
            ->where('gateway', 'paypal')
            ->where('gateway_payment_intent_id', $orderId)
            ->latest('id')
            ->first();
    }
}
