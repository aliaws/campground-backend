<?php

/*
| Usage measurement for usage-based billing (superadmin).
| See docs/usage-billing.md.
*/

return [

    'storage' => [
        /*
         * Filesystem disks (config/filesystems.php) the app stores uploads on.
         * Every file under each disk's root is scanned (metadata only, no file
         * contents read). `public` holds every upload today; `local`
         * (storage/app/private) is scanned so files that ever appear there are
         * reported instead of silently ignored. Add an object-storage disk
         * (e.g. `s3`) here only once the app actually writes to it.
         */
        'disks' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('USAGE_STORAGE_DISKS', 'public,local'))
        ))),

        /* The disk whose files the database references (/storage/... URLs). */
        'reference_disk' => env('USAGE_STORAGE_REFERENCE_DISK', 'public'),

        /*
         * Files never billed to anyone: OS metadata files, dotfiles such as
         * .gitignore, temporary/partial uploads. Matched against the file name.
         */
        'excluded_names' => ['.DS_Store', 'Thumbs.db', 'desktop.ini', '.gitignore', '.gitkeep'],
        'excluded_patterns' => ['/^\./', '/\.(tmp|part|partial|swp)$/i', '/~$/'],
    ],

    'database' => [
        /*
         * Tables never attributed to an organization: framework tables,
         * platform-wide content and configuration, and the billing tables
         * themselves (an organization isn't charged for its own billing data).
         */
        'excluded_tables' => [
            'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
            'password_reset_tokens', 'personal_access_tokens',
            'cms_pages', 'countries', 'engage_settings', 'engage_menu_items',
            'custom_scripts', 'custom_script_organizations', 'webhook_logs', 'amenities', 'features',
            'pricing_plans', 'pricing_plan_metrics', 'organization_usage_snapshots', 'usage_measurement_runs',
        ],

        /* Per-statement time limit while measuring (milliseconds). */
        'statement_timeout_ms' => (int) env('USAGE_DATABASE_STATEMENT_TIMEOUT_MS', 60000),
    ],

    /* A month's data counts as stale when its newest snapshot is older than this many days. */
    'stale_after_days' => (int) env('USAGE_STALE_AFTER_DAYS', 2),

    /* How long one measurement run may hold the lock (seconds). */
    'lock_seconds' => (int) env('USAGE_SNAPSHOT_LOCK_SECONDS', 1800),

];
