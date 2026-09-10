<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | The gateway used to start new subscriptions. Existing subscriptions
    | continue to use the gateway stored on their row.
    |
    | Supported: 'paypal', 'lemonsqueezy', 'paymongo'
    |
    */

    'default_gateway' => env('BILLING_GATEWAY', 'paypal'),

    /*
    |--------------------------------------------------------------------------
    | Card processing schedule
    |--------------------------------------------------------------------------
    |
    | The percentage-plus-fixed schedule of the gateway above, used by the
    | costing model to turn list prices into net revenue. The defaults describe
    | PayMongo (3.5% + ₱15); when the gateway in use charges something else,
    | set these to that schedule rather than reading margins off the wrong one.
    |
    */

    'fee_percent' => (float) env('BILLING_FEE_PERCENT', 0.035),

    'fee_fixed_pesos' => (float) env('BILLING_FEE_FIXED_PESOS', 15.0),

    /*
    |--------------------------------------------------------------------------
    | AI usage metering
    |--------------------------------------------------------------------------
    |
    | Plans sell a monthly AI spend allowance, not a message count. Every AI
    | operation reserves against the subscription's anniversary window before
    | the provider is called and settles with the measured cost after, so the
    | customer sees one percent meter while the ledger keeps every token.
    |
    | `fx_usd_php` converts metered USD costs to displayed pesos. It is a
    | budgeting rate with headroom over the reference rate, not a promise of
    | any card settlement rate — re-check it when the peso moves.
    |
    | `warn_percent` fires the once-per-window 80% notice. `reservation_ttl`
    | bounds how long an unsettled reservation counts against the budget, so
    | a crashed worker cannot hold spend hostage past its minutes.
    |
    | `estimates_usd` are the pre-flight holds per operation: large enough that
    | a normal turn always fits inside one, small enough that a nearly-spent
    | budget still admits cheap work. Actuals always replace them at settle.
    |
    */

    'fx_usd_php' => (float) env('BILLING_FX_USD_PHP', 65.0),

    'usage_warn_percent' => (float) env('BILLING_USAGE_WARN_PERCENT', 80.0),

    'reservation_ttl_minutes' => (int) env('BILLING_RESERVATION_TTL_MINUTES', 15),

    'usage_estimates_usd' => [
        'chat' => (float) env('BILLING_ESTIMATE_CHAT_USD', 0.08),
        'rewrite' => (float) env('BILLING_ESTIMATE_REWRITE_USD', 0.012),
        'research' => (float) env('BILLING_ESTIMATE_RESEARCH_USD', 0.03),
        'letter' => (float) env('BILLING_ESTIMATE_LETTER_USD', 0.02),
        'ingest_text' => (float) env('BILLING_ESTIMATE_INGEST_TEXT_USD', 0.012),
        'ingest_scan' => (float) env('BILLING_ESTIMATE_INGEST_SCAN_USD', 0.035),
        'digest' => (float) env('BILLING_ESTIMATE_DIGEST_USD', 0.01),
        'classify' => (float) env('BILLING_ESTIMATE_CLASSIFY_USD', 0.006),
    ],
];
