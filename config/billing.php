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
];
