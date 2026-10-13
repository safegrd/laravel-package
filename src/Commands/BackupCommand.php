<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;

final class BackupCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:backup';

    protected $description = 'Back up the app now: its database, .env and storage/app';

    public function handle(Cli $cli): int
    {
        if (! $cli->installed()) {
            $this->stderr('Error: the app is not set up for SafeGrd yet. Run php artisan safegrd:install.');

            return self::FAILURE;
        }
        $code = $this->cli($cli, ['backup', '--surface', $cli->surface()]);
        if ($code !== self::SUCCESS) {
            $this->reportFailure($cli, $code);
        }

        return $code;
    }
}
