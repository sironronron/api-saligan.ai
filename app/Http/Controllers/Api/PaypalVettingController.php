<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VettingRequest;
use App\Services\Billing\VettingPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PaypalVettingController extends Controller
{
    public function __construct(
        private readonly VettingPaymentService $payments,
    ) {
        //
    }

    /**
     * Authorize the order after PayPal sends the buyer back to Batayan.
     */
    public function return(Request $request): RedirectResponse
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && trim($token) !== '', 404, 'PayPal order not found.');

        $vettingRequest = $this->payments->authorizePaypalOrder(trim($token));
        abort_if($vettingRequest === null, 404, 'PayPal order not found.');

        return redirect()->away($this->frontendUrl(
            $vettingRequest->id,
            'return',
        ));
    }

    /**
     * Return the buyer to the request without changing a still-pending order.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $token = $request->query('token');
        $requestId = $request->query('request');
        $vettingRequest = is_string($token) && trim($token) !== ''
            ? $this->payments->requestByIntent(trim($token))
            : (is_string($requestId) && trim($requestId) !== ''
                ? VettingRequest::query()->whereKey($requestId)->first()
                : null);

        abort_if($vettingRequest === null, 404, 'Vetting request not found.');

        return redirect()->away($this->frontendUrl($vettingRequest->id, 'cancelled'));
    }

    protected function frontendUrl(?string $requestId, string $paymentState): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url'), '/');
        $path = $requestId === null ? '/vetting' : "/vetting/{$requestId}";

        return "{$baseUrl}{$path}?payment={$paymentState}";
    }
}
