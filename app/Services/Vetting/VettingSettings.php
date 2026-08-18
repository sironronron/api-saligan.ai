<?php

namespace App\Services\Vetting;

use App\Models\PlatformSetting;

/**
 * Runtime configuration for the lawyer-vetting marketplace. Admins edit fees
 * and the commission split from the admin panel; these values are persisted in
 * platform_settings and fall back to config/vetting.php before anything has
 * been saved.
 */
final class VettingSettings
{
    public const KEY_FEES = 'vetting.fees';

    public const KEY_RULES = 'vetting.rules';

    /**
     * The flat fee charged for notarization, in centavos.
     */
    public function notarizationFee(): int
    {
        return (int) ($this->fees()['notarization_fee']
            ?? config('vetting.default_notarization_fee'));
    }

    /**
     * The flat fee charged for vetting alone, in centavos (usually zero).
     */
    public function vettingFee(): int
    {
        return (int) ($this->fees()['vetting_fee']
            ?? config('vetting.default_vetting_fee'));
    }

    /**
     * Optional per-document-type fee overrides, keyed by document type slug.
     *
     * @return array<string, int>
     */
    public function feeOverrides(): array
    {
        return $this->fees()['overrides'] ?? [];
    }

    /**
     * The platform's commission percentage on each notarization.
     */
    public function commissionPercent(): float
    {
        return (float) ($this->rules()['commission_percent']
            ?? config('vetting.platform_commission_percent'));
    }

    /**
     * How long a notified lawyer has before the request escalates, in hours.
     */
    public function escalationHours(): int
    {
        return (int) ($this->rules()['escalation_hours']
            ?? config('vetting.escalation_hours'));
    }

    /**
     * The ceiling on concurrent assignments a profile may claim.
     */
    public function maxConcurrentAssignments(): int
    {
        return (int) ($this->rules()['max_concurrent_assignments']
            ?? config('vetting.max_concurrent_assignments'));
    }

    /**
     * How many lawyers are offered a request at once before escalation brings
     * in the next batch.
     */
    public function matchPoolSize(): int
    {
        return (int) ($this->rules()['match_pool_size']
            ?? config('vetting.match_pool_size', 3));
    }

    /**
     * All fee values, as saved.
     *
     * @return array<string, mixed>
     */
    public function fees(): array
    {
        $fees = PlatformSetting::get(self::KEY_FEES, []);

        return is_array($fees) ? $fees : [];
    }

    /**
     * All rule values, as saved.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = PlatformSetting::get(self::KEY_RULES, []);

        return is_array($rules) ? $rules : [];
    }

    /**
     * Persist the fee configuration.
     *
     * @param  array<string, mixed>  $fees
     */
    public function saveFees(array $fees): void
    {
        PlatformSetting::set(self::KEY_FEES, $fees);
    }

    /**
     * Persist the operational rules.
     *
     * @param  array<string, mixed>  $rules
     */
    public function saveRules(array $rules): void
    {
        PlatformSetting::set(self::KEY_RULES, $rules);
    }
}
