<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\DatabaseUrl;
use SafeGrd\Laravel\SafeGrdException;

final class InstallCommand extends Command
{
    use RunsCli;

    public const DEFAULT_SERVER = 'https://safegrd.dev';

    protected $signature = 'safegrd:install
        {--token= : A personal access token (sg_pat_...) from Tokens in the SafeGrd console, as env:VAR or file:/path. Without it, a code is printed to approve in the console}
        {--key-custody=safegrd : safegrd for a SafeGrd-managed key, local for a customer-managed key}
        {--storage=hosted : hosted for SafeGrd-hosted storage, local for a directory on this host. A project with its own bucket uses that}
        {--server= : The SafeGrd server, when it is not https://safegrd.dev (default: SAFEGRD_SERVER_URL)}
        {--no-backup : Set up only, and leave the first backup to the scheduler}';

    protected $description = 'Connect this app to SafeGrd: install the CLI, sign in, add the app and take the first backup';

    public function handle(Cli $cli): int
    {
        $custody = $this->stringOption('key-custody');
        if (! in_array($custody, ['safegrd', 'local'], true)) {
            $this->stderr("Error: --key-custody is safegrd or local, not {$custody}.");

            return self::FAILURE;
        }
        $storage = $this->stringOption('storage');
        if (! in_array($storage, ['hosted', 'local'], true)) {
            $this->stderr("Error: --storage is hosted or local, not {$storage}.");

            return self::FAILURE;
        }
        $token = $this->stringOption('token');
        if ($token === '' && $this->input->hasParameterOption(['--no-interaction', '-n'], true) && ! $cli->enrolled()) {
            // The device sign-in waits for someone to approve a code, and a
            // deploy script has no one to. (Not isInteractive(): Symfony
            // turns that off whenever stdin is not a terminal, as over ssh
            // without one, where the code can still be read and approved.)
            $this->stderr('Error: with --no-interaction, pass --token=env:SAFEGRD_TOKEN with a personal access token from Tokens in the SafeGrd console.');

            return self::FAILURE;
        }
        if (($owner = Cli::otherOwner(storage_path())) !== null) {
            // The scheduler runs as the app's user, and could not read a
            // home this command creates as someone else.
            $this->stderr("Warning: storage/ belongs to {$owner}, and this runs as another user. If Laravel's scheduler runs as {$owner}, run this as {$owner} instead: sudo -u {$owner} php artisan safegrd:install");
        }

        try {
            // The connection is checked first: nothing is installed for an
            // app SafeGrd cannot back up.
            $url = $cli->databaseUrl();
            $token = self::resolveToken($token);
            $this->ensureCli($cli);
        } catch (SafeGrdException $e) {
            $this->stderr('Error: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($this->cli($cli, ['version'], false) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $server = rtrim($this->stringOption('server') ?: (string) config('safegrd.server'), '/');
        $global = $server !== '' && $server !== self::DEFAULT_SERVER ? ['--server-url', $server] : [];

        if ($cli->enrolled()) {
            $this->line('This app\'s host is enrolled already.');
        } else {
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
        if (! $cli->installed()) {
            $this->stderr('Error: safegrd init finished, but '.$cli->configPath().' has no surface for '.$cli->root().'. Run php artisan safegrd:doctor.');

            return self::FAILURE;
        }

        $scheduled = filter_var(config('safegrd.schedule'), FILTER_VALIDATE_BOOL);
        if (! $this->option('no-backup')) {
            $this->newLine();
            $this->line('Taking the first backup.');
            // With the scheduler on, this is its first run, at its cadence.
            $run = $scheduled ? ['--interval', '5m'] : [];
            if ($this->cli($cli, array_merge(['daemon', 'run', '--once'], $run)) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        if ($scheduled) {
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

    /**
     * A CLI that can set up a Laravel app. One found on PATH from before
     * that could is replaced by a download into this app's home; one named
     * by SAFEGRD_BINARY is the operator's to upgrade.
     */
    private function ensureCli(Cli $cli): void
    {
        $bin = $cli->binary();
        if ($bin === null) {
            $cli->download(fn (string $line) => $this->line($line));

            return;
        }
        if ($cli->supportsLaravel()) {
            return;
        }
        $version = $cli->version() ?? 'of an unknown version';
        if ($cli->configuredBinary() !== null) {
            throw new SafeGrdException("SAFEGRD_BINARY is {$bin}, safegrd {$version}, which cannot set up a Laravel app (it has no init --laravel). Upgrade it, or unset SAFEGRD_BINARY to have this app download its own.");
        }
        $this->line("{$bin} is safegrd {$version}, which cannot set up a Laravel app. Downloading a newer one for this app.");
        $cli->download(fn (string $line) => $this->line($line));
        if (! $cli->supportsLaravel()) {
            throw new SafeGrdException('The downloaded CLI cannot set up a Laravel app either. Set SAFEGRD_VERSION to a newer tag from '.Cli::RELEASES.'.');
        }
    }

    private function stringOption(string $name): string
    {
        $v = $this->option($name);

        return is_string($v) ? $v : '';
    }

    /** The token --token names: as given, or read from env:VAR or file:/path. */
    private static function resolveToken(string $token): string
    {
        if (str_starts_with($token, 'env:')) {
            $name = substr($token, 4);
            $value = getenv($name);
            $value = is_string($value) && $value !== '' ? $value : (string) ($_ENV[$name] ?? $_SERVER[$name] ?? '');
            if ($value === '') {
                throw new SafeGrdException("{$name} is empty or not set.");
            }

            return trim($value);
        }
        if (str_starts_with($token, 'file:')) {
            $path = substr($token, 5);
            $value = @file_get_contents($path);
            if (! is_string($value) || trim($value) === '') {
                throw new SafeGrdException("{$path} could not be read, or is empty.");
            }

            return trim($value);
        }

        return $token;
    }
}
