<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding configuration
    |--------------------------------------------------------------------------
    |
    | The provider and model used to embed text. The dimensions must match the
    | vector columns in the database (document_chunks.embedding and
    | legal_chunks.embedding). qwen3-embedding currently emits 768 dimensions,
    | so EMBEDDING_DIMENSIONS (and the halfvec columns) must be 768.
    |
    */

    'embedding' => [
        'provider' => env('AI_EMBED_PROVIDER', 'ollama'),
        'model' => env('OLLAMA_EMBED_MODEL', 'qwen3-embedding:latest'),
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
        'provider' => env('AI_CHAT_PROVIDER', 'ollama'),
        'ollama_model' => env('OLLAMA_CHAT_MODEL', 'qwen3.6:latest'),
        'ollama_model_alt' => env('OLLAMA_CHAT_MODEL_ALT', 'qwen3.5:latest'),
        'gemini_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash'),
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
