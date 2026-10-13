<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\SafeGrdException;

final class CliCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:cli {cli_args?* : The CLI\'s arguments, after --}';

    protected $description = 'Run the SafeGrd CLI with this app\'s setup, for example: php artisan safegrd:cli -- list';

    public function handle(Cli $cli): int
    {
        // A connection the CLI cannot use stops a backup, but not a list or
        // a restore into another database.
        $database = true;
        try {
            $cli->databaseUrl();
        } catch (SafeGrdException $e) {
            $this->stderr('Warning: '.$e->getMessage().' The CLI runs without the app\'s connection.');
            $database = false;
        }

        return $this->cli($cli, array_values(array_map('strval', (array) $this->argument('cli_args'))), $database);
    }
}
