<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;

final class RunCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:run';

    protected $description = 'Back up the app when its schedule says so, and test-restore it when a drill is due (Laravel\'s scheduler runs this)';

    public function handle(): int
    {
        return $this->cli(Cli::fromConfig(), ['daemon', 'run', '--once', '--interval', '5m']);
    }
}
