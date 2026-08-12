<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supabase Project
    |--------------------------------------------------------------------------
    |
    | Supabase Auth is the identity and session layer for the app. The API
    | validates the bearer access tokens the frontend attaches to requests
    | against the project's shared JWT secret, so these values must match the
    | project configured in the Supabase dashboard (Settings > API).
    |
    */

    'url' => env('SUPABASE_URL'),

    /**
     * The publishable (anon) API key. Safe to expose to browsers; the frontend
     * uses it to authenticate against Supabase. The backend can also use it,
     * together with a user's access token, to introspect sessions via the
     * Supabase Auth API.
     */
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY'),

    /**
     * The secret (service_role) API key. Never expose to clients — it bypasses
     * Row Level Security and is intended for server-side admin operations
     * (user management, metadata sync) from the Laravel app.
     */
    'secret_key' => env('SUPABASE_SECRET_KEY'),

    /**
     * The project's `jwt_secret` (Settings > API > JWT Settings). Supabase
     * signs access tokens with HS256 using this shared secret, which lets the
     * API verify incoming bearer tokens offline.
     */
    'jwt_secret' => env('SUPABASE_JWT_SECRET'),
];
