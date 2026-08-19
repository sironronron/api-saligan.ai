<?php

namespace App\Support;

use Illuminate\Support\Str;

final class DraftingIntent
{
    /**
     * The marker a model reply must open with when it needs facts and writes
     * its questions out in chat instead of completing the draft. Everything
     * after the marker line is converted into intake form fields, so the
     * questions the user answers are exactly the ones the model asked for.
     */
    public const NEED_INFO_MARKER = '[[NEED_INFO]]';

    /**
     * The marker that closes the question block. Without it the block ran to
     * the end of the reply, so the model's closing prose ("Once you answer
     * these, I will draft the deed.") was collected as if it were a fact to
     * fill in. Both spellings are accepted because the model produces either.
     */
    public const NEED_INFO_END_MARKER = '[[/NEED_INFO]]';

    /**
     * Matches either closing spelling: [[/NEED_INFO]] or [[NEED_INFO_END]].
     */
    private const NEED_INFO_END_PATTERN = '/\[\[\s*(?:\/\s*NEED_INFO|NEED_INFO_END)\s*\]\]/i';

    /**
     * Matches a standalone TODO block marker line. The canonical form is
     * [[TODO_START]] / [[TODO_END]], but the model occasionally bolds it
     * ("**[TODO_START]**"), drops to single brackets ("[TODO_START]"), or
     * prefixes it with a list dash ("-[TODO_END]"), so all of those are
     * recognized rather than silently ignored.
     */
    public const TODO_MARKER_PATTERN = '/^[\s*_\-–—~]*\[{1,2}TODO_(START|END)\]{1,2}[\s*_\-–—~]*$/i';

    /**
     * Canonical intake field definitions. Each key is the single source of
     * truth for a fact's human-readable label, input type, and optional
     * grouping or conditional visibility. Bracket placeholders that match a
     * canonical concept collapse onto these keys so a fact is never collected
     * twice under a differently worded name.
     *
     * @var array<string, array{label: string, type: string, section?: string, conditional?: array{field: string, values: array<int, string>}}>
     */
    private const CANONICAL_FIELDS = [
        'sender_name' => ['label' => 'Your full name', 'type' => 'text'],
        'sender_address' => ['label' => 'Your complete address', 'type' => 'text'],
        'email' => ['label' => 'Email address', 'type' => 'text', 'section' => 'Contact Information'],
        'contact_number' => ['label' => 'Contact number', 'type' => 'text', 'section' => 'Contact Information'],
        'recipient_name' => ['label' => "Recipient's full name", 'type' => 'text'],
        'recipient_address' => ['label' => "Recipient's complete address", 'type' => 'text'],
        'complainant_name' => ['label' => "Complainant's full name", 'type' => 'text'],
        'complainant_address' => ['label' => "Complainant's complete address", 'type' => 'text'],
        'respondent_name' => ['label' => "Respondent's full name", 'type' => 'text'],
        'respondent_address' => ['label' => "Respondent's complete address", 'type' => 'text'],
        'reference_number' => [
            'label' => 'Reference / document number (e.g. CLOA No., TCT/CCT No., case No.)',
            'type' => 'text',
            'conditional' => [
                'field' => 'transaction_type',
                'values' => ['Request for Certification/Document', 'Appeal', 'Protest', 'Motion for Reconsideration', 'Compliance Submission'],
            ],
        ],
        'title_or_tax_dec_number' => ['label' => 'TCT/CCT/OCT or tax declaration number', 'type' => 'text'],
        'deceased_name' => [
            'label' => "Deceased's full name",
            'type' => 'text',
            'conditional' => ['field' => 'transaction_type', 'values' => ['Request for Certification/Document']],
        ],
        'date_of_death' => [
            'label' => 'Date of death',
            'type' => 'date',
            'conditional' => ['field' => 'transaction_type', 'values' => ['Request for Certification/Document']],
        ],
        'incident_date' => ['label' => 'When the violation or incident occurred', 'type' => 'date'],
        'facts' => ['label' => 'Statement of facts', 'type' => 'textarea'],
        'dates' => ['label' => 'Relevant date(s)', 'type' => 'text'],
        'date' => ['label' => 'Date', 'type' => 'date'],
        'deadline' => [
            'label' => 'Deadline to comply (if any)',
            'type' => 'date',
            'conditional' => ['field' => 'transaction_type', 'values' => ['Appeal', 'Protest', 'Motion for Reconsideration', 'Compliance Submission']],
        ],
        'relief_sought' => ['label' => 'Requested relief / action', 'type' => 'textarea'],
        'request_or_demand' => ['label' => 'What the recipient should do', 'type' => 'textarea'],
    ];

    /**
     * Placeholder phrasings that resolve to a canonical field key. Matching is
     * containment-based and longest-phrase-first, so "date of death" wins over
     * "date" and "complainant full name" wins over "full name". Phrases cover
     * the variants the model actually writes in drafts (e.g. "[Insert CLOA
     * Number If Available On PSA Death Cert Or Other Records]").
     *
     * @var array<string, array<int, string>>
     */
    private const CANONICAL_SYNONYMS = [
        'sender_name' => [
            'your printed full name', 'printed full name', 'signature over printed name',
            'your full name', 'sender full name', 'sender name', 'signatory name',
            'printed name', 'signature line', 'signature', 'your name', 'full name',
            'name of sender', "sender's full name",
        ],
        'sender_address' => [
            'your complete address', 'your full address', 'your address',
            'sender address', 'complete address', 'full address', 'address of sender',
            "sender's address",
        ],
        'email' => ['email address', 'e-mail address', 'electronic mail address', 'email', 'e-mail'],
        'contact_number' => [
            'contact number', 'contact no', 'phone number', 'telephone number',
            'mobile number', 'mobile no',
        ],
        'recipient_name' => [
            'recipient full name', "recipient's full name", 'recipient name',
            "recipient's name", 'name of recipient', 'addressee name', 'addressee',
        ],
        'recipient_address' => ['recipient address', "recipient's address", 'addressee address'],
        'complainant_name' => [
            'complainant full name', "complainant's full name", 'complainant name',
            "complainant's name", 'plaintiff full name', 'plaintiff name', "plaintiff's name",
        ],
        'complainant_address' => ['complainant address', "complainant's address", 'plaintiff address'],
        'respondent_name' => [
            'respondent full name', "respondent's full name", 'respondent name',
            "respondent's name", 'defendant full name', 'defendant name', "defendant's name",
        ],
        'respondent_address' => ['respondent address', "respondent's address", 'defendant address'],
        'reference_number' => [
            'number if known otherwise see attached details', 'insert cloa number if known',
            'cloa number if available', 'cloa number if known', 'insert number if known',
            'cloa number', 'cloa no', 'reference number', 'reference no', 'ref number',
            'number if known', 'insert number', 'case number', 'case no', 'document number',
            'docket number', 'docket no', 'application number',
        ],
        'title_or_tax_dec_number' => [
            'title number', 'tax declaration number', 'tax dec number', 'tct number',
            'tct no', 'cct number', 'cct no', 'oct number', 'oct no',
        ],
        'deceased_name' => [
            "father's full name", "deceased's full name", 'deceased full name',
            'name of deceased', "deceased's name", 'deceased name', 'late father name',
            'decedent name', 'decedent full name',
        ],
        'date_of_death' => ['date of death', 'death date', 'date of demise'],
        'incident_date' => ['incident date', 'date of incident', 'date of violation', 'date of occurrence'],
        'facts' => [
            'statement of facts', 'narration of facts', 'chronological account',
            'facts of the case', 'facts of the matter', 'narrative of facts', 'facts',
        ],
        'dates' => ['relevant dates', 'relevant date', 'dates'],
        'date' => [
            'date of execution', 'date of the letter', 'letter date', 'today date',
            'current date', 'date of letter', 'date',
        ],
        'deadline' => [
            'deadline to comply', 'compliance deadline', 'response deadline',
            'filing deadline', 'filing or appeal deadline', 'deadline or reglementary period',
            'deadline',
        ],
        'relief_sought' => [
            'relief or action sought', 'requested relief or action', 'prayer for relief',
            'requested relief', 'relief sought', 'action sought',
        ],
        'request_or_demand' => ['request or demand', 'what the recipient should do', 'demand'],
    ];

    /**
     * Determine whether a user message clearly asks to draft a legal document.
     *
     * An explicit drafting directive (draft, prepare, write, gumawa, ...)
     * always counts, even when the request is phrased as a question ("Can you
     * draft a demand letter?"). Informational questions about legal options
     * ("Is there any way I can request compensation...?") are advice, not
     * drafting instructions, so they never trigger the intake gate.
     */
    public static function matches(string $message): bool
    {
        if (self::hasTemplateDirective($message)) {
            return true;
        }

        $needle = mb_strtolower($message);

        // Directives strong enough to count as a drafting request even inside
        // an informational question ("Can you draft a demand letter?"). Bare
        // "write" / "make" are deliberately excluded here so phrasings like
        // "How can I make them pay?" stay advice, not drafting.
        $imperativeVerbs = [
            'draft', 'prepare', 'compose', 'create',
            'write a', 'write the', 'make a', 'make the',
            'gumawa', 'isulat',
        ];

        if (self::isInformationalQuestion($needle)) {
            foreach ($imperativeVerbs as $verb) {
                if (mb_strpos($needle, $verb) !== false) {
                    return true;
                }
            }

            return false;
        }

        $draftingVerbs = [
            'draft', 'prepare', 'compose', 'write', 'create', 'make',
            'gumawa', 'isulat',
        ];

        foreach ($draftingVerbs as $verb) {
            if (mb_strpos($needle, $verb) !== false) {
                return true;
            }
        }

        $keywords = [
            'letter', 'reklamo', 'reklamasyon', 'complaint', 'demand letter',
            'contract', 'agreement', 'affidavit', 'power of attorney',
            'deed of sale', 'kasulatan', 'legal document', 'position paper',
            'pleading', 'petition', 'request', 'requesting', 'certified copy',
        ];

        foreach ($keywords as $keyword) {
            if (mb_strpos($needle, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the message reads as an informational question rather than an
     * instruction to draft a document. A trailing question mark or any
     * interrogative phrasing (English or Tagalog/Filipino) marks it as advice
     * seeking, so bare document keywords no longer classify it as drafting.
     */
    private static function isInformationalQuestion(string $needle): bool
    {
        if (str_contains($needle, '?')) {
            return true;
        }

        $interrogatives = [
            'can i', 'can we', 'can you', 'could i', 'could we', 'could you',
            'would i', 'would we', 'would you', 'do i', 'do we', 'does the',
            'should i', 'should we', 'may i', 'may we', 'is there', 'are there',
            'is it', 'is this', 'is that', 'was there', 'were there', 'what if',
            'any way', 'any other way', 'is it possible', 'is that possible',
            'how do', 'how can', 'how to', 'how would', 'when can', 'where can',
            'what are', 'what are the', 'what is', 'am i', 'am i entitled',
            'entitled to', 'allowed to',
            'pwede', 'pwede ba', 'pwede bang', 'paano', 'ano ba', 'ano ang',
            'ano po', 'bakit', 'kung', 'kailan', 'saan', 'magkano', 'gaano',
            'meron bang', 'mayroon bang', 'meron ba', 'mayroon ba',
        ];

        foreach ($interrogatives as $phrase) {
            if (str_contains($needle, $phrase)) {
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
     * or "For Word and PDF export: [Insert Export Links Here]." Real export
     * links are always appended by the server, so any such placeholder text the
     * model produced is removed before persisting.
     */
    public static function exportPlaceholderPattern(): string
    {
        return '#(?:export\s+links?\s*:?\s*\[[^\]]+\]\s*(?:\|\s*\[[^\]]+\]\s*)*[.:;]?|\[[^\]]*(?:download|word document|exported|pdf|insert export)[^\]]*\]\s*(?:\|\s*\[[^\]]+\]\s*)*|\s*(?:as|for|to)\s+word\s+and\s+pdf\s+export\s*:?\s*\[[^\]]+\]\s*)[.:;]?[\r\n]*#i';
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
     * Recognize a TODO block marker on its own line. The canonical form is
     * [[TODO_START]] / [[TODO_END]], but the model occasionally wraps the
     * marker in markdown bold ("**[TODO_START]**"), drops to single brackets
     * ("[TODO_START]"), or prefixes it with a list dash ("-[TODO_END]"), so
     * all of those forms are matched. Returns 'start', 'end', or null.
     */
    private static function todoMarker(string $line): ?string
    {
        if (preg_match(self::TODO_MARKER_PATTERN, trim($line), $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Whether the reply opens a TODO block, in any of the forms the model
     * drifts into. Used to decide whether to fall back to server-side todo
     * creation when the model wrote the checklist but skipped create_todo.
     * Only the markers count here — a bare "Next Steps" heading appears in
     * ordinary chat replies too and must not spawn tasks on its own.
     */
    public static function hasTodoBlock(string $text): bool
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (self::todoMarker($line) !== null) {
                return true;
            }
        }

        return false;
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

            if (self::todoMarker($trimmed) === 'start') {
                $inSection = true;

                continue;
            }

            if (self::todoMarker($trimmed) === 'end') {
                break;
            }

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
     * Default intake fields used when the model produced a premature draft
     * instead of collecting missing facts through the intake tool.
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
                'key' => 'email',
                'label' => 'Email address',
                'type' => 'text',
                'section' => 'Contact Information',
                'required' => false,
            ],
            [
                'key' => 'contact_number',
                'label' => 'Contact number',
                'type' => 'text',
                'section' => 'Contact Information',
                'required' => false,
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
     * intake form can show the right fields when the model drafts with
     * missing facts.
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
     * Whether the reply is a clarifying question rather than a drafted
     * document — e.g. the model noticed missing or placeholder facts and asked
     * the user for more details. Such replies must never be presented as
     * exportable documents, even when the user originally asked for a draft.
     */
    public static function isClarification(string $text): bool
    {
        if (str_contains($text, '?')) {
            return true;
        }

        return preg_match(
            '/\b(?:please\s+)?(?:could\s+you|can\s+you|would\s+you|please\s+clarify|please\s+provide|please\s+confirm|let\s+me\s+know|provide\s+me\s+with|in\s+order\s+to\s+draft|once\s+you\s+provide|are\s+you\s+seeking|i\s+would\s+be\s+able\s+to\s+draft)\b/i',
            $text,
        ) === 1;
    }

    /**
     * Whether a marker-less reply is a substantive drafted document rather
     * than a plain chat answer: it must open like a legal document (letter
     * salutation or a Republic of the Philippines caption), close like one
     * (a signature block or a notarial acknowledgement), and be long enough
     * to be a real draft. Used so export links still reach drafts the model
     * forgot to wrap in the boundary markers.
     */
    public static function isSubstantiveDraft(string $text): bool
    {
        if (mb_strlen($text) < 400) {
            return false;
        }

        $opens = preg_match('/(?m)^\s*(?:Dear |Ginoong |Ginang |Kgg\.?|REPUBLIC OF THE PHILIPPINES|REPUBLIKA NG PILIPINAS)/i', $text) === 1;
        $closes = preg_match('/(?:Very truly yours|Respectfully yours|Truly yours|Yours faithfully|Sincerely|Gumagalang|Lubos na gumagalang|SUBSCRIBED AND SWORN|ACKNOWLEDGMENT)/i', $text) === 1;

        return $opens && $closes;
    }

    /**
     * Whether the text contains unknown facts written as bracketed
     * placeholders (e.g. "[Your Full Name]", "[CLOA No.]"). Meta tokens such
     * as the document markers or the intake submission wrapper are ignored.
     */
    public static function containsBrackets(string $text): bool
    {
        return preg_match('/\[([^\]\[]+)\]/', $text) === 1
            && self::extractBracketFields($text) !== [];
    }

    /**
     * Whether the text is a drafted document as opposed to a premature draft
     * or a partial answer. The opening marker is the reliable signal: the
     * model reliably wraps documents with [[DOCUMENT_START]] but often omits
     * the closing marker, so requiring it would discard otherwise complete
     * drafts.
     */
    public static function isCompleteDocument(string $text): bool
    {
        return str_contains($text, '[[DOCUMENT_START]]');
    }

    /**
     * Whether a drafting reply asks for the facts it needs via the
     * [[NEED_INFO]] marker. This is the single contract for re-opening the
     * intake form from a model response: the form is only triggered when the
     * model explicitly signals it cannot complete the draft without more
     * information.
     */
    public static function needsInfo(string $text): bool
    {
        return str_contains($text, self::NEED_INFO_MARKER);
    }

    /**
     * The question block itself: everything between the last [[NEED_INFO]] and
     * the closing marker, or the end of the reply when the model omitted the
     * close. Returns an empty string when the opening marker is absent.
     */
    public static function needsInfoBlock(string $text): string
    {
        $markerIndex = strrpos($text, self::NEED_INFO_MARKER);

        if ($markerIndex === false) {
            return '';
        }

        $block = substr($text, $markerIndex + strlen(self::NEED_INFO_MARKER));

        if (preg_match(self::NEED_INFO_END_PATTERN, $block, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $block = substr($block, 0, $matches[0][1]);
        }

        return $block;
    }

    /**
     * Remove the whole question block — markers included — from a reply, so a
     * turn that leaked the marker into the visible text is never shown to the
     * user or persisted with the raw protocol in it. Text before the marker
     * (the model's lead-in) is kept; text after the closing marker is dropped
     * too, since it is the model's "once you answer these…" sign-off for
     * questions the user is instead answering in the form.
     */
    public static function stripNeedsInfoBlock(string $text): string
    {
        $markerIndex = strrpos($text, self::NEED_INFO_MARKER);

        if ($markerIndex === false) {
            return $text;
        }

        return rtrim(substr($text, 0, $markerIndex));
    }

    /**
     * Extract the questions a model asked for inside the [[NEED_INFO]] block,
     * one per line, stripped of bullet/prefix decorations. Returns an empty
     * array when the marker is absent or nothing readable follows it.
     *
     * Only lines that actually ask for a fact are kept. The model habitually
     * ends the block with prose ("Once you answer these, I will draft the
     * complete Deed of Extrajudicial Settlement.") and that sentence must
     * never become a form field.
     *
     * @return array<int, string>
     */
    public static function extractNeedsInfoQuestions(string $text): array
    {
        $block = self::needsInfoBlock($text);

        if ($block === '') {
            return [];
        }

        $questions = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim((string) preg_replace('/^[\s\->*•.\d]+\s*/u', '', $line));

            if ($line === '' || preg_match('/[a-z0-9]/i', $line) !== 1) {
                continue;
            }

            if (! self::isFactRequest($line)) {
                continue;
            }

            $questions[] = $line;
        }

        return $questions;
    }

    /**
     * Whether a line inside the question block is asking the user for a fact,
     * as opposed to the model narrating what it will do next. A question mark
     * settles it; otherwise the line must read as a direct request.
     */
    private static function isFactRequest(string $line): bool
    {
        if (str_contains($line, '?')) {
            return true;
        }

        return preg_match(
            '/^(?:please\s+)?(?:provide|state|indicate|give|share|specify|confirm|supply|enter|tell|list|attach|upload)\b/i',
            $line,
        ) === 1;
    }

    /**
     * The choices a question offers inline, e.g. "…divided — equal one-fourth
     * shares each, adjudicated entirely to one heir, or some other unequal
     * arrangement?" becomes three options the user picks between instead of a
     * blank text box they have to paraphrase the question into.
     *
     * Returns an empty array when the question offers no enumeration.
     *
     * @return array{0: string, 1: array<int, string>} the label with the
     *                                                 enumeration trimmed off,
     *                                                 and the options
     */
    public static function questionChoices(string $question): array
    {
        // The enumeration is introduced by a dash or colon and must contain an
        // "or" alternative — without one it is an explanatory aside, not a
        // list of choices.
        if (preg_match('/^(.*?)\s*[—–:]\s*(.+?)\s*\??$/u', trim($question), $matches) !== 1) {
            return [$question, []];
        }

        [$stem, $tail] = [trim($matches[1]), trim($matches[2])];

        if ($stem === '' || ! preg_match('/,\s*or\s+/i', $tail)) {
            return [$question, []];
        }

        $options = [];

        foreach (preg_split('/,\s*(?:or\s+)?/i', $tail) ?: [] as $option) {
            $option = trim((string) $option, " \t\n\r.;");

            // An "or some other arrangement" tail is the model spelling out
            // the escape hatch the form already offers as "Other".
            if ($option === '' || preg_match('/^(?:some\s+)?other\b/i', $option) === 1) {
                continue;
            }

            $options[] = ucfirst($option);
        }

        if (count($options) < 2) {
            return [$question, []];
        }

        $options[] = 'Other';

        return [rtrim($stem, " \t\n\r,;").'?', $options];
    }

    /**
     * Whether the model marked a question as optional ("This one is optional —
     * I can omit it if you'd rather not provide it."), and the question with
     * that aside removed so the label stays a question.
     *
     * @return array{0: string, 1: bool} the trimmed label, and whether it is required
     */
    public static function questionRequirement(string $question): array
    {
        $trimmed = (string) preg_replace(
            '/\s*(?:[—–-]\s*)?(?:this\s+(?:one\s+)?is\s+optional|optional)\b[^?]*$/iu',
            '',
            trim($question),
        );

        $optional = $trimmed !== trim($question)
            || preg_match('/\b(?:optional|if\s+(?:any|available|known|applicable))\b/i', $question) === 1;

        $trimmed = trim($trimmed);

        return [$trimmed === '' ? trim($question) : $trimmed, ! $optional];
    }

    /**
     * Convert a model response carrying the [[NEED_INFO]] marker into intake
     * form fields, so the form collects exactly the facts the model said it
     * was missing. Questions that match a canonical concept collapse onto its
     * key (and type/section/conditional); the rest keep a slugged key derived
     * from the question's subject. The model's own wording becomes the field
     * label.
     *
     * @return array<int, array{key: string, label: string, type: string, options?: array<int, string>, section?: string, conditional?: array{field: string, values: array<int, string>}, required: bool}>
     */
    public static function intakeFieldsFromNeedsInfo(string $text): array
    {
        if (! self::needsInfo($text)) {
            return [];
        }

        $fields = [];
        $seen = [];

        foreach (self::extractNeedsInfoQuestions($text) as $question) {
            [$question, $required] = self::questionRequirement($question);
            [$label, $options] = self::questionChoices($question);

            $key = self::synonymFor($question) ?? Str::slug(self::questionSubject($label), '_', 'en');

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $definition = self::CANONICAL_FIELDS[$key] ?? null;

            $field = [
                'key' => $key,
                'label' => $label,
                // A question that spelled out its alternatives is answered by
                // picking one, not by retyping it.
                'type' => $options !== []
                    ? 'radio'
                    : ($definition['type'] ?? self::questionFieldType($question)),
                'required' => $required,
            ];

            if ($options !== []) {
                $field['options'] = $options;
            }

            if (isset($definition['section'])) {
                $field['section'] = $definition['section'];
            }

            if (isset($definition['conditional'])) {
                $field['conditional'] = $definition['conditional'];
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Normalize the `fields` argument the model passed to request_intake_form
     * into the shape the form renders, so what the model actually said it was
     * missing reaches the user instead of being replaced wholesale by a
     * server-side guess at the document's usual fields.
     *
     * Keys collapse onto their canonical concept so a model-invented key never
     * duplicates a field the template already collects; the model's own label
     * is kept, because it is phrased for the specific matter at hand. Entries
     * without a usable key or label are dropped rather than rendered blank.
     *
     * @param  mixed  $fields  The raw tool-call argument, of any shape.
     * @return array<int, array{key: string, label: string, type: string, options?: array<int, string>, required: bool}>
     */
    public const MAX_INTAKE_FIELDS = 14;

    public static function normalizeIntakeFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($fields as $field) {
            // A form longer than this is not a form, it is an interview. The
            // wizard shows one field at a time, so an uncapped list is a
            // model-authored questionnaire the user has to click through
            // before they can get the document they asked for.
            if (count($normalized) >= self::MAX_INTAKE_FIELDS) {
                break;
            }

            if (! is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            $rawKey = trim((string) ($field['key'] ?? ''));

            if ($rawKey === '' && $label === '') {
                continue;
            }

            $slug = $rawKey !== ''
                ? Str::slug($rawKey, '_', 'en')
                : Str::slug(self::questionSubject($label), '_', 'en');

            $key = self::canonicalForKey($slug) ?? $slug;

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $options = array_values(array_filter(
                array_map(
                    fn ($option) => trim((string) $option),
                    is_array($field['options'] ?? null) ? $field['options'] : [],
                ),
                fn (string $option) => $option !== '',
            ));

            $type = strtolower(trim((string) ($field['type'] ?? '')));

            if (! in_array($type, ['text', 'date', 'number', 'select', 'textarea', 'radio', 'checkbox'], true)) {
                $type = self::CANONICAL_FIELDS[$key]['type'] ?? self::questionFieldType($label !== '' ? $label : $key);
            }

            // A model that supplied options but asked for a free-text box gets
            // the choice widget anyway — the options are the answer set.
            if ($options !== [] && ! in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $type = 'radio';
            }

            if ($options === [] && in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $type = 'text';
            }

            $entry = [
                'key' => $key,
                'label' => $label !== '' ? $label : self::canonicalLabelOf($key),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? true),
            ];

            if ($options !== []) {
                $entry['options'] = $options;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * Resolve a bracketed placeholder text to its canonical field key, e.g.
     * "[Your Full Name]" or "[Sender Name]" both resolve to "sender_name".
     * Matching is longest-phrase-first so specific concepts win over generic
     * ones. Returns null when the placeholder does not match any concept.
     */
    public static function synonymFor(string $text): ?string
    {
        return self::matchSynonym(self::normalize($text), contain: true);
    }

    /**
     * The canonical key a field key (or slugged placeholder) represents, so
     * differently named keys for the same fact can be deduplicated. Keys that
     * are already canonical return themselves.
     */
    public static function canonicalForKey(string $key): ?string
    {
        if (isset(self::CANONICAL_FIELDS[$key])) {
            return $key;
        }

        return self::matchSynonym(self::normalize($key), contain: false);
    }

    /**
     * The canonical, human-reader-friendly label for a field key, or null when
     * the key does not map to a canonical concept.
     */
    public static function labelFor(string $key): ?string
    {
        return self::CANONICAL_FIELDS[self::canonicalForKey($key) ?? $key]['label'] ?? null;
    }

    /**
     * The canonical label for a key, falling back to a humanized version of
     * the key when the key is not part of the canonical registry.
     */
    public static function canonicalLabelOf(string $key): string
    {
        return self::labelFor($key) ?? ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Remap intake submission values onto their canonical keys so pre-filled
     * values always align with the canonical field set, even when an earlier
     * submission (or an earlier version of the form) used a different key for
     * the same fact (e.g. "cloa_number" → "reference_number").
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public static function canonicalizeIntakeValues(array $values): array
    {
        $canonical = [];

        foreach ($values as $key => $value) {
            $canonical[self::canonicalForKey((string) $key) ?? (string) $key] = $value;
        }

        return $canonical;
    }

    /**
     * Convert bracketed placeholders from a draft into intake form fields so
     * the unknown facts can be collected from the user instead of being left
     * as placeholders in the document. Placeholders that match a canonical
     * concept collapse onto its key, label, and type; the rest keep a slugged
     * key derived from the placeholder text.
     *
     * @return array<int, array{key: string, label: string, type: string, section?: string, conditional?: array{field: string, values: array<int, string>}, required: bool}>
     */
    public static function extractBracketFields(string $text): array
    {
        preg_match_all('/\[([^\]\[]+)\]/', $text, $matches);

        $fields = [];
        $seen = [];

        foreach ($matches[1] ?? [] as $raw) {
            $placeholder = trim($raw);

            if ($placeholder === '' || self::isMetaToken($placeholder)) {
                continue;
            }

            $key = self::synonymFor($placeholder) ?? Str::slug($placeholder, '_', 'en');

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $definition = self::CANONICAL_FIELDS[$key] ?? null;

            $field = [
                'key' => $key,
                'label' => $definition['label'] ?? ucfirst($placeholder),
                'type' => $definition['type'] ?? self::bracketFieldType($placeholder),
                'required' => true,
            ];

            if (isset($definition['section'])) {
                $field['section'] = $definition['section'];
            }

            if (isset($definition['conditional'])) {
                $field['conditional'] = $definition['conditional'];
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Append intake fields, dropping any whose canonical key is already present
     * in the base set so the template fields stay authoritative, the bracket
     * fields only add what the template missed, and the same fact is never
     * collected twice under a differently worded key (e.g. "cloa_number" is
     * dropped when the base already has "reference_number").
     *
     * @param  array<int, array{key: string, label: string, type: string, required: bool}>  $base
     * @param  array<int, array{key: string, label: string, type: string, required: bool}>  $extra
     * @return array<int, array{key: string, label: string, type: string, required: bool}>
     */
    public static function mergeIntakeFields(array $base, array $extra): array
    {
        $seen = [];

        foreach ($base as $field) {
            $seen[self::canonicalForKey($field['key']) ?? $field['key']] = true;
        }

        $merged = $base;

        foreach ($extra as $field) {
            $canonical = self::canonicalForKey($field['key']) ?? $field['key'];

            if (isset($seen[$canonical])) {
                continue;
            }

            $seen[$canonical] = true;

            $merged[] = $field;
        }

        return $merged;
    }

    /**
     * Match a normalized text against the canonical synonym registry. With
     * containment matching a phrase like "cloa number" matches the longer
     * placeholder "[Insert CLOA Number If Available On PSA Death Cert...]";
     * with exact matching the reverse (key → concept) lookup works.
     *
     * @return string|null The canonical key the text resolves to.
     */
    private static function matchSynonym(string $normalized, bool $contain): ?string
    {
        $pairs = [];

        foreach (self::CANONICAL_SYNONYMS as $canonical => $phrases) {
            foreach ($phrases as $phrase) {
                $pairs[] = [self::normalize($phrase), $canonical];
            }
        }

        usort($pairs, fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        foreach ($pairs as [$phrase, $canonical]) {
            if ($contain ? str_contains($normalized, $phrase) : $normalized === $phrase) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Normalize a placeholder or key for synonym matching: lowercased, with all
     * non-alphanumeric characters removed.
     */
    private static function normalize(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $text));
    }

    /**
     * Whether a bracketed token is a protocol/metadata marker rather than a
     * fact placeholder (document/todo markers, the intake wrapper, inline
     * citation tags such as [SRC K3F9], [DOC X1Y2], or [Source 1], etc.).
     */
    private static function isMetaToken(string $token): bool
    {
        $needle = mb_strtoupper($token);

        if (str_starts_with($needle, 'TEMPLATE:')) {
            return true;
        }

        if (preg_match('/^(SOURCE|USER DOC|WEB)\s+\d+$/', $needle) === 1) {
            return true;
        }

        if (preg_match('/^(SRC|DOC)\s+[A-Z0-9]+$/', $needle) === 1) {
            return true;
        }

        return in_array($needle, [
            'DOCUMENT_START',
            'DOCUMENT_END',
            'TODO_START',
            'TODO_END',
            'INTAKE FORM SUBMISSION',
            'EXPORT',
        ], true);
    }

    /**
     * Infer the intake input type from a bracketed placeholder's wording.
     */
    private static function bracketFieldType(string $text): string
    {
        $needle = mb_strtolower($text);

        if (str_contains($needle, 'date') || str_contains($needle, 'deadline')) {
            return 'date';
        }

        if (str_contains($needle, 'number') || str_contains($needle, 'amount') || str_contains($needle, 'no.')) {
            return 'number';
        }

        if (str_contains($needle, 'facts') || str_contains($needle, 'description')
            || str_contains($needle, 'details') || str_contains($needle, 'statement')) {
            return 'textarea';
        }

        return 'text';
    }

    /**
     * The subject a fact-gathering question is really about, used to derive a
     * stable key when the question does not match a canonical concept — e.g.
     * "What is the amount being demanded?" → "the amount being demanded".
     */
    private static function questionSubject(string $question): string
    {
        $subject = rtrim(trim($question), " \t\n\r?.,");

        $subject = preg_replace(
            '/^(?:what|who|whom|whose|when|where|why|which|how)\s+(?:is|are|was|were|do|does|did|would|could|should|can|will|shall|have|has)\s+/i',
            '',
            $subject,
        ) ?? $subject;

        $subject = preg_replace(
            '/^(?:please\s+)?(?:provide|state|indicate|give|share|enter|tell|supply|specify)\s+(?:me\s+)?(?:your\s+|the\s+|a\s+|an\s+)?/i',
            '',
            $subject,
        ) ?? $subject;

        return trim($subject) === '' ? $question : trim($subject);
    }

    /**
     * Infer the intake input type from a fact-gathering question's wording.
     */
    private static function questionFieldType(string $text): string
    {
        $needle = mb_strtolower($text);

        if (str_contains($needle, 'date') || str_contains($needle, 'deadline') || str_contains($needle, 'when')) {
            return 'date';
        }

        if (str_contains($needle, 'number') || str_contains($needle, 'amount')
            || str_contains($needle, 'how much') || str_contains($needle, 'how many')) {
            return 'number';
        }

        if (str_contains($needle, 'facts') || str_contains($needle, 'description')
            || str_contains($needle, 'details') || str_contains($needle, 'statement')
            || str_contains($needle, 'narrative') || str_contains($needle, 'explain')) {
            return 'textarea';
        }

        return 'text';
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
                'key' => 'email',
                'label' => 'Email address',
                'type' => 'text',
                'section' => 'Contact Information',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'contact_number',
                'label' => 'Contact number',
                'type' => 'text',
                'section' => 'Contact Information',
                'options' => [],
                'required' => false,
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
                'conditional' => [
                    'field' => 'transaction_type',
                    'values' => ['Request for Certification/Document', 'Appeal', 'Protest', 'Motion for Reconsideration', 'Compliance Submission'],
                ],
            ],
            [
                'key' => 'legal_basis',
                'label' => 'Law or regulation being invoked (if known)',
                'type' => 'text',
                'options' => [],
                'required' => false,
                'conditional' => [
                    'field' => 'transaction_type',
                    'values' => ['Appeal', 'Protest', 'Motion for Reconsideration'],
                ],
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
                'conditional' => [
                    'field' => 'transaction_type',
                    'values' => ['Appeal', 'Protest', 'Motion for Reconsideration', 'Compliance Submission'],
                ],
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
                'key' => 'email',
                'label' => 'Email address',
                'type' => 'text',
                'section' => 'Contact Information',
                'options' => [],
                'required' => false,
            ],
            [
                'key' => 'contact_number',
                'label' => 'Contact number',
                'type' => 'text',
                'section' => 'Contact Information',
                'options' => [],
                'required' => false,
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
