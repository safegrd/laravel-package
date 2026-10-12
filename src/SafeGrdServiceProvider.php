<?php

namespace SafeGrd\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class SafeGrdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safegrd.php', 'safegrd');
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
            Commands\CliCommand::class,
        ]);

        // Every five minutes, once the app is set up. safegrd:run exits at
        // once when nothing is due; the CLI keeps the schedule.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! filter_var(config('safegrd.schedule'), FILTER_VALIDATE_BOOL) || ! Cli::fromConfig()->installed()) {
                return;
            }
            $schedule->command('safegrd:run')
                ->everyFiveMinutes()
                ->withoutOverlapping(180)
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/safegrd.log'));
        });
    }
}
