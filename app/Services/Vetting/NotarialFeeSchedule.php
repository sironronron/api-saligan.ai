<?php

namespace App\Services\Vetting;

use Illuminate\Support\Str;

/**
 * Matches a submitter's document type against the notarial fee schedule.
 *
 * The schedule lives in config/vetting.php and is keyed by category slug; the
 * document type on a request is free text, so each category carries the
 * keywords that identify it. Keyword hits win over the flat default, so
 * "Affidavit of Loss", "Sworn Statement", and "Affidavit" all land on the
 * Simple Affidavits rate without the submitter having to pick an exact label.
 */
final class NotarialFeeSchedule
{
    /**
     * The keyword list for each category slug, used to match free-text
     * document types.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'affidavit' => ['affidavit', 'sworn statement', 'sworn'],
        'spa' => ['special power of attorney', 'power of attorney', 'spa'],
        'deed' => ['deed of absolute sale', 'deed of sale', 'transfer of rights', 'transfer of ownership'],
        'lease' => ['contract of lease', 'lease agreement', 'lease'],
        'ctc' => ['certified true copy', 'certified copy', 'ctc'],
    ];

    /**
     * The rule for a document type, or null when it does not match any
     * category in the schedule.
     *
     * @return array{label: string, fee: int|null, percent: int|null, minimum: int|null}|null
     */
    public function ruleFor(string $documentType): ?array
    {
        $needle = Str::lower(trim($documentType));

        if ($needle === '') {
            return null;
        }

        foreach (self::KEYWORDS as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($needle, $keyword)) {
                    return config("vetting.notarial_fee_schedule.{$slug}");
                }
            }
        }

        return null;
    }

    /**
     * Whether a document type's fee depends on a property/contract value.
     */
    public function requiresValue(string $documentType): bool
    {
        $rule = $this->ruleFor($documentType);

        return $rule !== null && $rule['fee'] === null && ($rule['percent'] ?? null) !== null;
    }
}
