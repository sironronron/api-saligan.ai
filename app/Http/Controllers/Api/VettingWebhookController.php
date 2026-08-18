<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\PaymongoClient;
use App\Services\Billing\VettingPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhooks for vetting/notarization payments. Signature-verified like the
 * subscription webhooks; the events match a payment intent back to its request
 * and advance the payment lifecycle.
 */
class VettingWebhookController extends Controller
{
    public function __construct(
        private readonly PaymongoClient $paymongo,
        private readonly VettingPaymentService $payments,
    ) {
        //
    }

    /**
     * Handle a PayMongo webhook for a vetting payment.
     */
    public function payments(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->paymongo->verifyWebhookSignature($request->headers->all(), $raw)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $attributes = $request->json('data.attributes', []);
        $eventType = $attributes['type'] ?? null;
        $eventData = $attributes['data'] ?? [];
        $eventAttributes = $eventData['attributes'] ?? [];

        $intentId = $eventAttributes['payment_intent_id'] ?? null;

        match ($eventType) {
            'payment.paid' => $this->payments->markAuthorized(
                (string) $intentId,
                (string) ($eventData['id'] ?? ''),
            ),
            'payment.failed' => $intentId !== null
                ? $this->payments->markFailed((string) $intentId)
                : null,
            'payment_refund.created', 'payment_refund.updated' => $intentId !== null
                ? $this->payments->markRefunded((string) $intentId, (string) ($eventData['id'] ?? null))
                : null,
            default => null,
        };

        return response()->json(['status' => 'ok'], 200);
    }
}
