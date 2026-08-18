<?php

namespace App\Services\Vetting;

/**
 * Computes the fees a submitter sees before confirming a vetting/notarization
 * request. Fees are held on the request at creation so the lawyer is never
 * paid a different amount than what the submitter agreed to.
 *
 * Notarization always carries its own fee — either a flat rate from the
 * notarial schedule or a percentage of the property/contract value — and every
 * request carries a flat vetting fee. The PayMongo processing fee is passed
 * through to the buyer, so the platform nets the requested service fee after
 * the gateway takes its cut.
 */
final class VettingFees
{
    public function __construct(
        private readonly VettingSettings $settings,
        private readonly NotarialFeeSchedule $schedule,
    ) {
        //
    }

    /**
     * The fee breakdown for a service type.
     *
     * @param  string  $serviceType  'vetting' or 'notarization'.
     * @param  int|null  $propertyValue  Property/contract value in centavos,
     *                                   for percentage-based document types.
     * @return array{vetting_fee: int, notarization_fee: int, processing_fee: int, total: int}
     */
    public function compute(string $serviceType, string $documentType, ?int $propertyValue): array
    {
        $overrides = $this->settings->feeOverrides();

        $vettingFee = (int) ($overrides[$documentType]['vetting_fee']
            ?? $this->settings->vettingFee());

        $notarizationFee = $serviceType === 'vetting'
            ? 0
            : $this->notarizationFee($documentType, $propertyValue, $overrides);

        $serviceTotal = $vettingFee + $notarizationFee;
        $processingFee = $this->processingFee($serviceTotal);

        return [
            'vetting_fee' => $vettingFee,
            'notarization_fee' => $notarizationFee,
            'processing_fee' => $processingFee,
            'total' => $serviceTotal + $processingFee,
        ];
    }

    /**
     * The notarization fee for a document type: a percentage of the declared
     * property/contract value (never below the schedule minimum) for deeds and
     * leases, or a flat rate for everything else. An admin override for the
     * exact document type always wins.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function notarizationFee(string $documentType, ?int $propertyValue, array $overrides): int
    {
        $override = $overrides[$documentType]['notarization_fee'] ?? null;

        if ($override !== null) {
            return (int) $override;
        }

        $rule = $this->schedule->ruleFor($documentType);

        if ($rule === null) {
            return $this->settings->notarizationFee();
        }

        if ($rule['percent'] !== null) {
            $percentFee = (int) round(($propertyValue ?? 0) * $rule['percent'] / 100);

            return max($rule['minimum'] ?? 0, $percentFee);
        }

        return (int) $rule['fee'];
    }

    /**
     * The PayMongo processing fee passed on to the buyer. The amount charged
     * is grossed up so the service fee survives the gateway's percentage and
     * fixed fee: charged = (service + fixed) / (1 - percent/100).
     */
    protected function processingFee(int $serviceTotal): int
    {
        if ($serviceTotal <= 0) {
            return 0;
        }

        $percent = (float) config('paymongo.processing_fee_percent', 3.5);
        $fixed = (int) config('paymongo.processing_fee_fixed', 1500);

        $charged = (int) ceil(($serviceTotal + $fixed) / (1 - $percent / 100));

        return max(0, $charged - $serviceTotal);
    }
}
