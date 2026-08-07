<?php

namespace App\Services\Billing;

use App\Enums\BillingGateway;
use App\Models\Plan;
use App\Models\Subscription;

class BillingGatewayManager
{
    public function __construct(
        private readonly PaymongoGateway $paymongo,
        private readonly LemonSqueezyGateway $lemonsqueezy,
    ) {
        //
    }

    /**
     * The gateway to use for a new checkout on the given plan. Returns the
     * configured default when LemonSqueezy is fully provisioned for that
     * plan and interval, otherwise falls back to PayMongo.
     */
    public function resolve(Plan $plan, string $interval): PaymentGateway
    {
        if ($this->wantsLemonSqueezy()
            && $this->lemonSqueezyConfigured()
            && $plan->lemonSqueezyVariantIdForInterval($interval) !== null) {
            return $this->lemonsqueezy;
        }

        return $this->paymongo;
    }

    /**
     * The gateway an existing subscription belongs to.
     */
    public function for(Subscription $subscription): PaymentGateway
    {
        return $subscription->gateway === BillingGateway::LemonSqueezy->value
            ? $this->lemonsqueezy
            : $this->paymongo;
    }

    /**
     * Whether LemonSqueezy is the configured default gateway.
     */
    protected function wantsLemonSqueezy(): bool
    {
        return config('billing.default_gateway') === BillingGateway::LemonSqueezy->value;
    }

    /**
     * Whether the LemonSqueezy API credentials are present.
     */
    protected function lemonSqueezyConfigured(): bool
    {
        return config('lemonsqueezy.api_key') !== null && config('lemonsqueezy.api_key') !== '';
    }
}
