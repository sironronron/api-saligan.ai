<?php

namespace App\Support;

final class DraftingIntent
{
    /**
     * Determine whether a user message clearly asks to draft a legal document.
     */
    public static function matches(string $message): bool
    {
        if (self::hasTemplateDirective($message)) {
            return true;
        }

        $keywords = [
            'draft', 'prepare', 'compose', 'write', 'letter',
            'reklamo', 'reklamasyon', 'complaint', 'demand letter',
            'contract', 'agreement', 'affidavit', 'power of attorney',
            'deed of sale', 'kasulatan', 'legal document', 'position paper',
            'pleading', 'petition', 'request', 'requesting', 'certified copy',
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
     * Whether the message carries a template picker directive, e.g.
     * "[Template: demand_letter]" or "[Template: <uuid>]".
     */
    public static function hasTemplateDirective(string $message): bool
    {
        return preg_match('/^\[Template:\s*[^\]]+\]/', $message) === 1;
    }

    /**
     * Strip a leading template directive, returning the template token and
     * the remaining instruction text.
     *
     * @return array{0: string|null, 1: string}
     */
    public static function extractTemplateDirective(string $message): array
    {
        if (preg_match('/^\[Template:\s*([^\]]+)\]\s*\n?(.*)$/s', $message, $matches)) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [null, $message];
    }

    /**
     * Whether the message is the intake form submission sent back to the AI.
     */
    public static function isIntakeSubmission(string $message): bool
    {
        return str_starts_with($message, '[Intake Form Submission]');
    }

    /**
     * Whether the user explicitly asked to export, download, or save a
     * document as Word/PDF. Export links are only emitted for these requests.
     */
    public static function requestsExport(string $message): bool
    {
        $needle = mb_strtolower($message);

        $keywords = [
            'export', 'download', 'save as', 'save this', 'save the document',
            'to word', 'as word', 'word document', 'docx',
            'to pdf', 'as pdf', 'pdf document', '.pdf',
            'send me the file', 'convert to word', 'convert to pdf',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($needle, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match any markdown download/export link the model may have written —
     * both the sanctioned export links and fabricated ones (e.g. placeholder
     * domains like example.com) — so only the real export links persist.
     */
    public static function exportLinkPattern(): string
    {
        return '#\s*\[[^\]]*(?:download|export)[^\]]*\]\((?:https?://|\/)[^)]*\)#i';
    }

    /**
     * Match placeholder export labels the model wrote instead of real links,
     * e.g. "EXPORT LINKS: [Word Document Download Link] | [PDF Exported Version]."
     * Real export links are always appended by the server, so any such
     * placeholder text the model produced is removed before persisting.
     */
    public static function exportPlaceholderPattern(): string
    {
        return '#(?:export\s+links?\s*:?\s*\[[^\]]+\]\s*(?:\|\s*\[[^\]]+\]\s*)*[.:;]?|\[[^\]]*(?:download|word document|exported|pdf)[^\]]*\]\s*(?:\|\s*\[[^\]]+\]\s*)*)[.:;]?[\r\n]*#i';
    }

    /**
     * Match a standalone "Export Links:" / "Download Links:" label line the
     * model wrote ahead of links it should not have produced, e.g.
     * "**Export Links:**". The real links are appended by the server, so the
     * leftover label is dropped.
     */
    public static function exportLabelPattern(): string
    {
        return '#(?m)^[ \t]*(?:\*\*)?\s*(?:export|download)\s+links?\s*(?:\*\*)?\s*:?\s*$#i';
    }

    /**
     * Remove any export/download links or placeholder labels the model wrote so
     * only the real export links appended by the server are ever surfaced.
     */
    public static function stripExportLinks(string $text): string
    {
        $stripped = preg_replace(self::exportLinkPattern(), '', $text);
        $stripped = preg_replace(self::exportLabelPattern(), '', $stripped);
        $stripped = preg_replace(self::exportPlaceholderPattern(), '', $stripped);

        return rtrim((string) $stripped);
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
     * Extract the items under a next-steps heading. Handles numbered,
     * bulleted, bold-led ("**Label**: detail"), and plain label-detail
     * ("Label: detail") items, across common heading wordings ("Next Steps",
     * "Checklist", "What to do", etc.).
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

            if (self::isStepHeading($trimmed)) {
                $inSection = true;

                continue;
            }

            if (! $inSection) {
                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            if (self::startsNewSection($trimmed)) {
                break;
            }

            if (str_starts_with($trimmed, '[Export') || str_contains($trimmed, '/export/')) {
                break;
            }

            $item = self::extractStepItem($trimmed);

            if ($item !== null) {
                $items[] = self::cleanTitle($item);
            }
        }

        return $items;
    }

    /**
     * Whether the line closes the steps section and opens a new one, e.g. a
     * markdown heading, the Sources block, or a bold standalone heading. This
     * keeps later sections (Sources, Witnesses, Attachments) from being
     * mistaken for to-do items.
     */
    private static function startsNewSection(string $line): bool
    {
        if (preg_match('/^#{1,6}\s+/', $line) === 1) {
            return true;
        }

        if (preg_match('/^sources?\s*:?\s*$/i', $line) === 1) {
            return true;
        }

        if (preg_match('/^\*\*(?:sources?|next steps?|attachments?|enclosures?)\s*\*+:?\s*$/i', $line) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Whether the line reads like a next-steps heading.
     */
    private static function isStepHeading(string $line): bool
    {
        if (preg_match('/^(?:#+\s*)?\*?(next steps?|steps? to take|recommended steps?|action items?|checklist|immediate steps?|next actions?|steps? to follow|what to do)/i', $line) !== 1) {
            return false;
        }

        return mb_strlen($line) <= 100;
    }

    /**
     * Extract a single checklist item from a line, or null when the line is
     * not an item (e.g. an introductory sentence ending in a colon).
     */
    private static function extractStepItem(string $line): ?string
    {
        if (preg_match('/^\d+[.)]\s*(.+)$/', $line, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^[-*]\s*(?!\*)(.+)$/', $line, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^\*\*(.+?)\*\*:?\s*(.*)$/', $line, $matches)) {
            $detail = trim($matches[2]);

            return filled($detail) ? $matches[1].': '.$detail : $matches[1];
        }

        // Label: detail lines such as "Gather Witnesses: Ask your neighbors…"
        if (preg_match('/^[A-Z0-9][^\n]*?:.+/', $line) === 1 && str_ends_with($line, ':') === false) {
            return $line;
        }

        // A plain sentence-length item (not a short "To proceed, do this:" intro).
        if (! str_ends_with($line, ':') && mb_strlen($line) > 12 && str_contains($line, ' ')) {
            return $line;
        }

        return null;
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

    /**
     * Guess the document category from a drafting request so the synthetic
     * intake form can show the right fields when the model skipped the tool.
     *
     * @return string|null One of the categories understood by fieldsForDocumentType()
     */
    public static function documentTypeFor(string $message): ?string
    {
        $needle = mb_strtolower($message);

        if (self::matchesAnyWord($needle, [
            'government transaction', 'agency', 'dar', 'denr', 'lra', 'bir',
            'registry of deeds', 'certified copy', 'clearance', 'certification',
            'transcript of records', 'appeal', 'protest', 'motion for reconsideration',
            'application for', 'request a',
        ])) {
            return 'government transaction letter';
        }

        if (self::matchesAnyWord($needle, [
            'demand', 'notice of', 'reply to', 'letter to', 'letter for',
            'formal letter', 'letter of',
        ])) {
            return 'formal letter';
        }

        if (self::matchesAnyWord($needle, ['deed', 'kasulatan', 'donation', 'assignment', 'conveyance', 'sale of'])) {
            return 'deed';
        }

        if (self::matchesAnyWord($needle, ['affidavit', 'sinumpaan', 'sworn statement'])) {
            return 'affidavit';
        }

        if (self::matchesAnyWord($needle, ['power of attorney', 'poa'])) {
            return 'special power of attorney';
        }

        if (self::matchesAnyWord($needle, ['agreement', 'contract', 'lease', 'tenancy', 'usufruct', 'mortgage', 'partnership'])) {
            return 'agreement';
        }

        if (self::matchesAnyWord($needle, ['reklamo', 'reklamasyon', 'complaint', 'ejectment', 'detainer'])) {
            return 'complaint';
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private static function matchesAnyWord(string $needle, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $needle) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * General intake field sets keyed by document category. Templates carry
     * their own fields (placeholder_fields) which take precedence; this map
     * covers drafting requests with no resolved template so the form still
     * asks for what the specific document needs instead of a generic
     * complaint-oriented set.
     *
     * @return array<int, array{key: string, label: string, type: string, options?: array<int, string>, required: bool}>
     */
    public static function fieldsForDocumentType(?string $documentType): array
    {
        $type = mb_strtolower((string) $documentType);

        return match (true) {
            self::containsAny($type, ['government transaction', 'government', 'agency', 'dar', 'denr', 'lra', 'bir', 'registry of deeds', 'application', 'request', 'appeal', 'protest', 'motion for reconsideration', 'certified copy', 'clearance', 'certification', 'transcript']) => self::governmentTransactionFields(),
            self::containsAny($type, ['formal letter', 'demand', 'notice', 'reply', 'letter']) => self::formalLetterFields(),
            self::containsAny($type, ['agreement', 'contract', 'lease', 'tenancy', 'usufruct', 'mortgage', 'partnership']) => self::agreementFields(),
            self::containsAny($type, ['deed', 'sale', 'donation', 'assignment', 'conveyance', 'kasulatan']) => self::deedFields(),
            self::containsAny($type, ['affidavit', 'sinumpaan', 'sworn statement']) => self::affidavitFields(),
            self::containsAny($type, ['power of attorney', 'poa']) => self::powerOfAttorneyFields(),
            self::containsAny($type, ['complaint', 'reklamo', 'reklamasyon', 'ejectment', 'detainer']) => self::defaultFields(),
            default => self::defaultFields(),
        };
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private static function containsAny(string $needle, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($needle, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fields for submissions to a government office (DAR, DENR, LRA, Registry
     * of Deeds, BIR, LGU, etc.): applications, requests for documents,
     * appeals, protests, and motions for reconsideration.
     *
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool}>
     */
    private static function governmentTransactionFields(): array
    {
        return [
            [
                'key' => 'sender_name',
                'label' => 'Your full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'sender_address',
                'label' => 'Your complete address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'agency_name',
                'label' => 'Agency or office (e.g. DAR Provincial Office, Registry of Deeds)',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'agency_office_or_officer',
                'label' => 'Specific office or officer, if known',
                'type' => 'text',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'transaction_type',
                'label' => 'Type of submission',
                'type' => 'select',
                'options' => [
                    'Application',
                    'Request for Certification/Document',
                    'Appeal',
                    'Protest',
                    'Motion for Reconsideration',
                    'Compliance Submission',
                    'Other',
                ],
                'required' => true,
            ],
            [
                'key' => 'subject_matter',
                'label' => 'What you are applying for, requesting, appealing, or protesting',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'reference_number',
                'label' => 'Reference/document number if known (e.g. CLOA No., TCT/CCT No., case No.)',
                'type' => 'text',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'legal_basis',
                'label' => 'Law or regulation being invoked (if known)',
                'type' => 'text',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'facts',
                'label' => 'Relevant circumstances (dates, documents, ownership)',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'relief_or_action_sought',
                'label' => 'What the agency should do',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'attachments',
                'label' => 'Supporting documents you will enclose',
                'type' => 'textarea',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'deadline_or_reglementary_period',
                'label' => 'Filing or appeal deadline, if any',
                'type' => 'date',
                'options' => [],
                'required' => false,
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool}>
     */
    private static function formalLetterFields(): array
    {
        return [
            [
                'key' => 'sender_name',
                'label' => 'Your full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'sender_address',
                'label' => 'Your complete address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'recipient_name',
                'label' => "Recipient's full name",
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'recipient_address',
                'label' => "Recipient's complete address",
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'subject',
                'label' => 'Subject of the letter',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'facts',
                'label' => 'What happened and why the letter is being sent',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'request_or_demand',
                'label' => 'What the recipient should do',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'legal_basis',
                'label' => 'Law, contract provision, or agreement relied on (if known)',
                'type' => 'text',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'deadline',
                'label' => 'Response or compliance deadline, if any',
                'type' => 'date',
                'options' => [],
                'required' => false,
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool}>
     */
    private static function agreementFields(): array
    {
        return [
            [
                'key' => 'party_a_name',
                'label' => 'First party (Party A) full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'party_a_address',
                'label' => 'First party (Party A) address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'party_b_name',
                'label' => 'Second party (Party B) full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'party_b_address',
                'label' => 'Second party (Party B) address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'transaction_type',
                'label' => 'Type of transaction (e.g. agricultural lease, land sale, farm services, mortgage)',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'property_or_subject',
                'label' => 'Land/property or service involved (location, area)',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'amount',
                'label' => 'Price, rent, share, or consideration',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'term',
                'label' => 'Duration or start/end dates',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'obligations',
                'label' => 'Obligations and duties of each party',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'special_clauses',
                'label' => 'Special clauses (penalties, renewal, termination, sharing)',
                'type' => 'textarea',
                'options' => [],
                'required' => false,
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool}>
     */
    private static function deedFields(): array
    {
        return [
            [
                'key' => 'vendor_or_donor_name',
                'label' => 'Vendor/donor full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'vendor_or_donor_address',
                'label' => 'Vendor/donor address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'vendee_or_donee_name',
                'label' => 'Vendee/donee full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'vendee_or_donee_address',
                'label' => 'Vendee/donee address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'property_description',
                'label' => 'Property description (location, area, boundaries, title/tax declaration number)',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'consideration',
                'label' => 'Consideration (price/value in words and figures, or state if gratuitous)',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'payment_terms',
                'label' => 'Payment terms, if applicable',
                'type' => 'textarea',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'title_or_tax_dec_number',
                'label' => 'TCT/CCT/OCT or tax declaration number, if known',
                'type' => 'text',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'encumbrances_or_restrictions',
                'label' => 'Encumbrances or restrictions (agrarian reform coverage, liens)',
                'type' => 'textarea',
                'options' => [],
                'required' => false,
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool}>
     */
    private static function affidavitFields(): array
    {
        return [
            [
                'key' => 'affiant_name',
                'label' => 'Affiant (person making the statement) full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'affiant_address',
                'label' => 'Affiant address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'affiant_occupation',
                'label' => 'Affiant occupation',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'statement_facts',
                'label' => 'The facts being sworn to',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'purpose',
                'label' => 'What the affidavit is for',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'date',
                'label' => 'Date of execution',
                'type' => 'date',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'place_of_execution',
                'label' => 'Place of execution (city/municipality)',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool}>
     */
    private static function powerOfAttorneyFields(): array
    {
        return [
            [
                'key' => 'principal_name',
                'label' => 'Principal (grantor) full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'principal_address',
                'label' => 'Principal address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'attorney_name',
                'label' => 'Attorney-in-fact (grantee) full name',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'attorney_address',
                'label' => 'Attorney-in-fact address',
                'type' => 'text',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'powers',
                'label' => 'Specific acts the attorney may perform',
                'type' => 'textarea',
                'options' => [],
                'required' => true,
            ],
            [
                'key' => 'transaction_details',
                'label' => 'Property/transaction involved, if any',
                'type' => 'textarea',
                'options' => [],
                'required' => false,
            ],
        ];
    }
}
