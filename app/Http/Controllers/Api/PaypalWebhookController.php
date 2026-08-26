<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Billing\PaypalClient;
use App\Services\Billing\VettingPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaypalWebhookController extends Controller
{
    public function __construct(
        private readonly PaypalClient $paypal,
        private readonly VettingPaymentService $vettingPayments,
    ) {
        //
    }

    /**
     * Handle a verified PayPal webhook event.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->paypal->verifyWebhookSignature($request->headers->all(), $rawBody)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = $request->json()->all();
        $eventType = $event['event_type'] ?? null;
        $resource = $event['resource'] ?? [];

        $this->syncSubscription($eventType, $resource);
        $this->syncVetting($eventType, $resource);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Mirror PayPal's one-off vetting order lifecycle into its local payment.
     *
     * @param  array<string, mixed>  $resource
     */
    protected function syncVetting(?string $eventType, array $resource): void
    {
        $orderId = data_get($resource, 'supplementary_data.related_ids.order_id')
            ?? ($eventType === 'CHECKOUT.ORDER.APPROVED' ? ($resource['id'] ?? null) : null);

        if (! is_string($orderId) || $orderId === '') {
            return;
        }

        match ($eventType) {
            'CHECKOUT.ORDER.APPROVED' => $this->vettingPayments->authorizePaypalOrder($orderId),
            'PAYMENT.CAPTURE.COMPLETED' => $this->vettingPayments->markPaypalCaptured(
                $orderId,
                (string) ($resource['id'] ?? ''),
            ),
            'PAYMENT.CAPTURE.REFUNDED' => $this->vettingPayments->markPaypalRefunded(
                $orderId,
                (string) ($resource['id'] ?? ''),
            ),
            'PAYMENT.AUTHORIZATION.VOIDED' => $this->vettingPayments->markPaypalVoided($orderId),
            default => null,
        };
    }

    /**
     * Mirror a PayPal subscription state into the local subscription row.
     *
     * @param  array<string, mixed>  $resource
     */
    protected function syncSubscription(?string $eventType, array $resource): void
    {
        $subscription = $this->subscriptionFor($resource);

        if ($subscription === null) {
            return;
        }

        $status = $this->mapStatus($eventType, $resource['status'] ?? null);

        if ($status === null) {
            return;
        }

        $updates = [
            'status' => $status,
            'cancelled_at' => in_array($status, [Subscription::STATUS_CANCELLED], true)
                ? ($subscription->cancelled_at ?? now())
                : null,
        ];

        if (isset($resource['start_time'])) {
            $updates['current_period_start'] = $resource['start_time'];
        }

        if (isset($resource['billing_info']['next_billing_time'])) {
            $updates['current_period_end'] = $resource['billing_info']['next_billing_time'];
        }

        $subscription->update($updates);
    }

    /**
     * Find the local row by PayPal's subscription id or the local custom id.
     *
     * @param  array<string, mixed>  $resource
     */
    protected function subscriptionFor(array $resource): ?Subscription
    {
        $paypalId = $resource['billing_agreement_id'] ?? $resource['id'] ?? null;
        $customId = $resource['custom_id'] ?? null;

        if (is_string($paypalId) && $paypalId !== '') {
            $subscription = Subscription::query()
                ->where('paypal_subscription_id', $paypalId)
                ->first();

            if ($subscription !== null) {
                return $subscription;
            }
        }

        return is_string($customId) && $customId !== ''
            ? Subscription::query()->whereKey($customId)->where('gateway', 'paypal')->first()
            : null;
    }

    /**
     * Map PayPal events and statuses to the local subscription states.
     */
    protected function mapStatus(?string $eventType, ?string $providerStatus): ?string
    {
        return match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED', 'PAYMENT.SALE.COMPLETED' => Subscription::STATUS_ACTIVE,
            'BILLING.SUBSCRIPTION.SUSPENDED' => Subscription::STATUS_PAUSED,
            'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED' => Subscription::STATUS_CANCELLED,
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => Subscription::STATUS_PAST_DUE,
            'BILLING.SUBSCRIPTION.UPDATED' => match ($providerStatus) {
                'ACTIVE' => Subscription::STATUS_ACTIVE,
                'SUSPENDED' => Subscription::STATUS_PAUSED,
                'CANCELLED', 'EXPIRED' => Subscription::STATUS_CANCELLED,
                'APPROVAL_PENDING' => Subscription::STATUS_INCOMPLETE,
                default => null,
            },
            default => null,
        };
    }
}
