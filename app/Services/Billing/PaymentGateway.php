<?php

namespace App\Services\Billing;

use App\Enums\BillingGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

interface PaymentGateway
{
    /**
     * The gateway's identifier.
     */
    public function name(): BillingGateway;

    /**
     * Begin a subscription for the user and return the hosted checkout
     * details together with the local subscription row.
     *
     * @return array{
     *     subscription: Subscription,
     *     checkout: array{checkout_url: ?string, payment_intent_id: ?string, public_key: ?string},
     * }
     */
    public function initiateCheckout(User $user, Plan $plan, string $interval, string $successUrl, string $cancelUrl): array;

    /**
     * Change an existing subscription to a different plan.
     */
    public function changePlan(Subscription $subscription, Plan $plan): void;

    /**
     * Cancel an existing subscription.
     */
    public function cancel(Subscription $subscription, ?string $reason = null): void;
}
