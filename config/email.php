<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Email Sending Configuration
    |--------------------------------------------------------------------------
    */

    // Threshold for auto-batching (if recipients > this, use batch job)
    'batch_threshold' => env('EMAIL_BATCH_THRESHOLD', 10),

    // Chunk size for bulk email batches
    'batch_chunk_size' => env('EMAIL_BATCH_CHUNK_SIZE', 50),

    // Rate limit per user (emails per minute)
    'rate_limit' => env('EMAIL_RATE_LIMIT', 30),

    // Delay between bulk job batches (seconds)
    'batch_delay_seconds' => env('EMAIL_BATCH_DELAY', 30),

    /*
    |--------------------------------------------------------------------------
    | Email Sync Configuration
    |--------------------------------------------------------------------------
    */

    // Number of emails to fetch per folder during initial seed (Phase 1)
    'seed_count' => env('EMAIL_SEED_COUNT', 50),

    // Chunk size for full sync (Phase 2)
    'chunk_size' => env('EMAIL_CHUNK_SIZE', 100),

    // Preview snippet length (characters)
    'preview_length' => env('EMAIL_PREVIEW_LENGTH', 200),

    // Sync log retention (days)
    'sync_log_retention_days' => env('EMAIL_SYNC_LOG_RETENTION', 7),

    /*
    |--------------------------------------------------------------------------
    | Provider-Specific Parallel Limits
    |--------------------------------------------------------------------------
    | Maximum number of concurrent folder syncs per provider.
    | Gmail/Outlook are stricter; custom IMAP servers can handle more.
    */

    'max_parallel_folders' => [
        'gmail' => env('EMAIL_MAX_PARALLEL_GMAIL', 2),
        'outlook' => env('EMAIL_MAX_PARALLEL_OUTLOOK', 2),
        'custom' => env('EMAIL_MAX_PARALLEL_CUSTOM', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | IMAP Folder Mapping
    |--------------------------------------------------------------------------
    | Maps local folder types to provider-specific IMAP folder names.
    */

    'imap_folders' => [
        'gmail' => [
            'inbox' => 'INBOX',
            'sent' => '[Gmail]/Sent Mail',
            'drafts' => '[Gmail]/Drafts',
            'trash' => '[Gmail]/Trash',
            'spam' => '[Gmail]/Spam',
            'archive' => '[Gmail]/All Mail',
            'starred' => '[Gmail]/Starred',
            'important' => '[Gmail]/Important',
        ],
        // Alternative Gmail folder names (some locales)
        'gmail_alternatives' => [
            'sent' => ['[Gmail]/Sent Mail', '[Google Mail]/Sent Mail', 'Sent', 'Sent Messages'],
            'drafts' => ['[Gmail]/Drafts', '[Google Mail]/Drafts', 'Drafts'],
            'trash' => ['[Gmail]/Trash', '[Google Mail]/Trash', 'Trash', 'Deleted Messages'],
            'spam' => ['[Gmail]/Spam', '[Google Mail]/Spam', 'Spam', 'Junk'],
            'archive' => ['[Gmail]/All Mail', '[Google Mail]/All Mail', 'Archive'],
        ],
        'outlook' => [
            'inbox' => 'INBOX',
            'sent' => 'Sent Items',
            'drafts' => 'Drafts',
            'trash' => 'Deleted Items',
            'spam' => 'Junk Email',
            'archive' => 'Archive',
            'notes' => 'Notes',
        ],
        // Outlook.com / Hotmail alternatives
        'outlook_alternatives' => [
            'sent' => ['Sent Items', 'Sent', 'Sent Messages'],
            'trash' => ['Deleted Items', 'Deleted', 'Trash'],
            'spam' => ['Junk Email', 'Junk', 'Spam'],
        ],
        'yahoo' => [
            'inbox' => 'INBOX',
            'sent' => 'Sent',
            'drafts' => 'Draft',
            'trash' => 'Trash',
            'spam' => 'Bulk Mail',
            'archive' => 'Archive',
        ],
        'icloud' => [
            'inbox' => 'INBOX',
            'sent' => 'Sent Messages',
            'drafts' => 'Drafts',
            'trash' => 'Deleted Messages',
            'spam' => 'Junk',
            'archive' => 'Archive',
        ],
        'zoho' => [
            'inbox' => 'INBOX',
            'sent' => 'Sent',
            'drafts' => 'Drafts',
            'trash' => 'Trash',
            'spam' => 'Spam',
            'archive' => 'Archives',
        ],
        'custom' => [
            'inbox' => 'INBOX',
            'sent' => 'Sent',
            'drafts' => 'Drafts',
            'trash' => 'Trash',
            'spam' => 'Spam',
            'archive' => 'Archive',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Configuration
    |--------------------------------------------------------------------------
    */

    'jobs' => [
        'send' => [
            'queue' => env('EMAIL_QUEUE', 'emails'),
            'tries' => 5,
            'timeout' => 120, // 2 minutes
            'backoff' => 60, // 1 minute
            'retry_until_hours' => 4,
        ],
        'bulk_send' => [
            'queue' => env('EMAIL_QUEUE', 'emails'),
            'tries' => 3,
            'timeout' => 600, // 10 minutes
            'retry_until_hours' => 8,
        ],
        'sync' => [
            'queue' => env('EMAIL_SYNC_QUEUE', 'emails'),
            'tries' => 3,
            'timeout' => 300, // 5 minutes per chunk
            'retry_until_hours' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler Configuration
    |--------------------------------------------------------------------------
    */

    'scheduler' => [
        // Watchdog checks for pending/stalled syncs
        'watchdog_interval' => env('EMAIL_WATCHDOG_INTERVAL', 1), // minutes

        // Forward crawler interval (new emails)
        'forward_interval' => env('EMAIL_FORWARD_INTERVAL', 2), // minutes

        // Incremental sync for completed accounts (legacy)
        'incremental_interval' => env('EMAIL_INCREMENTAL_INTERVAL', 5), // minutes
    ],
];
