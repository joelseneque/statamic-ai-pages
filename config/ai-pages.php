<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic API
    |--------------------------------------------------------------------------
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

    // Cheaper model used for mechanical work (summarising source docs, digests).
    'fast_model' => env('ANTHROPIC_FAST_MODEL', env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001')),

    'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

    'api_version' => '2023-06-01',

    'max_tokens' => (int) env('AI_PAGES_MAX_TOKENS', 16000),

    'timeout' => (int) env('AI_PAGES_TIMEOUT', 300),

    'retries' => (int) env('AI_PAGES_RETRIES', 3),

    // Prompt caching keeps the (large) schema + instruction blocks cheap across
    // the many calls a single page build makes. Set false to disable.
    'prompt_caching' => true,

    /*
    |--------------------------------------------------------------------------
    | Where things live
    |--------------------------------------------------------------------------
    |
    | Instructions are plain Markdown so they can be committed, diffed and
    | hand-edited. Job records are JSON under storage.
    |
    */

    'instructions_path' => resource_path('ai-pages'),

    'storage_path' => storage_path('ai-pages'),

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Which collections AI Pages may build into. ['*'] for all.
    |
    */

    'collections' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Build behaviour
    |--------------------------------------------------------------------------
    */

    // New entries are always created unpublished unless you flip this.
    'publish_new_entries' => false,

    // How many of the site's own entries to show the model as worked examples.
    'exemplars_per_collection' => 3,

    // Hydrate this many sets concurrently. 1 = sequential.
    'concurrency' => (int) env('AI_PAGES_CONCURRENCY', 4),

    // Queue connection for build jobs. null runs them inline.
    'queue' => env('AI_PAGES_QUEUE'),

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    */

    'sources' => [
        'max_upload_mb' => 30,
        'allowed_extensions' => [
            'pdf', 'docx', 'doc', 'txt', 'md', 'markdown', 'rtf', 'html', 'htm',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'csv',
        ],
        // Fetching arbitrary URLs is opt-out-able for locked-down installs.
        'allow_url_fetch' => true,
        'url_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fidelity guard
    |--------------------------------------------------------------------------
    |
    | In "verbatim" mode every emitted sentence is checked back against the
    | source. Below this similarity ratio a section is flagged for review.
    |
    */

    'verbatim_threshold' => 0.92,

];
