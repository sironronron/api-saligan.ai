<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LemonSqueezyClient
{
    /**
     * Create the authenticated HTTP client.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('lemonsqueezy.base_url'))
            ->withToken(config('lemonsqueezy.api_key'))
            ->acceptJson()
            ->withHeaders(['Content-Type' => 'application/vnd.api+json']);
    }

    /**
     * Create a hosted checkout for the given subscription variant, pre-filling
     * the customer's email and carrying our own custom data so webhooks can
     * map the purchase back to the local subscription.
     */
    public function createCheckout(
        string $variantId,
        string $email,
        ?string $name = null,
        array $custom = [],
        ?string $storeId = null,
        ?string $redirectUrl = null,
    ): array {
        $checkoutData = [
            'email' => $email,
            'custom' => $custom,
        ];

        if ($name !== null && $name !== '') {
            $checkoutData['name'] = $name;
        }

        $productOptions = [];

        if ($redirectUrl !== null) {
            $productOptions['redirect_url'] = $redirectUrl;
        }

        $relationships = [
            'variant' => [
                'data' => [
                    'type' => 'variants',
                    'id' => $variantId,
                ],
            ],
        ];

        if ($storeId !== null) {
            $relationships['store'] = [
                'data' => [
                    'type' => 'stores',
                    'id' => $storeId,
                ],
            ];
        }

        $response = $this->client()->post('/checkouts', [
            'data' => [
                'type' => 'checkouts',
                'attributes' => [
                    'checkout_data' => $checkoutData,
                    'checkout_options' => [
                        'button_color' => config('lemonsqueezy.checkout_button_color', '#7047EB'),
                    ],
                ],
                'relationships' => $relationships,
            ],
        ]);

        return $response->throw()->json();
    }

    /**
     * Cancel a subscription. LemonSqueezy keeps it active until the current
     * period ends.
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        $response = $this->client()->delete("/subscriptions/{$subscriptionId}");

        return $response->throw()->json();
    }

    /**
     * Move a subscription to a different variant (plan change). LemonSqueezy
     * applies it immediately, prorating the next invoice.
     */
    public function changeSubscriptionVariant(string $subscriptionId, string $variantId): array
    {
        $response = $this->client()->patch("/subscriptions/{$subscriptionId}", [
            'data' => [
                'type' => 'subscriptions',
                'id' => $subscriptionId,
                'attributes' => [
                    'variant_id' => (int) $variantId,
                ],
            ],
        ]);

        return $response->throw()->json();
    }

    /**
     * Verify a LemonSqueezy webhook signature (HMAC-SHA256 hex digest of the
     * raw request body using the webhook signing secret).
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $signature = $headers['x-signature'][0] ?? null;

        if ($signature === null) {
            return false;
        }

        $secret = config('lemonsqueezy.webhook_secret', '');

        if ($secret === '') {
            return false;
        }

        $digest = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($signature, $digest);
    }
}
