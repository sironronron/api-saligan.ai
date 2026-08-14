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

        /*
         * Context window requested from Ollama, in tokens.
         *
         * Ollama defaults num_ctx to 4096 and silently truncates anything
         * longer, keeping only the TAIL of the prompt. A drafting turn sends
         * roughly 23k tokens — persona and drafting rules, the uploaded
         * template body and its placeholders, matter memory, then retrieved
         * context — so at the default the model read about 2k tokens and every
         * instruction that makes template drafting work was discarded before
         * it ever saw them. It answered from the leftover tail and never
         * called fill_template_fields, so no document was produced.
         *
         * Raise this and the whole prompt survives. The cost is KV-cache
         * memory on the Ollama host and prompt-eval time, so it is tunable:
         * lower it if the host runs out of VRAM, but never below the size of a
         * drafting prompt or the truncation returns silently.
         */
        'ollama_num_ctx' => (int) env('OLLAMA_NUM_CTX', 32768),

        /*
         * Per-request timeout for a chat step, in seconds.
         *
         * laravel/ai falls back to 60s when the agent names no timeout, and
         * Guzzle applies that as an IDLE timeout on the response stream. A
         * local model reading a ~23k-token drafting prompt emits nothing at
         * all while it works — measured at ~163s on the dev box — so the read
         * expired long before the first token and surfaced as Guzzle's
         * misleading "Connection refused for URI", with no reply persisted.
         *
         * Hosted providers answer in a few seconds and never approach this;
         * it exists for slow local inference.
         */
        'timeout' => (int) env('AI_CHAT_TIMEOUT', 300),
        'gemini_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash'),
        'openai_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o'),
        'anthropic_model' => env('ANTHROPIC_CHAT_MODEL', 'claude-sonnet-5'),

        /*
         * How hard the model works before answering: low | medium | high |
         * xhigh | max.
         *
         * Claude Sonnet 5 defaults to `high`, which was never set here and so
         * was never chosen — every answer was paying for the most deliberate
         * setting. Retrieval has already done the source-finding by the time
         * the model runs, so `medium` returns the first token noticeably
         * sooner while holding answer quality. Raise it if citation accuracy
         * suffers; drop to `low` for a faster, chattier feel.
         */
        'effort' => env('ANTHROPIC_CHAT_EFFORT', 'medium'),
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
    | Deadline reminders
    |--------------------------------------------------------------------------
    |
    | A nightly sweep emails the owner of each case or task whose due date has
    | arrived or is about to. `lead_days` is how far ahead of the deadline a
    | reminder starts going out; set it to 0 to disable reminders entirely.
    |
    */

    'reminders' => [
        'lead_days' => (int) env('DEADLINE_REMINDER_LEAD_DAYS', 3),
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
        | Refuse the unauthenticated legacy format
        |--------------------------------------------------------------------------
        |
        | Documents written before the integrity tag existed use format v1,
        | which encrypts but does not authenticate: an attacker with write
        | access to the disk can flip bits of the plaintext undetectably.
        | Those files stay readable so a deployment can migrate without
        | downtime. Run `saligan:reencrypt-documents` to rewrite them as v2,
        | then turn this on so the old format is rejected outright.
        |
        */

        'require_authenticated_encryption' => (bool) env('DOCUMENT_REQUIRE_AUTHENTICATED_ENCRYPTION', false),

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

        /*
        |--------------------------------------------------------------------------
        | Case-file classification
        |--------------------------------------------------------------------------
        |
        | After a document is ingested, a model reads the opening of it and
        | files it under the case-file categories it belongs to. Classification
        | is a suggestion, never a verdict: anything the model is not at least
        | `min_confidence` sure of is left off, so the document surfaces in the
        | Unfiled queue for a person to decide instead of being filed wrongly.
        |
        | A category a person chose is never overwritten. `model` is optional —
        | left empty, each provider falls back to its own cheap default, since
        | this is a short classification call and not a drafting one.
        |
        */

        'classification' => [
            'enabled' => (bool) env('DOCUMENT_CLASSIFICATION_ENABLED', true),
            'provider' => env('DOCUMENT_CLASSIFICATION_PROVIDER', env('AI_CHAT_PROVIDER', 'anthropic')),
            'model' => env('DOCUMENT_CLASSIFICATION_MODEL'),
            'min_confidence' => (float) env('DOCUMENT_CLASSIFICATION_MIN_CONFIDENCE', 0.6),
            'max_categories' => (int) env('DOCUMENT_CLASSIFICATION_MAX_CATEGORIES', 3),
            'excerpt_characters' => (int) env('DOCUMENT_CLASSIFICATION_EXCERPT_CHARS', 6000),
            'timeout' => (int) env('DOCUMENT_CLASSIFICATION_TIMEOUT', 90),
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
         * Refuse to fetch URLs that resolve to loopback, private, or
         * link-local addresses — the SSRF guard that keeps a seed URL or a
         * redirect from reaching the cloud metadata service, Redis, or
         * anything else bound inside the network. Leave this on in production;
         * it is disabled under test so faked HTTP does not need live DNS.
         */
        'block_private_addresses' => (bool) env('LEGAL_CRAWLER_BLOCK_PRIVATE_ADDRESSES', true),

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
    | Intake form
    |--------------------------------------------------------------------------
    |
    | When the case already carries the narrative facts, the intake form stops
    | asking for them — the model drafts the "who, what, when, where" straight
    | from the case context instead of making the user retype it. The
    | thresholds below decide when a case counts as actually supplying those
    | facts.
    |
    | Both are deliberately about SUBSTANCE, not presence. The check used to be
    | `filled($case->description)`, so a three-word description ("land
    | dispute") or a single uploaded scan of an ID suppressed the entire form —
    | the model then had no channel left for the party names, addresses, and
    | amounts a case description never contains, and either invented them or
    | wrote bracketed placeholders that the export strips out.
    |
    | `min_description_characters` is the length at which a description reads
    | as a narrative rather than a label. `min_document_chunks` is how much
    | extracted text an uploaded document must yield to count as a source of
    | facts; at the default 500-character chunk size, two chunks is roughly a
    | page, which a photo of an ID or a receipt never reaches.
    |
    | Note that clearing these thresholds only drops the NARRATIVE fields from
    | the form (see ChatService::dropCaseCoveredFields). The form itself is
    | suppressed only when nothing whatsoever is left to ask.
    |
    */

    'intake' => [
        'min_description_characters' => (int) env('INTAKE_MIN_DESCRIPTION_CHARS', 60),
        'min_document_chunks' => (int) env('INTAKE_MIN_DOCUMENT_CHUNKS', 2),
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
