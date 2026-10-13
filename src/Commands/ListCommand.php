<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;

final class ListCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:list {--json : Print the backups as JSON}';

    protected $description = 'List the backups, with the ids safegrd:cli -- restore takes';

    public function handle(Cli $cli): int
    {
        return $this->cli($cli, $this->option('json') ? ['list', '--json'] : ['list'], false);
    }
}
