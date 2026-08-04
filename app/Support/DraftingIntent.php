<?php

namespace App\Support;

final class DraftingIntent
{
    /**
     * Determine whether a user message clearly asks to draft a legal document.
     */
    public static function matches(string $message): bool
    {
        $keywords = [
            'draft', 'prepare', 'reklamo', 'reklamasyon', 'complaint',
            'demand letter', 'contract', 'agreement', 'affidavit',
            'power of attorney', 'deed of sale', 'kasulatan',
            'legal document', 'position paper', 'pleading',
        ];

        $needle = mb_strtolower($message);

        foreach ($keywords as $keyword) {
            if (mb_strpos($needle, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the message is the intake form submission sent back to the AI.
     */
    public static function isIntakeSubmission(string $message): bool
    {
        return str_starts_with($message, '[Intake Form Submission]');
    }

    /**
     * Derive next-step todo items from a drafted document. Prefers the
     * document's own "Next Steps" section, then falls back to a generic set
     * matched to the document type.
     *
     * @return array<int, array{title: string, status: string}>
     */
    public static function fallbackTodos(string $text): array
    {
        $titles = self::extractStepsSection($text);

        if ($titles === []) {
            $titles = self::defaultTitlesFor($text);
        }

        $titles = array_values(array_unique($titles));

        return array_map(
            fn (string $title) => [
                'title' => mb_strimwidth($title, 0, 255, '…'),
                'status' => 'pending',
            ],
            $titles,
        );
    }

    /**
     * Extract the numbered items under a "Next Steps"-style heading.
     *
     * @return array<int, string>
     */
    private static function extractStepsSection(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?? [];
        $inSection = false;
        $items = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#+\s*.*?(?:next steps?|steps? to take|recommended steps?|action items?).*$/i', $trimmed)) {
                $inSection = true;

                continue;
            }

            if (! $inSection) {
                continue;
            }

            if (str_starts_with($trimmed, '[Export') || str_contains($trimmed, '/export/')) {
                break;
            }

            if (preg_match('/^\d+[.)]\s*(.+)$/', $trimmed, $matches)) {
                $items[] = self::cleanTitle($matches[1]);
            }
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private static function defaultTitlesFor(string $text): array
    {
        $needle = mb_strtoupper($text);

        return match (true) {
            str_contains($needle, 'UNLAWFUL DETAINER'), str_contains($needle, 'EJECTMENT'), str_contains($needle, 'ILLEGAL OCCUPATION') => [
                'File the complaint with the proper court',
                'Pay the filing and docket fees',
                'Attend the preliminary conference',
                'Prepare your evidence (title, tax declaration, photos, blotter)',
            ],
            str_contains($needle, 'DEMAND LETTER') => [
                'Finalize and serve the demand letter with proof of receipt',
                'Wait for the deadline to comply',
                'File the appropriate case if the demand is not complied with',
            ],
            str_contains($needle, 'POWER OF ATTORNEY') => [
                'Have the special power of attorney notarized',
                'Provide copies to the attorney-in-fact',
            ],
            str_contains($needle, 'DEED OF SALE') => [
                'Execute the deed before a notary public',
                'Pay the capital gains and documentary stamp taxes',
                'Register the sale with the Registry of Deeds',
                'Request the transfer of the title (TCT/CCT)',
            ],
            str_contains($needle, 'AFFIDAVIT') => [
                'Have the affidavit subscribed and sworn before a notary public',
                'Keep certified copies for your records',
            ],
            str_contains($needle, 'CONTRACT'), str_contains($needle, 'AGREEMENT') => [
                'Have the contract reviewed by counsel',
                'Execute and sign the agreement',
                'Notarize the agreement',
                'Keep certified copies for all parties',
            ],
            default => [
                'Review the draft with a licensed attorney',
                'Have the document notarized if required',
                'Keep copies of all supporting documents',
            ],
        };
    }

    private static function cleanTitle(string $title): string
    {
        $cleaned = preg_replace('/\*\*(.+?)\*\*/', '$1', $title);
        $cleaned = trim((string) $cleaned);

        return trim($cleaned, " \t\n\r*#");
    }

    /**
     * Default intake fields used when the model skips the mandatory intake
     * tool call for a drafting request.
     *
     * @return array<int, array{key: string, label: string, type: string, options?: array<int, string>, required: bool}>
     */
    public static function defaultFields(): array
    {
        return [
            [
                'key' => 'plaintiff_name',
                'label' => 'Your full name as it appears in legal documents',
                'type' => 'text',
                'required' => true,
            ],
            [
                'key' => 'plaintiff_address',
                'label' => 'Your complete address (barangay, city, province)',
                'type' => 'text',
                'required' => true,
            ],
            [
                'key' => 'defendant_name',
                'label' => "Defendant's full name",
                'type' => 'text',
                'required' => true,
            ],
            [
                'key' => 'defendant_address',
                'label' => "Defendant's complete address",
                'type' => 'text',
                'required' => true,
            ],
            [
                'key' => 'property_details',
                'label' => 'Property / claim details (location, boundaries)',
                'type' => 'textarea',
                'required' => true,
            ],
            [
                'key' => 'facts',
                'label' => 'Chronological account of what happened (dates, events)',
                'type' => 'textarea',
                'required' => true,
            ],
            [
                'key' => 'relief_sought',
                'label' => 'What you want the court to order (eviction, payment, damages, etc.)',
                'type' => 'textarea',
                'required' => true,
            ],
            [
                'key' => 'incident_date',
                'label' => 'When the violation or incident occurred',
                'type' => 'date',
                'required' => true,
            ],
            [
                'key' => 'evidence',
                'label' => 'Documents or proof you have (titles, contracts, photos, receipts)',
                'type' => 'textarea',
                'required' => false,
            ],
            [
                'key' => 'court_preference',
                'label' => 'Preferred forum',
                'type' => 'select',
                'options' => [
                    'Municipal Trial Court',
                    'Regional Trial Court',
                    'Barangay (Lupong Tagapamayapa)',
                    'Not sure',
                ],
                'required' => false,
            ],
        ];
    }
}
