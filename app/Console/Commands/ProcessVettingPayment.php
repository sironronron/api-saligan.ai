<?php

namespace App\Console\Commands;

use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use App\Services\Billing\PaymongoClient;
use App\Services\Billing\VettingPaymentService;
use Illuminate\Console\Command;

class ProcessVettingPayment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vetting:payment-process
        {payment-intent-id? : The PayMongo payment intent id (pi_...) from the dashboard}
        {payment-id? : The PayMongo payment id (pay_...) from the dashboard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Authorize a vetting payment as if the webhook had fired (for local testing)';

    /**
     * Execute the console command.
     */
    public function handle(PaymongoClient $paymongo, VettingPaymentService $payments): int
    {
        $paymentIntentId = $this->clean($this->argument('payment-intent-id'));
        $paymentId = $this->clean($this->argument('payment-id'));

        if ($paymentIntentId === null && $paymentId === null) {
            $this->error('Provide a payment intent id, a payment id, or both.');

            return self::FAILURE;
        }

        [$request, $gatewayPaymentId] = $this->resolveRequest($paymongo, $payments, $paymentIntentId, $paymentId);

        if ($request === null) {
            $this->error('No vetting request found for the given payment id or payment intent id.');

            return self::FAILURE;
        }

        $status = $request->payment_status;

        if ($status === VettingPaymentStatus::Authorized || $status === VettingPaymentStatus::Captured) {
            $this->info("Payment for request #{$request->id} is already {$status->value}.");

            return self::SUCCESS;
        }

        // Key the authorization off the request's own intent so its payment
        // row is found, rather than a possibly-different id the gateway
        // returned for a re-created checkout.
        $intentId = $request->gateway_payment_intent_id ?? $paymentIntentId ?? $paymentId;

        $payments->markAuthorized($intentId, $gatewayPaymentId);

        $request->refresh();

        $this->info("Payment authorized for request #{$request->id} [{$request->document_type}].");
        $this->info("Request status: {$request->status->label()}. Lawyer matching has been dispatched.");

        return self::SUCCESS;
    }

    /**
     * Find the vetting request behind a payment intent id, a payment id, or
     * both. A payment id that is already stored on a payment row, or that the
     * gateway resolves to a tracked intent, matches the request directly.
     *
     * @return array{0: VettingRequest|null, 1: string|null}
     */
    protected function resolveRequest(
        PaymongoClient $paymongo,
        VettingPaymentService $payments,
        ?string $paymentIntentId,
        ?string $paymentId,
    ): array {
        // The local request is keyed on the payment intent, so a pi_ id matches directly.
        if ($paymentIntentId !== null) {
            $request = $payments->requestByIntent($paymentIntentId);

            if ($request !== null) {
                return [$request, $paymentId];
            }
        }

        if ($paymentId !== null) {
            // A pay_ id may already be stored on a payment row after a webhook.
            $payment = VettingPayment::query()
                ->where('gateway_payment_id', $paymentId)
                ->latest('id')
                ->first();

            if ($payment !== null && $payment->vettingRequest !== null) {
                return [$payment->vettingRequest, $paymentId];
            }

            // Otherwise ask the gateway which intent the payment belongs to.
            [$intentId, $gatewayPaymentId] = $this->resolvePayment($paymongo, $paymentId);

            if ($intentId !== null) {
                $request = $payments->requestByIntent($intentId);

                if ($request !== null) {
                    return [$request, $gatewayPaymentId ?? $paymentId];
                }
            }
        }

        return $this->resolveAwaitingRequest($paymentId ?? $paymentIntentId ?? '');
    }

    /**
     * Local testing fallback: the gateway id may belong to a checkout that was
     * re-created (or a different sandbox key), so it matches nothing tracked.
     * When exactly one request is awaiting payment, take it so the flow keeps
     * moving; this never runs in production.
     *
     * @return array{0: VettingRequest|null, 1: string|null}
     */
    protected function resolveAwaitingRequest(string $identifier): array
    {
        if (app()->environment('production')) {
            return [null, null];
        }

        $awaiting = VettingRequest::query()
            ->where('status', VettingRequestStatus::PaymentPending)
            ->where('payment_status', '!=', VettingPaymentStatus::Authorized->value)
            ->get();

        if ($awaiting->count() === 1) {
            $request = $awaiting->first();

            $this->warn("Gateway id [{$identifier}] matched no tracked request; marking request #{$request->id} as paid for local testing.");

            return [$request, $identifier];
        }

        if ($awaiting->count() > 1) {
            $this->error('Several requests are awaiting payment — paste the payment intent id for the one you paid for.');

            return [null, null];
        }

        return [null, null];
    }

    /**
     * Resolve a PayMongo payment id (`pay_…`) or payment intent id (`pi_…`)
     * into the intent id the local request row is keyed on, plus the gateway's
     * payment id where it is known.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function resolvePayment(PaymongoClient $paymongo, string $identifier): array
    {
        if (str_starts_with($identifier, 'pay_')) {
            $payment = $paymongo->retrievePayment($identifier);

            $paymentStatus = data_get($payment, 'attributes.status');

            if ($paymentStatus !== null && $paymentStatus !== 'paid') {
                $this->warn("Payment [{$identifier}] has status [{$paymentStatus}], not 'paid'.");
            }

            return [
                (string) data_get($payment, 'attributes.payment_intent_id') ?: null,
                $identifier,
            ];
        }

        if (str_starts_with($identifier, 'pi_')) {
            $intent = $paymongo->retrievePaymentIntent($identifier);

            return [
                $identifier,
                (string) data_get($intent, 'attributes.payments.0.id') ?: null,
            ];
        }

        return [null, null];
    }

    /**
     * The trimmed argument value, or null when the argument was not given.
     */
    protected function clean(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
