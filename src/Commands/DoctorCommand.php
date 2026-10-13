<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Console\Command;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\SafeGrdException;

final class DoctorCommand extends Command
{
    use RunsCli;

    protected $signature = 'safegrd:doctor';

    protected $description = 'Check this app\'s SafeGrd setup: the connection, the CLI, its home and the schedule, then the CLI\'s own checks';

    private bool $failed = false;

    public function handle(Cli $cli): int
    {
        $database = true;
        try {
            $url = $cli->databaseUrl();
            $this->ok('Connection', self::describe($url));
        } catch (SafeGrdException $e) {
            $this->bad('Connection', $e->getMessage());
            $database = false;
        }

        $bin = null;
        try {
            $bin = $cli->binary();
            if ($bin === null) {
                $this->bad('CLI', 'not installed. Run php artisan safegrd:install.');
            }
        } catch (SafeGrdException $e) {
            $this->bad('CLI', $e->getMessage());
        }
        if ($bin !== null) {
            $version = $cli->version();
            if ($version === null) {
                $this->bad('CLI', "{$bin} does not run on this host. Run php artisan safegrd:upgrade, or set SAFEGRD_BINARY.");
                $bin = null;
            } elseif (! $cli->supportsLaravel()) {
                $this->bad('CLI', "{$bin} is safegrd {$version}, which cannot back up a Laravel app. Run php artisan safegrd:upgrade.");
            } else {
                $this->ok('CLI', "{$bin}, safegrd {$version}");
            }
        }

        $home = $cli->home();
        $owner = Cli::otherOwner($home);
        if (! is_dir($home)) {
            $this->bad('Home', "{$home} does not exist. Run php artisan safegrd:install.");
        } elseif (! is_writable($home)) {
            $this->bad('Home', "{$home} is not writable by this user".($owner !== null ? ", it belongs to {$owner}. Run as {$owner}: sudo -u {$owner} php artisan safegrd:doctor" : '.'));
        } elseif ($owner !== null) {
            $this->warnRow('Home', "{$home} belongs to {$owner}, not to this user. The scheduler has to run as a user that can write it.");
        } else {
            $this->ok('Home', $home);
        }

        $entry = $cli->entry();
        if ($entry === null) {
            $this->bad('App', 'not set up: '.$cli->configPath().' has no surface for '.$cli->root().'. Run php artisan safegrd:install.');
        } else {
            $this->ok('App', "surface {$entry['id']}, ".$cli->root());
            $configured = (string) config('safegrd.surface');
            if ($configured !== '' && $configured !== $entry['id']) {
                $this->warnRow('Surface', "SAFEGRD_SURFACE is {$configured}, and the app was set up as {$entry['id']}, which is the id it keeps. SAFEGRD_SURFACE names a surface only at install.");
            }
            if (! $cli->enrolled()) {
                $this->warnRow('Host', 'not enrolled, so backups are not reported to the SafeGrd console. Run php artisan safegrd:install.');
            }
        }

        if (! filter_var(config('safegrd.schedule'), FILTER_VALIDATE_BOOL)) {
            $this->warnRow('Schedule', 'SAFEGRD_SCHEDULE is off. Run php artisan safegrd:run every few minutes yourself.');
        } elseif ($entry !== null) {
            $this->ok('Schedule', 'safegrd:run every five minutes, once schedule:run runs from cron');
        }
        $logs = dirname($cli->logPath());
        if (! is_writable($logs)) {
            $this->bad('Log', "{$logs} is not writable, so the scheduled run has nowhere to log.");
        }

        if ($bin === null || $entry === null) {
            return self::FAILURE;
        }
        $this->newLine();
        $code = $this->cli($cli, ['doctor'], $database);

        return $this->failed || $code !== self::SUCCESS ? self::FAILURE : self::SUCCESS;
    }

    private function ok(string $what, string $detail): void
    {
        $this->line(sprintf('  <fg=green>ok</>    %-11s %s', $what, $detail));
    }

    private function warnRow(string $what, string $detail): void
    {
        $this->line(sprintf('  <fg=yellow>warn</>  %-11s %s', $what, $detail));
    }

    private function bad(string $what, string $detail): void
    {
        $this->failed = true;
        $this->line(sprintf('  <fg=red>fail</>  %-11s %s', $what, $detail));
    }

    /** A database URL without its password. */
    private static function describe(string $url): string
    {
        $scheme = (string) strstr($url, '://', true);
        if ($scheme === 'sqlite') {
            return 'sqlite, file '.substr($url, strlen('sqlite://'));
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $db = rawurldecode(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));

        return "{$scheme}, database {$db} on {$host}".($port ? ":{$port}" : '');
    }
}
