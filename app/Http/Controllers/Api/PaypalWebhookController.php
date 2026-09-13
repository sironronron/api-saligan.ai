<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\PaypalClient;
use App\Services\Billing\VettingPaymentService;
use App\Services\Integrations\IntegrationEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        $eventId = is_string($event['id'] ?? null) ? $event['id'] : null;

        $this->syncSubscription($eventType, $resource, $event['create_time'] ?? null, $eventId);
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
    protected function syncSubscription(
        ?string $eventType,
        array $resource,
        mixed $createdAt = null,
        ?string $eventId = null,
    ): void {
        DB::transaction(function () use (
            $eventType,
            $resource,
            $createdAt,
            $eventId,
        ): void {
            $subscription = $this->subscriptionFor($resource, true);

            if ($subscription === null) {
                return;
            }

            if ($eventId !== null && $subscription->paypal_last_event_id === $eventId) {
                return;
            }

            $status = $this->mapStatus($eventType, $resource['status'] ?? null);

            if ($status === null) {
                return;
            }

            $eventTime = $this->eventTime($createdAt);

            if ($subscription->paypal_last_event_at !== null && $eventTime === null) {
                return;
            }

            if ($eventTime !== null && $subscription->paypal_last_event_at?->greaterThan($eventTime)) {
                return;
            }

            $approvalPending = $eventType === 'BILLING.SUBSCRIPTION.UPDATED'
                && ($resource['status'] ?? null) === 'APPROVAL_PENDING';
            $updates = [];
            $statusChanged = ! $approvalPending && $subscription->status !== $status;
            $planChanged = false;

            if (! $approvalPending || $subscription->status === Subscription::STATUS_INCOMPLETE) {
                $updates['status'] = $status;
                $updates['cancelled_at'] = $status === Subscription::STATUS_CANCELLED
                    ? ($subscription->cancelled_at ?? now())
                    : null;
            }

            if ($eventTime !== null) {
                $updates['paypal_last_event_at'] = $eventTime;
            }

            if ($eventId !== null) {
                $updates['paypal_last_event_id'] = $eventId;
            }

            if (isset($resource['start_time'])) {
                $updates['current_period_start'] = $resource['start_time'];
            }

            if (isset($resource['billing_info']['next_billing_time'])) {
                $updates['current_period_end'] = $resource['billing_info']['next_billing_time'];
            }

            $plan = $this->planFor($resource['plan_id'] ?? null);

            $planChangeMatchesPending = $plan !== null
                && $subscription->pending_plan_id === $plan['id']
                && ($subscription->pending_plan_interval === null
                    || $subscription->pending_plan_interval === $plan['interval']);
            $providerPlanMatchesLocal = $plan !== null
                && $subscription->plan_id === $plan['id']
                && $subscription->interval === $plan['interval'];

            // Once a revision is marked pending, only that exact plan and
            // interval may consume the marker. A webhook for the current
            // subscription can arrive while the revision is still in flight.
            $planMatchesExpectedState = $subscription->pending_plan_id !== null
                ? $planChangeMatchesPending
                : $providerPlanMatchesLocal;

            // A cleared PayPal revision can still deliver its old webhook after
            // the user cancelled the approval flow. Only apply a plan that was
            // already local or explicitly awaiting approval; never let a stale
            // provider callback resurrect a cancelled local change.
            if ($planMatchesExpectedState && ! $approvalPending) {
                $planChanged = $subscription->plan_id !== $plan['id']
                    || $subscription->interval !== $plan['interval'];

                $updates['plan_id'] = $plan['id'];
                $updates['interval'] = $plan['interval'];
                $updates['price_per_seat'] = $plan['model']->seat_price ?? $plan['model']->price;
                $updates['seats_purchased'] = max(
                    $subscription->seats_purchased,
                    $plan['model']->included_seats ?? 1,
                );

                if ($subscription->pending_plan_id === $plan['id']) {
                    $updates['pending_plan_id'] = null;
                    $updates['pending_plan_interval'] = null;
                    $updates['pending_plan_checkout_url'] = null;
                }
            }

            $subscription->update($updates);

            if (($planChanged || $statusChanged) && $subscription->organization_id !== null) {
                $organization = $subscription->organization;

                if ($organization !== null) {
                    app(IntegrationEligibility::class)->syncOrganization($organization);
                }
            } elseif ($planChanged || $statusChanged) {
                $user = $subscription->user;

                if ($user !== null) {
                    app(IntegrationEligibility::class)->syncUser($user);
                }
            }
        });
    }

    /**
     * Find the local row by PayPal's subscription id or the local custom id.
     *
     * @param  array<string, mixed>  $resource
     */
    protected function subscriptionFor(array $resource, bool $lockForUpdate = false): ?Subscription
    {
        $paypalId = $resource['billing_agreement_id'] ?? $resource['id'] ?? null;
        $customId = $resource['custom_id'] ?? null;

        if (is_string($paypalId) && $paypalId !== '') {
            $query = Subscription::query()->where('paypal_subscription_id', $paypalId);
            $subscription = ($lockForUpdate ? $query->lockForUpdate() : $query)->first();

            if ($subscription !== null) {
                return $subscription;
            }
        }

        if (! is_string($customId) || $customId === '') {
            return null;
        }

        $query = Subscription::query()->whereKey($customId)->where('gateway', 'paypal');

        return ($lockForUpdate ? $query->lockForUpdate() : $query)->first();
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

    /**
     * Resolve a PayPal plan id to the local plan and billing interval.
     *
     * @return array{id: string, interval: string, model: Plan}|null
     */
    protected function planFor(mixed $providerPlanId): ?array
    {
        if (! is_string($providerPlanId) || $providerPlanId === '') {
            return null;
        }

        foreach (Plan::query()->get() as $plan) {
            foreach (['monthly', 'annual'] as $interval) {
                if (config("paypal.plans.{$plan->slug}.{$interval}") === $providerPlanId) {
                    return [
                        'id' => $plan->id,
                        'interval' => $interval,
                        'model' => $plan,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Parse a provider event timestamp without allowing malformed events to
     * prevent PayPal's webhook endpoint from returning a successful response.
     */
    protected function eventTime(mixed $createdAt): ?Carbon
    {
        if (! is_string($createdAt) || $createdAt === '') {
            return null;
        }

        try {
            return Carbon::parse($createdAt);
        } catch (\Throwable) {
            return null;
        }
    }
}
