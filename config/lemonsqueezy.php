<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LemonSqueezy API Keys
    |--------------------------------------------------------------------------
    |
    | Your LemonSqueezy API key from the dashboard (Settings -> API).
    | Keep this secret. Test mode uses the same key as live mode but is
    | toggled per checkout/webhook.
    |
    */

    'api_key' => env('LEMONSQUEEZY_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook Signature
    |--------------------------------------------------------------------------
    |
    | The signing secret configured on your LemonSqueezy webhook. Used to
    | verify the X-Signature header on incoming events (HMAC-SHA256 of the
    | raw request body).
    |
    */

    'webhook_secret' => env('LEMONSQUEEZY_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | The store id to attach checkouts to. Visible in the LemonSqueezy
    | dashboard URL or via the stores API.
    |
    */

    'store_id' => env('LEMONSQUEEZY_STORE_ID'),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | LemonSqueezy's API endpoint. Overridable so tests can point at a fake.
    |
    */

    'base_url' => env('LEMONSQUEEZY_BASE_URL', 'https://api.lemonsqueezy.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    |
    | Styling passed through to the hosted LemonSqueezy checkout page.
    |
    */

    'checkout_button_color' => env('LEMONSQUEEZY_CHECKOUT_BUTTON_COLOR', '#7047EB'),
];
