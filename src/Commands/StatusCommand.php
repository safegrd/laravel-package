<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;

final class StatusCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:status {--json : Print the status as JSON}';

    protected $description = 'Show the last backup, the next one due and the last drill';

    public function handle(): int
    {
        $cli = Cli::fromConfig();
        if (! $cli->installed()) {
            $this->error('Error: the app is not set up for SafeGrd yet. Run php artisan safegrd:install.');

            return self::FAILURE;
        }

        return $this->cli($cli, $this->option('json') ? ['daemon', 'status', '--json'] : ['daemon', 'status'], false);
    }
}
