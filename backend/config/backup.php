<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup disk
    |--------------------------------------------------------------------------
    | The private Laravel filesystem disk used to store backup archives.
    | Backups MUST never live under public/ or be web-served.
    */
    'disk' => env('BACKUP_DISK', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Backup type toggles
    |--------------------------------------------------------------------------
    */
    'database' => [
        'enabled' => env('BACKUP_DATABASE_ENABLED', true),
        // Binary used to dump/restore the database. `null` auto-detects from PATH.
        'dump_binary' => env('BACKUP_MYSQLDUMP_PATH'),
        'restore_binary' => env('BACKUP_MYSQL_PATH'),
        // Disable TLS for the CLI tools when the server has no valid certs.
        'ssl' => env('BACKUP_DB_SSL', false),
        // Operational/audit tables owned by the backup subsystem itself are
        // excluded from the dump/restore so backup records and the live audit
        // trail survive a restore (restoring them would clobber the very
        // records created by the restore). Never put business tables here.
        'ignore_tables' => ['backups', 'backup_settings', 'audit_logs'],
    ],

    'storage' => [
        'enabled' => env('BACKUP_STORAGE_ENABLED', true),
        // Relative paths (inside storage/app) included in a storage backup.
        // vendor/, node_modules/, cache, logs and framework temp are excluded automatically.
        'include' => [
            'private',
            'private',
            'public',
        ],
        // Additional absolute globs/patterns excluded from the archive.
        'exclude' => [
            '*/cache/*',
            '*/logs/*',
            'framework/cache/*',
            'framework/sessions/*',
            'framework/testing/*',
            'framework/laravel-excel/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Keep the last N successful (non-safety) backups. null = manual retention
    | only (nothing is ever auto-deleted). Auto-pruning requires the
    | backup:prune command to be scheduled / run.
    */
    'retention' => [
        'keep_last' => env('BACKUP_RETENTION_KEEP_LAST'),
        'auto_prune' => env('BACKUP_AUTO_PRUNE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety & operational guards
    |--------------------------------------------------------------------------
    */
    // Minimum free disk space (MB) required before creating a backup.
    'min_free_space_mb' => env('BACKUP_MIN_FREE_SPACE_MB', 200),

    // If a pre-restore safety backup cannot be created, block the restore
    // unless this emergency override is explicitly enabled.
    'emergency_restore_without_safety' => env('BACKUP_EMERGENCY_RESTORE', false),

    // Word the Super Admin must type to confirm a destructive restore.
    'restore_confirmation_word' => env('BACKUP_RESTORE_CONFIRM_WORD', 'RESTORE'),

    // Application version recorded alongside each backup (compat checks).
    'application_version' => env('APP_VERSION', '1.0.0'),
];
