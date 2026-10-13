<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\SafeGrdException;

final class UpgradeCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:upgrade';

    protected $description = 'Download the SafeGrd CLI release SAFEGRD_VERSION names (the latest by default) and put it in place of this app\'s copy';

    public function handle(Cli $cli): int
    {
        if (($configured = $cli->configuredBinary()) !== null) {
            $this->stderr("Error: SAFEGRD_BINARY is {$configured}. Upgrade it the way it was installed, or unset SAFEGRD_BINARY to have this app download its own.");

            return self::FAILURE;
        }
        $before = $cli->binary() !== null ? $cli->version() : null;
        try {
            $cli->download(fn (string $line) => $this->line($line));
        } catch (SafeGrdException $e) {
            $this->stderr('Error: '.$e->getMessage());

            return self::FAILURE;
        }
        $after = $cli->version();
        if ($after === null) {
            $this->stderr('Error: the downloaded CLI at '.$cli->downloadedBinary().' does not run on this host.');

            return self::FAILURE;
        }
        $this->line($before === null ? "safegrd {$after}" : ($before === $after ? "safegrd {$after}, unchanged" : "safegrd {$before} to {$after}"));

        return self::SUCCESS;
    }
}
