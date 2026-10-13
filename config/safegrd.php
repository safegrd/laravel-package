<?php

return [

    // Register safegrd:run with Laravel's scheduler, every five minutes. It
    // backs the app up when its schedule says so and runs the restore test
    // (the drill) when one is due; between those it exits at once. Set it to
    // false to schedule safegrd:run yourself, or to run the CLI as a service.
    'schedule' => env('SAFEGRD_SCHEDULE', true),

    // The surface's id in the CLI's config and in the console. Unset, it is
    // APP_NAME as a slug: set it to the current id before renaming the app.
    'surface' => env('SAFEGRD_SURFACE'),

    // The database connection to back up. Unset, it is the default one.
    'connection' => env('SAFEGRD_CONNECTION'),

    // Where the CLI keeps its config, its key and its state. Unset, it is
    // storage/safegrd (through storage's link to a shared directory, where
    // there is one). It is outside storage/app, so the key is not in the
    // backups it opens.
    'home' => env('SAFEGRD_HOME'),

    // A safegrd binary already on this host. Unset, safegrd:install
    // downloads the release named by 'version' into home/bin and checks it
    // against the release's checksums.txt.
    'binary' => env('SAFEGRD_BINARY'),
    'version' => env('SAFEGRD_VERSION', 'latest'),

    // The SafeGrd server.
    'server' => env('SAFEGRD_SERVER_URL', 'https://safegrd.dev'),

    // Seconds a run of the CLI may take before it is stopped. Unset, there
    // is no limit: a large first backup can take hours.
    'timeout' => env('SAFEGRD_TIMEOUT'),

    // The log channel a failed backup or run is written to, as an error, so
    // the error tracker the app reports to (Sentry, Flare, Bugsnag) sees it.
    // Unset, it is the app's default channel. The CLI's own output goes to
    // storage/logs/safegrd.log either way.
    'log_channel' => env('SAFEGRD_LOG_CHANNEL'),

];
