<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PaypalClient
{
    /**
     * Create a PayPal subscription approval flow.
     */
    public function createSubscription(
        string $planId,
        string $customId,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        $response = $this->client(true)->post('/v1/billing/subscriptions', [
            'plan_id' => $planId,
            'custom_id' => $customId,
            'application_context' => [
                'brand_name' => 'Batayan',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);

        return $response->throw()->json();
    }

    /**
     * Create a PayPal order that holds funds for later authorization capture.
     */
    public function createOrder(
        int $amount,
        string $description,
        string $customId,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        $response = $this->client(true)->post('/v2/checkout/orders', [
            'intent' => 'AUTHORIZE',
            'purchase_units' => [
                [
                    'custom_id' => $customId,
                    'description' => $description,
                    'amount' => [
                        'currency_code' => config('paypal.currency', 'PHP'),
                        'value' => $this->formatAmount($amount),
                    ],
                ],
            ],
            'application_context' => [
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);

        return $response->throw()->json();
    }

    /**
     * Authorize an approved PayPal order.
     */
    public function authorizeOrder(string $orderId): array
    {
        $response = $this->client(true)->post("/v2/checkout/orders/{$orderId}/authorize");

        return $response->throw()->json();
    }

    /**
     * Capture an authorized PayPal order.
     */
    public function captureOrder(string $orderId): array
    {
        $response = $this->client(true)->post("/v2/checkout/orders/{$orderId}/capture");

        return $response->throw()->json();
    }

    /**
     * Capture an authorization created by an AUTHORIZE order.
     */
    public function captureAuthorization(string $authorizationId, int $amount): array
    {
        $response = $this->client(true)->post("/v2/payments/authorizations/{$authorizationId}/capture", [
            'amount' => [
                'currency_code' => config('paypal.currency', 'PHP'),
                'value' => $this->formatAmount($amount),
            ],
            'final_capture' => true,
        ]);

        return $response->throw()->json();
    }

    /**
     * Void a PayPal authorization.
     */
    public function voidAuthorization(string $authorizationId): array
    {
        $response = $this->client(true)->post("/v2/payments/authorizations/{$authorizationId}/void");

        return $response->throw()->json() ?? [];
    }

    /**
     * Refund a PayPal capture, optionally for a partial amount in centavos.
     */
    public function refundCapture(string $captureId, ?int $amount = null): array
    {
        $data = [];

        if ($amount !== null) {
            $data['amount'] = [
                'currency_code' => config('paypal.currency', 'PHP'),
                'value' => $this->formatAmount($amount),
            ];
        }

        $response = $this->client(true)->post("/v2/payments/captures/{$captureId}/refund", $data);

        return $response->throw()->json();
    }

    /**
     * Revise the plan for an existing PayPal subscription.
     */
    public function reviseSubscriptionPlan(
        string $subscriptionId,
        string $planId,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        $response = $this->client(true)->post("/v1/billing/subscriptions/{$subscriptionId}/revise", [
            'plan_id' => $planId,
            'application_context' => [
                'brand_name' => 'Batayan',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);

        return $response->throw()->json() ?? [];
    }

    /**
     * Cancel a PayPal subscription.
     */
    public function cancelSubscription(string $subscriptionId, string $reason = 'User requested cancellation'): array
    {
        $response = $this->client(true)->post("/v1/billing/subscriptions/{$subscriptionId}/cancel", [
            'reason' => $reason,
        ]);

        return $response->throw()->json() ?? [];
    }

    /**
     * Retrieve a PayPal order.
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->client()->get("/v2/checkout/orders/{$orderId}");

        return $response->throw()->json();
    }

    /**
     * Ask PayPal to verify a webhook using the raw event and transmission headers.
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $event = json_decode($rawBody, true);

        $transmissionHeaders = [
            'auth_algo' => $this->headerValue($headers, 'paypal-auth-algo'),
            'cert_url' => $this->headerValue($headers, 'paypal-cert-url'),
            'transmission_id' => $this->headerValue($headers, 'paypal-transmission-id'),
            'transmission_sig' => $this->headerValue($headers, 'paypal-transmission-sig'),
            'transmission_time' => $this->headerValue($headers, 'paypal-transmission-time'),
        ];

        if (! is_array($event) || in_array(null, $transmissionHeaders, true)) {
            return false;
        }

        $response = $this->client()->post('/v1/notifications/verify-webhook-signature', [
            ...$transmissionHeaders,
            'webhook_id' => config('paypal.webhook_id'),
            'webhook_event' => $event,
        ]);

        return $response->throw()->json('verification_status') === 'SUCCESS';
    }

    /**
     * Create an authenticated PayPal HTTP client.
     */
    protected function client(bool $withRequestId = false): PendingRequest
    {
        $client = Http::baseUrl(config('paypal.base_url'))
            ->timeout(config('paypal.timeout', 15))
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson();

        if ($withRequestId) {
            $client = $client->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
            ]);
        }

        return $client;
    }

    /**
     * Retrieve and cache a PayPal OAuth access token until shortly before expiry.
     */
    protected function accessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();
        $cachedToken = Cache::get($cacheKey);

        if (is_array($cachedToken)
            && is_string($cachedToken['access_token'] ?? null)
            && ($cachedToken['expires_at'] ?? 0) > now()->timestamp) {
            return $cachedToken['access_token'];
        }

        if ($cachedToken !== null) {
            Cache::forget($cacheKey);
        }

        $token = Cache::remember($cacheKey, now()->addDay(), function (): array {
            $response = Http::timeout(config('paypal.timeout', 15))
                ->asForm()
                ->withBasicAuth(config('paypal.client_id', ''), config('paypal.client_secret', ''))
                ->acceptJson()
                ->post(config('paypal.oauth_url'), [
                    'grant_type' => 'client_credentials',
                ]);

            $response->throw();

            $accessToken = $response->json('access_token');
            if (! is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('PayPal OAuth response did not contain an access token.');
            }

            $expiresIn = max(1, (int) $response->json('expires_in', 3600) - 60);

            return [
                'access_token' => $accessToken,
                'expires_at' => now()->addSeconds($expiresIn)->timestamp,
            ];
        });

        return $token['access_token'];
    }

    /**
     * Use provider credentials and endpoint to prevent cross-environment token reuse.
     */
    protected function tokenCacheKey(): string
    {
        return 'paypal.oauth_token.'.md5(implode('|', [
            config('paypal.client_id', ''),
            config('paypal.base_url', ''),
        ]));
    }

    /**
     * Convert an integer amount in centavos to PayPal's decimal amount format.
     */
    protected function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * Read a request header regardless of whether Laravel supplied a scalar or list.
     */
    protected function headerValue(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
