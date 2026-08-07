<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | The gateway used to start new subscriptions. LemonSqueezy is the
    | default; PayMongo is used automatically as a fallback when
    | LemonSqueezy is not configured or a plan has no LemonSqueezy variant.
    |
    | Supported: 'lemonsqueezy', 'paymongo'
    |
    */

    'default_gateway' => env('BILLING_GATEWAY', 'lemonsqueezy'),
];
