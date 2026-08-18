<?php

namespace App\Services\Vetting;

use Illuminate\Support\Str;

/**
 * Classifies a submitter's free-text document type into the canonical slug the
 * lawyer matcher keys its practice-area map by.
 *
 * Vetting requests carry a free-text document type ("Deed of Absolute Sale",
 * "Affidavit of Loss", "Special Power of Attorney"), while lawyer matching is
 * keyed by category slugs. Each slug lists the keywords that identify it, so a
 * request lands on the practice areas that own it. An unknown type returns
 * null and matches every lawyer, mirroring the existing "other" behaviour.
 *
 * The same pattern as NotarialFeeSchedule, but for practice-area matching.
 */
final class DocumentTypeClassifier
{
    /**
     * The keyword list for each document-type slug, used to match free-text
     * document types. More specific slugs are listed before broad ones so
     * "Contract of Lease" lands on `lease`, not `contract`.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'lease' => ['contract of lease', 'lease agreement', 'lease'],
        'power_of_attorney' => ['special power of attorney', 'power of attorney', 'spa'],
        'deed' => [
            'deed of absolute sale',
            'deed of sale',
            'deed of donation',
            'deed',
            'transfer of rights',
            'transfer of ownership',
            'extrajudicial settlement',
            'extra-judicial settlement',
            'settlement',
        ],
        'affidavit' => ['affidavit', 'sworn statement', 'sworn'],
        'complaint' => ['complaint', 'reklamo'],
        'demand_letter' => ['demand letter', 'demand'],
        'government_letter' => ['government transaction letter', 'government letter', 'barangay', 'brgy'],
        'corporate' => ['board resolution', 'articles of incorporation', 'certificate of incorporation', 'minutes', 'corporate'],
        'contract' => ['contract to sell', 'contract', 'agreement', 'loan'],
    ];

    /**
     * The canonical slug for a free-text document type, or null when it does
     * not match any known category.
     */
    public function slugFor(string $documentType): ?string
    {
        $needle = Str::lower(trim($documentType));

        if ($needle === '') {
            return null;
        }

        if (array_key_exists($needle, self::KEYWORDS)) {
            return $needle;
        }

        foreach (self::KEYWORDS as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($needle, $keyword)) {
                    return $slug;
                }
            }
        }

        return null;
    }
}
