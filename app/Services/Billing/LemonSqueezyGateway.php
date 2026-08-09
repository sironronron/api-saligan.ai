<?php

namespace App\Services\Billing;

use App\Enums\BillingGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

class LemonSqueezyGateway implements PaymentGateway
{
    public function __construct(
        private readonly LemonSqueezyClient $client,
    ) {
        //
    }

    public function name(): BillingGateway
    {
        return BillingGateway::LemonSqueezy;
    }

    public function initiateCheckout(User $user, Plan $plan, string $interval, string $successUrl, string $cancelUrl): array
    {
        $variantId = $plan->lemonSqueezyVariantIdForInterval($interval);

        abort_if($variantId === null, 422, 'This plan is not yet available for checkout. Please try again.');

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'gateway' => BillingGateway::LemonSqueezy->value,
            'status' => Subscription::STATUS_INCOMPLETE,
        ]);

        $checkout = $this->client->createCheckout(
            variantId: (string) $variantId,
            email: $user->email,
            name: $user->name,
            storeId: config('lemonsqueezy.store_id') !== null ? (string) config('lemonsqueezy.store_id') : null,
            redirectUrl: $successUrl,
            custom: [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => $interval,
            ],
        );

        return [
            'subscription' => $subscription->load('plan'),
            'checkout' => [
                'checkout_url' => data_get($checkout, 'data.attributes.checkout_url'),
                'payment_intent_id' => null,
                'public_key' => null,
            ],
        ];
    }

    public function changePlan(Subscription $subscription, Plan $plan): void
    {
        abort_if($subscription->lemonsqueezy_subscription_id === null, 422, 'This subscription is not active on LemonSqueezy yet.');

        $variantId = $plan->lemonSqueezyVariantIdForInterval($subscription->interval ?? Plan::INTERVAL_MONTHLY);

        abort_if($variantId === null, 422, 'This plan is not yet available on LemonSqueezy.');

        $this->client->changeSubscriptionVariant($subscription->lemonsqueezy_subscription_id, (string) $variantId);
    }

    public function cancel(Subscription $subscription, ?string $reason = null): void
    {
        if ($subscription->lemonsqueezy_subscription_id !== null) {
            $this->client->cancelSubscription($subscription->lemonsqueezy_subscription_id);
        }
    }
}
