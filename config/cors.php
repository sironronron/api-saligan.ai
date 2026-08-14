<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Comma-separated list of frontend origins (e.g. https://app.batayan.co,
    // https://batayan.co). Credentialed CORS requires the request origin to
    // match exactly, so every host the SPA can be reached at must be listed.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')),
    ))),

    // Local frontend hosts (localhost / 127.0.0.1 / IPv6) on any dev port.
    // Patterns also make the middleware echo back the actual request origin
    // instead of always returning the single configured origin.
    //
    // Local origins are granted only outside production. Left on in
    // production, any page the user can be induced to load from a local port —
    // a dev server, an Electron app, a local tool with an HTTP interface —
    // would be handed cross-origin read access to the API.
    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : [
        '#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // The frontend authenticates with an Authorization header, not cookies,
    // so credentialed requests are no longer needed. Leaving this on would
    // also forbid the '*' origin wildcard for no benefit.
    'supports_credentials' => false,

];
