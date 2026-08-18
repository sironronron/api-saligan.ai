<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lawyer / document vetting
    |--------------------------------------------------------------------------
    |
    | The selectable practice areas, regions, and fee defaults for the
    | lawyer-vetting marketplace. Fees and the commission split live in
    | platform_settings so admins can change them at runtime; these are the
    | fallbacks used before anything has been configured.
    |
    */

    'practice_areas' => [
        ['value' => 'contracts', 'label' => 'Contracts & Agreements'],
        ['value' => 'real_estate', 'label' => 'Real Estate'],
        ['value' => 'land_titles', 'label' => 'Land Titles & Titling'],
        ['value' => 'corporate', 'label' => 'Corporate Law'],
        ['value' => 'family_law', 'label' => 'Family Law'],
        ['value' => 'litigation', 'label' => 'Litigation & Disputes'],
        ['value' => 'labor_employment', 'label' => 'Labor & Employment'],
        ['value' => 'criminal_law', 'label' => 'Criminal Law'],
        ['value' => 'tax', 'label' => 'Tax'],
        ['value' => 'immigration', 'label' => 'Immigration'],
        ['value' => 'intellectual_property', 'label' => 'Intellectual Property'],
        ['value' => 'other', 'label' => 'Other'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Practice area → document types
    |--------------------------------------------------------------------------
    |
    | The document types a lawyer offering each practice area can expect to be
    | matched for, shown on the registration form so the areas and the
    | submitter-facing document types visibly line up. An empty list means the
    | area receives only unclassified document types. This mirrors the
    | `AREA_BY_DOCUMENT_TYPE` map in `App\Services\Vetting\LawyerMatcher`.
    |
    */
    'practice_area_document_types' => [
        'contracts' => ['Contract to Sell', 'Loan Agreement', 'Contract of Lease', 'Lease Agreement', 'Special Power of Attorney', 'Board Resolution', 'Articles of Incorporation'],
        'real_estate' => ['Deed of Absolute Sale', 'Deed of Donation', 'Extra-Judicial Settlement', 'Transfer of Rights', 'Contract of Lease', 'Lease Agreement', 'Contract to Sell'],
        'land_titles' => ['Deed of Absolute Sale', 'Deed of Donation', 'Extra-Judicial Settlement', 'Transfer of Rights'],
        'corporate' => ['Board Resolution', 'Articles of Incorporation', 'Contract to Sell', 'Loan Agreement'],
        'family_law' => ['Special Power of Attorney'],
        'litigation' => ['Affidavit', 'Sworn Statement', 'Complaint', 'Demand Letter'],
        'other' => ['Government Transaction Letter'],
    ],

    'regions' => [
        ['value' => 'nationwide', 'label' => 'Nationwide'],
        ['value' => 'ncr', 'label' => 'National Capital Region'],
        ['value' => 'region1', 'label' => 'Region I – Ilocos'],
        ['value' => 'car', 'label' => 'Cordillera Administrative Region'],
        ['value' => 'region2', 'label' => 'Region II – Cagayan Valley'],
        ['value' => 'region3', 'label' => 'Region III – Central Luzon'],
        ['value' => 'region4a', 'label' => 'Region IV-A – CALABARZON'],
        ['value' => 'region4b', 'label' => 'Region IV-B – MIMAROPA'],
        ['value' => 'region5', 'label' => 'Region V – Bicol'],
        ['value' => 'region6', 'label' => 'Region VI – Western Visayas'],
        ['value' => 'region7', 'label' => 'Region VII – Central Visayas'],
        ['value' => 'region8', 'label' => 'Region VIII – Eastern Visayas'],
        ['value' => 'region9', 'label' => 'Region IX – Zamboanga Peninsula'],
        ['value' => 'region10', 'label' => 'Region X – Northern Mindanao'],
        ['value' => 'region11', 'label' => 'Region XI – Davao'],
        ['value' => 'region12', 'label' => 'Region XII – SOCCSKSARGEN'],
        ['value' => 'caraga', 'label' => 'Caraga'],
        ['value' => 'barmm', 'label' => 'BARMM'],
    ],

    /*
     * Fallback defaults, in centavos. Overrides live in platform_settings
     * under `vetting.fees` once an admin has saved them.
     */
    'default_vetting_fee' => (int) env('VETTING_FEE', 10000),
    'default_notarization_fee' => (int) env('NOTARIZATION_FEE', 50000),

    /*
     * The lawyer notarization fee schedule, in centavos. Keyed by a document
     * category slug; the fee shown to a submitter is matched from their
     * document type via `NotarialFeeSchedule`. A `fee` is a flat charge; a
     * `percent` rule applies that percentage of the property/contract value,
     * never below `minimum`.
     */
    'notarial_fee_schedule' => [
        'affidavit' => [
            'label' => 'Simple Affidavits',
            'fee' => 32500,
            'percent' => null,
            'minimum' => null,
        ],
        'spa' => [
            'label' => 'Special Power of Attorney',
            'fee' => 100000,
            'percent' => null,
            'minimum' => null,
        ],
        'deed' => [
            'label' => 'Deed of Absolute Sale / Transfer of Rights',
            'fee' => null,
            'percent' => 1,
            'minimum' => 150000,
        ],
        'lease' => [
            'label' => 'Contract of Lease',
            'fee' => null,
            'percent' => 1,
            'minimum' => 150000,
        ],
        'ctc' => [
            'label' => 'Certified True Copy',
            'fee' => 15000,
            'percent' => null,
            'minimum' => null,
        ],
    ],

    /*
     * The platform's cut of each notarization, as a percentage. The rest of
     * the fee goes to the notary-lawyer in a weekly payout.
     */
    'platform_commission_percent' => (float) env('VETTING_COMMISSION_PERCENT', 10),

    /*
     * How long a notified lawyer has to respond before the request is offered
     * to the next matching lawyer, in hours.
     */
    'escalation_hours' => (int) env('VETTING_ESCALATION_HOURS', 24),

    /*
     * The default cap on how many open requests a single lawyer may hold at
     * once. Individual lawyers can lower theirs; the settings value is the
     * ceiling for what a profile may claim.
     */
    'max_concurrent_assignments' => (int) env('VETTING_MAX_CONCURRENT', 3),

    /*
     * The provider used to spin up the remote notarization video session.
     * Only the meeting URL is generated; recordings are not stored here.
     */
    'session_provider' => env('VETTING_SESSION_PROVIDER', 'whereby'),

    /*
     * Fallback meeting base URL used when the provider is a fixed link.
     */
    'session_base_url' => env('VETTING_SESSION_BASE_URL', 'https://whereby.com'),

    /*
     * The remote online notarization method recorded in the journal entry
     * when a notary completes a notarization over video.
     */
    'verification_method' => env('VETTING_VERIFICATION_METHOD', 'remote_online_video'),
];
