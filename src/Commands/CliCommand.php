<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;

final class CliCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:cli {cli_args?* : The CLI\'s arguments, after --}';

    protected $description = 'Run the SafeGrd CLI with this app\'s setup, for example: php artisan safegrd:cli -- list';

    public function handle(): int
    {
        return $this->cli(Cli::fromConfig(), array_values((array) $this->argument('cli_args')));
    }
}
