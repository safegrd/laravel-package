<?php

namespace SafeGrd\Laravel\Events;

/**
 * safegrd:backup or a scheduled safegrd:run did not finish cleanly: the CLI
 * exited non-zero, could not start, or was stopped. The SafeGrd console
 * alerts on the same failure; listen for this to act on it inside the app.
 */
final class BackupFailed
{
    /**
     * @param  string  $command  the artisan command: safegrd:backup or safegrd:run
     * @param  string  $surface  the app's surface id
     * @param  int  $exitCode  the CLI's exit code: 10 the database or files, 11 the SafeGrd server, 12 the storage, 1 anything else
     * @param  string  $message  the CLI's last error line, or why it did not run
     * @param  list<string>  $output  the last lines the CLI printed
     */
    public function __construct(
        public readonly string $command,
        public readonly string $surface,
        public readonly int $exitCode,
        public readonly string $message,
        public readonly array $output,
    ) {}
}
