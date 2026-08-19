<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Add-on Integrations (Google Workspace / Microsoft SharePoint)
    |--------------------------------------------------------------------------
    |
    | OAuth 2.0 credentials for the two add-on providers. Tokens are stored
    | encrypted at rest (see the Integration model's `encrypted` casts) and
    | are never returned to any client.
    |
    */

    // Where the provider sends the user back after consent. Must match the
    // redirect URI registered with the provider exactly.
    'redirect_path' => '/api/integrations/callback',

    // When set, the OAuth redirect_uri is built against this base instead of
    // the API's own URL. This lets the Nuxt app own the callback
    // (/api/integrations/callback) and proxy the round-trip to the API, so the
    // browser only ever talks to the frontend during the hand-off.
    'callback_base_url' => env('INTEGRATIONS_CALLBACK_BASE_URL', ''),

    // How long an OAuth `state` stays valid, in minutes. Short on purpose: a
    // consent round-trip that takes longer than this should simply start over.
    'state_ttl_minutes' => 10,

    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET', ''),
        'authorize_url' => env('GOOGLE_OAUTH_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'revoke_url' => env('GOOGLE_OAUTH_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        'userinfo_url' => env('GOOGLE_OAUTH_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_OAUTH_CLIENT_ID', ''),
        'client_secret' => env('MICROSOFT_OAUTH_CLIENT_SECRET', ''),
        // The multi-tenant endpoint; an organization that wants a single tenant
        // overrides this with its tenant id.
        'tenant' => env('MICROSOFT_OAUTH_TENANT', 'common'),
        'graph_url' => env('MICROSOFT_GRAPH_URL', 'https://graph.microsoft.com/v1.0'),
    ],
];
