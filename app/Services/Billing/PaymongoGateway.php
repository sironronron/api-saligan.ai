<?php

namespace App\Services\Billing;

use App\Enums\BillingGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Throwable;

class PaymongoGateway implements PaymentGateway
{
    public function __construct(
        private readonly PaymongoClient $paymongo,
    ) {
        //
    }

    public function name(): BillingGateway
    {
        return BillingGateway::Paymongo;
    }

    public function initiateCheckout(User $user, Plan $plan, string $interval, string $successUrl, string $cancelUrl): array
    {
        $customerId = $this->resolveCustomerId($user);
        $paymongoPlanId = $this->resolvePlanId($plan, $interval);

        $paymongoSubscription = $this->paymongo->createSubscription($customerId, $paymongoPlanId, [
            'user_id' => $user->id,
            'plan_slug' => $plan->slug,
            'billing_interval' => $interval,
        ]);

        $paymongoSubscriptionId = $paymongoSubscription['id'] ?? null;

        abort_if(
            ! is_string($paymongoSubscriptionId) || $paymongoSubscriptionId === '',
            422,
            'The subscription could not be initialized. Please try again.',
        );

        $subscription = null;

        try {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'plan_id' => $plan->id,
                'interval' => $interval,
                'gateway' => BillingGateway::Paymongo->value,
                'paymongo_subscription_id' => $paymongoSubscriptionId,
                'paymongo_customer_id' => $customerId,
                'status' => Subscription::STATUS_INCOMPLETE,
                'seats_purchased' => $plan->included_seats,
                // A plan that does not sell seats still has to price the one it
                // covers, or the seat ledger would record every change against
                // nothing. That price is the plan itself.
                'price_per_seat' => $plan->seat_price ?? $plan->price,
            ]);

            $paymentIntentId = data_get($paymongoSubscription, 'attributes.latest_invoice.payment_intent.id');

            abort_if(
                ! is_string($paymentIntentId) || $paymentIntentId === '',
                422,
                'The subscription payment could not be initialized. Please try again.',
            );

            $checkout = $this->paymongo->createCheckoutSession(
                paymentIntentId: $paymentIntentId,
                customerId: $customerId,
                description: "{$plan->name} ".($interval === Plan::INTERVAL_ANNUAL ? 'annual' : 'monthly').' subscription',
                amount: $plan->priceForInterval($interval),
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                metadata: [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'plan_slug' => $plan->slug,
                    'billing_interval' => $interval,
                ],
            );

            $checkoutUrl = data_get($checkout, 'attributes.checkout_url');

            abort_if(
                ! is_string($checkoutUrl) || $checkoutUrl === '',
                422,
                'The subscription checkout could not be initialized. Please try again.',
            );
        } catch (Throwable $exception) {
            $subscription?->delete();

            try {
                $this->paymongo->cancelSubscription(
                    $paymongoSubscriptionId,
                    'Checkout initialization failed',
                );
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        return [
            'subscription' => $subscription->load('plan'),
            'checkout' => [
                'checkout_url' => $checkoutUrl,
                'payment_intent_id' => $paymentIntentId,
                'public_key' => config('paymongo.public_key'),
            ],
        ];
    }

    public function changePlan(
        Subscription $subscription,
        Plan $plan,
        string $interval,
        string $successUrl,
        string $cancelUrl,
    ): ?array {
        $paymongoPlanId = $this->resolvePlanId($plan, $interval);

        $this->paymongo->changeSubscriptionPlan($subscription->paymongo_subscription_id, $paymongoPlanId);

        return null;
    }

    public function cancel(Subscription $subscription, ?string $reason = null): void
    {
        if ($subscription->paymongo_subscription_id !== null) {
            $this->paymongo->cancelSubscription($subscription->paymongo_subscription_id, $reason);
        }
    }

    /**
     * Reuse an existing PayMongo customer, creating one when missing.
     */
    protected function resolveCustomerId(User $user): string
    {
        $existing = $user->subscription?->paymongo_customer_id;

        if ($existing !== null) {
            return $existing;
        }

        $customer = $this->paymongo->findCustomerByEmail($user->email)
            ?? $this->paymongo->createCustomer($user->email, $user->name);

        return $customer['id'];
    }

    /**
     * Reuse an existing PayMongo plan id for the given billing interval,
     * creating the plan on PayMongo when missing.
     */
    protected function resolvePlanId(Plan $plan, string $billingInterval): string
    {
        $existing = $plan->paymongoPlanIdForInterval($billingInterval);

        if ($existing !== null) {
            return $existing;
        }

        $paymongoInterval = $billingInterval === Plan::INTERVAL_ANNUAL ? 'yearly' : 'monthly';

        $paymongoPlan = $this->paymongo->createPlan(
            $plan->name,
            $plan->priceForInterval($billingInterval),
            "{$plan->name} ".($billingInterval === Plan::INTERVAL_ANNUAL ? 'annual' : 'monthly').' subscription',
            [
                'slug' => $plan->slug,
                'interval' => $billingInterval,
            ],
            $paymongoInterval,
        );

        $column = $billingInterval === Plan::INTERVAL_ANNUAL
            ? 'paymongo_plan_id_annual'
            : 'paymongo_plan_id';

        $plan->update([$column => $paymongoPlan['id']]);

        return $paymongoPlan['id'];
    }
}
