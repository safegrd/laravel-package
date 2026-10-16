<?php

namespace SafeGrd\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class SafeGrdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safegrd.php', 'safegrd');
        // Built from config on each resolve, so a config change (a test's,
        // or one made at runtime) is what the next command sees.
        $this->app->bind(Cli::class, fn () => Cli::fromConfig());
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->publishes([__DIR__.'/../config/safegrd.php' => config_path('safegrd.php')], 'safegrd-config');
        $this->commands([
            Commands\InstallCommand::class,
            Commands\RunCommand::class,
            Commands\BackupCommand::class,
            Commands\StatusCommand::class,
            Commands\ListCommand::class,
            Commands\DoctorCommand::class,
            Commands\UpgradeCommand::class,
            Commands\CliCommand::class,
        ]);

        // Every five minutes, once the app is set up. safegrd:run exits at
        // once when nothing is due; the CLI keeps the schedule. It runs in
        // maintenance mode too: an app left down by a failed deploy is one
        // whose backups matter.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $cli = $this->app->make(Cli::class);
            if (! filter_var(config('safegrd.schedule'), FILTER_VALIDATE_BOOL) || ! $cli->installed()) {
                return;
            }
            $schedule->command('safegrd:run')
                ->everyFiveMinutes()
                ->withoutOverlapping(180)
                ->runInBackground()
                ->evenInMaintenanceMode()
                ->appendOutputTo($cli->logPath());
        });

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('SafeGrd', fn () => $this->about());
        }
    }

    /**
     * The current container's Cli, not $this->app's: in Laravel 10.28 and older,
     * AboutCommand keeps every closure added in the process, so a test suite
     * calls this on providers whose app is gone.
     *
     * @return array<string, string>
     */
    private function about(): array
    {
        $cli = app(Cli::class);
        try {
            $bin = $cli->binary() ?? '<fg=yellow>not installed</>';
        } catch (Throwable $e) {
            $bin = '<fg=red>'.$e->getMessage().'</>';
        }
        $entry = $cli->entry();

        return [
            'App' => $entry !== null ? $entry['id'] : '<fg=yellow>not set up (php artisan safegrd:install)</>',
            'CLI' => $bin,
            'Home' => $cli->home(),
            'Host' => $cli->enrolled() ? 'enrolled' : '<fg=yellow>not enrolled</>',
            'Schedule' => ! filter_var(config('safegrd.schedule'), FILTER_VALIDATE_BOOL) ? 'off'
                : ($entry !== null ? 'every five minutes' : 'after safegrd:install'),
        ];
    }
}
