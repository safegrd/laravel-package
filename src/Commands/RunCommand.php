<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;

final class RunCommand extends Command
{
    use RunsCli;

    /** The log is moved to safegrd.log.1 past this size, so it stays bounded. */
    public const LOG_LIMIT = 5 * 1024 * 1024;

    protected $signature = 'safegrd:run';

    protected $description = 'Back up the app when its schedule says so, and test-restore it when a drill is due (Laravel\'s scheduler runs this)';

    public function handle(Cli $cli): int
    {
        if (! $cli->installed()) {
            $this->stderr('Error: the app is not set up for SafeGrd yet. Run php artisan safegrd:install.');

            return self::FAILURE;
        }
        $this->rotateLog($cli->logPath());
        $this->line('['.date('Y-m-d H:i:s T').'] safegrd:run');
        $code = $this->cli($cli, ['daemon', 'run', '--once', '--interval', '5m']);
        if ($code !== self::SUCCESS) {
            $this->reportFailure($cli, $code);
        }

        return $code;
    }

    /**
     * Moves the log aside once it passes LOG_LIMIT, over the one moved
     * before. A run whose output is already going to the old file finishes
     * there; the next run starts a new one.
     */
    private function rotateLog(string $log): void
    {
        clearstatcache(true, $log);
        if (is_file($log) && filesize($log) > self::LOG_LIMIT) {
            @rename($log, $log.'.1');
        }
    }
}
