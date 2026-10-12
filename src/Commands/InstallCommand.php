<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\DatabaseUrl;
use SafeGrd\Laravel\SafeGrdException;

final class InstallCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:install
        {--token= : A personal access token (sg_pat_...) from Tokens in the SafeGrd console, or env:VAR to read it from a variable. Without it, a code is printed to approve in the console}
        {--key-custody=safegrd : safegrd for a SafeGrd-managed key, local for a customer-managed key}
        {--storage=hosted : hosted for SafeGrd-hosted storage, local for a directory on this host. A project with its own bucket uses that}
        {--server= : The SafeGrd server, when it is not https://safegrd.dev}
        {--no-backup : Set up only, and leave the first backup to the scheduler}';

    protected $description = 'Connect this app to SafeGrd: install the CLI, sign in, add the app and take the first backup';

    public function handle(): int
    {
        $cli = Cli::fromConfig();
        $custody = (string) $this->option('key-custody');
        if (! in_array($custody, ['safegrd', 'local'], true)) {
            $this->error("Error: --key-custody is safegrd or local, not {$custody}.");

            return self::FAILURE;
        }
        $storage = (string) $this->option('storage');
        if (! in_array($storage, ['hosted', 'local'], true)) {
            $this->error("Error: --storage is hosted or local, not {$storage}.");

            return self::FAILURE;
        }

        try {
            // The connection is checked first: nothing is installed for an
            // app SafeGrd cannot back up.
            $url = $cli->databaseUrl();
            if ($cli->binary() === null) {
                $cli->download(fn (string $line) => $this->line($line));
            }
        } catch (SafeGrdException $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($this->cli($cli, ['version'], false) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $server = (string) $this->option('server');
        $global = $server !== '' ? ['--server-url', rtrim($server, '/')] : [];

        if ($cli->enrolled()) {
            $this->line('This app\'s host is enrolled already.');
        } else {
            $token = (string) $this->option('token');
            if (str_starts_with($token, 'env:')) {
                $token = (string) getenv(substr($token, 4));
                if ($token === '') {
                    $this->error('Error: '.substr((string) $this->option('token'), 4).' is empty or not set.');

                    return self::FAILURE;
                }
            }
            $env = [];
            $enroll = array_merge($global, ['enroll', '--storage', $storage, '--key-custody', $custody]);
            if ($token !== '') {
                // In the environment, so the token is not on a command line.
                $env['SAFEGRD_INSTALL_TOKEN'] = $token;
                $enroll = array_merge($enroll, ['--token', 'env:SAFEGRD_INSTALL_TOKEN']);
            } elseif ($this->cli($cli, array_merge($global, ['login', '--no-browser']), false) !== self::SUCCESS) {
                return self::FAILURE;
            }
            if ($this->cli($cli, $enroll, false, $env) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $init = ['init', '--laravel', $cli->root(), '--surface-id', $cli->surface(), '--database-url', 'env:'.Cli::DATABASE_ENV];
        if ($table = DatabaseUrl::migrationsTable(config('database.migrations'))) {
            $init = array_merge($init, ['--migrations-table', $table]);
        }
        if ($this->cli($cli, $init, true, [Cli::DATABASE_ENV => $url]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if (! $this->option('no-backup')) {
            $this->newLine();
            $this->line('Taking the first backup.');
            // With the scheduler on, this is its first run, at its cadence.
            $run = filter_var(config('safegrd.schedule'), FILTER_VALIDATE_BOOL) ? ['--interval', '5m'] : [];
            if ($this->cli($cli, array_merge(['daemon', 'run', '--once'], $run)) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        if (filter_var(config('safegrd.schedule'), FILTER_VALIDATE_BOOL)) {
            $this->line('Laravel\'s scheduler runs php artisan safegrd:run every five minutes. It backs the app up when');
            $this->line('it is due, test-restores it when a drill is due, and logs to storage/logs/safegrd.log.');
            $this->line('The scheduler needs its cron entry on this host:');
            $this->line('   * * * * * cd '.$cli->root().' && php artisan schedule:run >> /dev/null 2>&1');
        } else {
            $this->line('SAFEGRD_SCHEDULE is off: run php artisan safegrd:run every few minutes yourself.');
        }
        $this->line('php artisan safegrd:status shows the last backup and drill. The console alerts you when a backup is overdue or a drill fails.');

        return self::SUCCESS;
    }
}
