<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fallback Document Version
    |--------------------------------------------------------------------------
    |
    | The published terms live in the `legal_documents` table and are seeded by
    | LegalDocumentSeeder; that table is the source of truth for the current
    | version. This value is only used as a fallback when no document has been
    | published yet (a fresh or test database). To publish new terms, add a
    | version in LegalDocumentSeeder and re-run it — users are then prompted to
    | re-accept on their next page load.
    |
    */

    'current_version' => '1.0',

    /*
    |--------------------------------------------------------------------------
    | Document Hash Algorithm
    |--------------------------------------------------------------------------
    |
    | The algorithm used to hash the document content for integrity verification.
    |
    */

    'hash_algorithm' => 'sha256',

    /*
    |--------------------------------------------------------------------------
    | Exempt Routes
    |--------------------------------------------------------------------------
    |
    | Routes that do not require terms acceptance. These routes are accessible
    | even if the user has not accepted the terms yet.
    |
    */

    'exempt_routes' => [
        'user',
        'terms/*',
        'logout',
        'kyc/*',
        'register',
        'login',
    ],

];
