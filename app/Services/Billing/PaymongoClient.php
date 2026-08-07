<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PaymongoClient
{
    /**
     * Create the authenticated HTTP client.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('paymongo.base_url'))
            ->withBasicAuth(config('paymongo.secret_key'), '')
            ->acceptJson()
            ->asJson();
    }

    /**
     * Find an existing PayMongo Customer by email address.
     */
    public function findCustomerByEmail(string $email): ?array
    {
        $response = $this->client()->get('/v1/customers', [
            'email' => $email,
        ]);

        $response->throw();

        return $response->json('data')[0] ?? null;
    }

    /**
     * Create a PayMongo Customer for a user.
     */
    public function createCustomer(string $email, ?string $name = null): array
    {
        [$firstName, $lastName] = $this->splitName($name);

        $response = $this->client()->post('/v1/customers', [
            'data' => [
                'attributes' => [
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'default_device' => 'email',
                ],
            ],
        ]);

        return $response->throw()->json('data');
    }

    /**
     * Split a full name into first and last parts.
     *
     * @return array{0: string, 1: string}
     */
    protected function splitName(?string $name): array
    {
        if ($name === null || $name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', trim($name));

        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $lastName = array_pop($parts);

        return [implode(' ', $parts), $lastName];
    }

    /**
     * Create a PayMongo billing Plan (scheduled, indefinite).
     *
     * @param  string  $interval  Billing frequency: 'monthly' or 'yearly'.
     *
     * @see https://docs.paymongo.com/reference/create-a-plan
     */
    public function createPlan(
        string $name,
        int $amount,
        string $description,
        array $metadata = [],
        string $interval = 'monthly',
    ): array {
        $response = $this->client()->post('/v1/subscriptions/plans', [
            'data' => [
                'attributes' => [
                    'name' => $name,
                    'plan_type' => 'scheduled',
                    'amount' => $amount,
                    'currency' => 'PHP',
                    'interval' => $interval,
                    'interval_count' => 1,
                    'description' => $description,
                    'metadata' => $metadata,
                ],
            ],
        ]);

        return $response->throw()->json('data');
    }

    /**
     * Create a subscription linking a customer to a plan. The returned
     * subscription carries the first invoice's payment intent, which the
     * client completes (vaulting the payment method for future cycles).
     */
    public function createSubscription(string $customerId, string $planId, array $metadata = []): array
    {
        $response = $this->client()->post('/v1/subscriptions', [
            'data' => [
                'attributes' => [
                    'customer_id' => $customerId,
                    'plan_id' => $planId,
                    'metadata' => $metadata,
                ],
            ],
        ]);

        return $response->throw()->json('data');
    }

    /**
     * Retrieve a subscription by PayMongo id.
     */
    public function retrieveSubscription(string $subscriptionId): array
    {
        $response = $this->client()->get("/v1/subscriptions/{$subscriptionId}");

        return $response->throw()->json('data');
    }

    /**
     * Cancel a subscription immediately.
     */
    public function cancelSubscription(string $subscriptionId, ?string $reason = null): array
    {
        $attributes = $reason !== null ? ['cancellation_reason' => $reason] : [];

        $response = $this->client()->post("/v1/subscriptions/{$subscriptionId}/cancel", [
            'data' => [
                'attributes' => $attributes,
            ],
        ]);

        return $response->throw()->json('data');
    }

    /**
     * Change the plan of an existing subscription. PayMongo applies the new
     * plan at the next billing cycle.
     */
    public function changeSubscriptionPlan(string $subscriptionId, string $planId): array
    {
        $response = $this->client()->put("/v1/subscriptions/{$subscriptionId}/plan", [
            'data' => [
                'attributes' => [
                    'plan_id' => $planId,
                ],
            ],
        ]);

        return $response->throw()->json('data');
    }

    /**
     * Retrieve a payment intent (used to reflect the first-payment status).
     */
    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        $response = $this->client()->get("/v1/payment_intents/{$paymentIntentId}");

        return $response->throw()->json('data');
    }

    /**
     * Create a hosted Checkout Session wrapping an existing payment intent
     * (the subscription's first invoice payment intent). PayMongo renders
     * the payment page at the returned checkout_url; completing it pays the
     * wrapped intent and vaults the payment method for future cycles.
     */
    public function createCheckoutSession(
        string $paymentIntentId,
        string $customerId,
        string $description,
        int $amount,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): array {
        $response = $this->client()->post('/v1/checkout_sessions', [
            'data' => [
                'attributes' => [
                    'payment_intent_id' => $paymentIntentId,
                    'customer_id' => $customerId,
                    'description' => $description,
                    'line_items' => [
                        [
                            'name' => $description,
                            'amount' => $amount,
                            'currency' => 'PHP',
                            'quantity' => 1,
                        ],
                    ],
                    'payment_method_types' => ['card', 'paymaya'],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'metadata' => $metadata,
                ],
            ],
        ]);

        return $response->throw()->json('data');
    }

    /**
     * Verify a PayMongo webhook signature using the shared webhook secret.
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $signature = $headers['paymongo-signature'][0] ?? null;
        if ($signature === null) {
            return false;
        }

        $secret = config('paymongo.webhook_secret', '');
        $prefix = config('paymongo.webhook_signature_prefix', 'paymongo');

        $digest = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($signature, $digest)
            || hash_equals($signature, "{$prefix}_{$digest}");
    }
}
