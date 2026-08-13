<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding configuration
    |--------------------------------------------------------------------------
    |
    | The provider and model used to embed text. The dimensions must match the
    | vector columns in the database (document_chunks.embedding and
    | legal_chunks.embedding), which are currently halfvec(768). Gemini
    | embedding models accept an output dimensionality, so EMBEDDING_DIMENSIONS
    | (and the halfvec columns) can stay at 768.
    |
    */

    'embedding' => [
        'provider' => env('AI_EMBED_PROVIDER', 'gemini'),
        'model' => env('AI_EMBED_MODEL', 'gemini-embedding-2'),
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 768),
        'timeout' => (int) env('EMBEDDING_TIMEOUT', 600),
        'batch_size' => (int) env('EMBEDDING_BATCH_SIZE', 16),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat configuration
    |--------------------------------------------------------------------------
    |
    | Provider names reference providers defined in config/ai.php.
    |
    */

    'chat' => [
        'provider' => env('AI_CHAT_PROVIDER', 'anthropic'),
        'ollama_model' => env('OLLAMA_CHAT_MODEL', 'qwen3.6:latest'),
        'ollama_model_alt' => env('OLLAMA_CHAT_MODEL_ALT', 'qwen3.5:latest'),
        'gemini_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash'),
        'openai_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o'),
        'anthropic_model' => env('ANTHROPIC_CHAT_MODEL', 'claude-sonnet-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context caching
    |--------------------------------------------------------------------------
    |
    | Gates both providers' prompt caching for the static system prompt.
    |
    | Gemini: the static system prompt (persona + standing instructions) is
    | cached as a CachedContent resource so subsequent chat turns bill those
    | tokens at the reduced cached-input rate. The cached prefix is referenced
    | by name via the generateContent "cachedContent" field; dynamic per-turn
    | instructions (export, case, template, retrieved context) are appended
    | after it.
    |
    | Anthropic: the static system prompt is sent as its own system block with
    | a "cache_control" breakpoint, using the same ttl_seconds as Gemini —
    | an hour when it is 3600 or more, otherwise Anthropic's five-minute
    | default. The block is identical on every request and carries no per-user
    | text, so one cache entry serves every tenant and is read at 0.1x the
    | input rate on every subsequent turn.
    |
    */

    'context_caching' => [
        'enabled' => (bool) env('GEMINI_CONTEXT_CACHING', true),
        'ttl_seconds' => (int) env('GEMINI_CONTEXT_CACHE_TTL', 3600),
        'refresh_seconds' => (int) env('GEMINI_CONTEXT_CACHE_REFRESH', 3000),
        // How long to wait when (re)creating the CachedContent on the request
        // path before giving up. Creation happens synchronously on a cache
        // miss, so a hung call must fail fast — the caller then proceeds
        // without cached-input pricing instead of blocking the stream start.
        'create_timeout' => (int) env('GEMINI_CONTEXT_CACHE_CREATE_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trials
    |--------------------------------------------------------------------------
    |
    | A code-granted trial ends on whichever runs out first: the days on the
    | code, or the plan's message allowance counted across the organization.
    | The thresholds below decide when the single warning email goes out.
    |
    */

    'trials' => [
        'warn_days_remaining' => (int) env('TRIAL_WARN_DAYS', 3),
        'warn_messages_remaining' => (int) env('TRIAL_WARN_MESSAGES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document ingestion
    |--------------------------------------------------------------------------
    */

    'documents' => [
        'max_size_mb' => (int) env('DOCUMENT_MAX_SIZE_MB', 25),
        'chunk_size' => (int) env('DOCUMENT_CHUNK_SIZE', 500),
        'chunk_overlap' => (int) env('DOCUMENT_CHUNK_OVERLAP', 50),
        'queue' => env('DOCUMENT_PROCESSING_QUEUE', 'document-processing'),
        'image_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tiff', 'heic'],

        /*
        |--------------------------------------------------------------------------
        | At-rest encryption
        |--------------------------------------------------------------------------
        |
        | When enabled, uploaded documents are encrypted with a per-file key
        | before they are written to disk and decrypted on the fly when served
        | or processed. Existing plaintext files remain readable until deleted;
        | they are detected by the absence of the encryption header.
        |
        */

        'encrypt_at_rest' => (bool) env('DOCUMENT_ENCRYPT_AT_REST', true),

        /*
        |--------------------------------------------------------------------------
        | Image OCR
        |--------------------------------------------------------------------------
        |
        | The provider and model used to transcribe text out of uploaded images
        | (scans, photos, screenshots). Provider names reference providers
        | defined in config/ai.php. Falls back to Ollama when the configured
        | provider has no API key.
        |
        */

        'ocr' => [
            'provider' => env('DOCUMENT_OCR_PROVIDER', 'gemini'),
            'model' => env('DOCUMENT_OCR_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal source crawler
    |--------------------------------------------------------------------------
    */

    'crawler' => [
        'enabled' => (bool) env('LEGAL_CRAWLER_ENABLED', true),
        'schedule' => env('LEGAL_CRAWLER_SCHEDULE', '0 2 * * *'),
        'user_agent' => env('LEGAL_CRAWLER_USER_AGENT', 'SaliganAIBot/1.0 (+https://saligan.ai/bot)'),
        'delay_ms' => (int) env('LEGAL_CRAWLER_DELAY_MS', 3000),
        'queue' => env('LEGAL_CRAWLER_QUEUE', 'legal-crawler'),
        'max_depth' => (int) env('LEGAL_CRAWLER_MAX_DEPTH', 2),
        'max_links_per_page' => (int) env('LEGAL_CRAWLER_MAX_LINKS_PER_PAGE', 25),
        'max_pages_per_run' => (int) env('LEGAL_CRAWLER_MAX_PAGES_PER_RUN', 500),

        /*
         * The plain-language digest written for each crawled authority. Set
         * the provider to "none" to skip digesting entirely — the reader falls
         * back to full text, so this only costs the summary at the top.
         */
        'digest' => [
            'provider' => env('LEGAL_DIGEST_PROVIDER', 'gemini'),
            'model' => env('LEGAL_DIGEST_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    'retrieval' => [
        'min_similarity' => (float) env('RETRIEVAL_MIN_SIMILARITY', 0.30),
        'max_legal_chunks' => (int) env('RETRIEVAL_MAX_LEGAL_CHUNKS', 6),
        'max_document_chunks' => (int) env('RETRIEVAL_MAX_DOCUMENT_CHUNKS', 4),
    ],

];
