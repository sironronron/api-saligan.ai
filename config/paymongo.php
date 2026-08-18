<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PayMongo API Keys
    |--------------------------------------------------------------------------
    |
    | These are the secret and public keys from your PayMongo dashboard.
    | Use test keys (sk_test_ and pk_test_ prefixed) in development.
    |
    */

    'secret_key' => env('PAYMONGO_SECRET_KEY', ''),

    'public_key' => env('PAYMONGO_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook Signature
    |--------------------------------------------------------------------------
    |
    | The key PayMongo uses to sign webhook events (configured in the
    | PayMongo dashboard, "Webhooks" settings). Used to verify the
    | PayMongo-Signature header on incoming events.
    |
    */

    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook Signature Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix PayMongo prepends to webhook signatures (defaults to
    | "paymongo").
    |
    */

    'webhook_signature_prefix' => env('PAYMONGO_WEBHOOK_SIGNATURE_PREFIX', 'paymongo'),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | PayMongo's API endpoint. Overridable so tests can point at a fake.
    |
    */

    'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com'),

    /*
    |--------------------------------------------------------------------------
    | Processing Fee Pass-Through
    |--------------------------------------------------------------------------
    |
    | The fee PayMongo charges the merchant for each successful payment is
    | passed on to the buyer: the amount authorized is grossed up so the
    | platform nets the requested service fee after PayMongo takes its cut.
    |
    */

    'processing_fee_percent' => (float) env('PAYMONGO_PROCESSING_FEE_PERCENT', 3.5),

    'processing_fee_fixed' => (int) env('PAYMONGO_PROCESSING_FEE_FIXED', 1500),
];
