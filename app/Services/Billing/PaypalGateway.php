<?php

namespace App\Services\Billing;

use App\Enums\BillingGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Throwable;

class PaypalGateway implements PaymentGateway
{
    public function __construct(
        private readonly PaypalClient $paypal,
    ) {
        //
    }

    public function name(): BillingGateway
    {
        return BillingGateway::Paypal;
    }

    public function initiateCheckout(User $user, Plan $plan, string $interval, string $successUrl, string $cancelUrl): array
    {
        $planId = $this->planId($plan, $interval);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'gateway' => BillingGateway::Paypal->value,
            'status' => Subscription::STATUS_INCOMPLETE,
            'seats_purchased' => $plan->included_seats,
            'price_per_seat' => $plan->seat_price ?? $plan->price,
        ]);

        try {
            $paypalSubscription = $this->paypal->createSubscription(
                planId: $planId,
                customId: (string) $subscription->id,
                returnUrl: $successUrl,
                cancelUrl: $cancelUrl,
            );

            $approvalUrl = collect($paypalSubscription['links'] ?? [])
                ->firstWhere('rel', 'approve')['href'] ?? null;

            abort_if(
                ! is_string($paypalSubscription['id'] ?? null) || ! is_string($approvalUrl),
                422,
                'The PayPal checkout could not be initialized. Please try again.',
            );

            $subscription->update([
                'paypal_subscription_id' => $paypalSubscription['id'],
            ]);
        } catch (Throwable $exception) {
            $subscription->delete();

            throw $exception;
        }

        return [
            'subscription' => $subscription->fresh()->load('plan'),
            'checkout' => [
                'checkout_url' => $approvalUrl,
                'payment_intent_id' => null,
                'public_key' => null,
            ],
        ];
    }

    public function changePlan(Subscription $subscription, Plan $plan): void
    {
        $interval = $subscription->interval ?? Plan::INTERVAL_MONTHLY;
        $planId = $this->planId($plan, $interval);

        abort_if(
            $subscription->paypal_subscription_id === null,
            422,
            'This subscription is not active on PayPal yet.',
        );

        $this->paypal->patchSubscriptionPlan($subscription->paypal_subscription_id, $planId);
    }

    public function cancel(Subscription $subscription, ?string $reason = null): void
    {
        if ($subscription->paypal_subscription_id !== null) {
            $this->paypal->cancelSubscription(
                $subscription->paypal_subscription_id,
                $reason ?? 'User requested cancellation',
            );
        }
    }

    protected function planId(Plan $plan, string $interval): string
    {
        $planId = config("paypal.plans.{$plan->slug}.{$interval}");

        abort_if(
            ! is_string($planId) || $planId === '',
            422,
            'This plan is not configured for PayPal checkout.',
        );

        return $planId;
    }
}
