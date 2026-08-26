<?php

$mode = strtolower((string) env('PAYPAL_MODE', 'sandbox'));
$defaultBaseUrl = $mode === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com';

return [
    'mode' => $mode,

    'client_id' => env('PAYPAL_CLIENT_ID', ''),

    'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),

    'webhook_id' => env('PAYPAL_WEBHOOK_ID', ''),

    'currency' => strtoupper((string) env('PAYPAL_CURRENCY', 'PHP')),

    'base_url' => env('PAYPAL_BASE_URL') ?: $defaultBaseUrl,

    'oauth_url' => env('PAYPAL_OAUTH_URL') ?: "{$defaultBaseUrl}/v1/oauth2/token",

    'timeout' => (int) env('PAYPAL_TIMEOUT', 15),

    'plans' => [
        'standard' => [
            'monthly' => env('PAYPAL_PLAN_STANDARD_MONTHLY', ''),
            'annual' => env('PAYPAL_PLAN_STANDARD_ANNUAL', ''),
        ],
        'pro' => [
            'monthly' => env('PAYPAL_PLAN_PRO_MONTHLY', ''),
            'annual' => env('PAYPAL_PLAN_PRO_ANNUAL', ''),
        ],
        'firm' => [
            'monthly' => env('PAYPAL_PLAN_FIRM_MONTHLY', ''),
            'annual' => env('PAYPAL_PLAN_FIRM_ANNUAL', ''),
        ],
    ],
];
