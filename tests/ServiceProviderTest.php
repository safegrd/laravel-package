<?php

namespace SafeGrd\Laravel\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use SafeGrd\Laravel\Cli;

final class ServiceProviderTest extends TestCase
{
    /** @return list<Event> */
    private function scheduled(): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'safegrd:run'),
        ));
    }

    public function test_the_commands_are_registered(): void
    {
        $names = array_keys($this->app->make(Kernel::class)->all());
        foreach (['safegrd:install', 'safegrd:run', 'safegrd:backup', 'safegrd:status', 'safegrd:list', 'safegrd:doctor', 'safegrd:upgrade', 'safegrd:cli'] as $c) {
            $this->assertContains($c, $names);
        }
    }

    public function test_nothing_is_scheduled_before_install(): void
    {
        $this->assertSame([], $this->scheduled());
    }

    public function test_safegrd_run_is_scheduled_every_five_minutes_once_installed_even_in_maintenance_mode(): void
    {
        $this->installed();
        $this->refreshApplication();
        $events = $this->scheduled();
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->runsInMaintenanceMode());
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(storage_path('logs/safegrd.log'), $events[0]->output);
    }

    public function test_another_apps_surface_in_a_shared_home_does_not_schedule_this_one(): void
    {
        $this->installed('other-app', '/srv/other-app');
        $this->refreshApplication();
        $this->assertSame([], $this->scheduled());
    }

    public function test_the_schedule_can_be_turned_off(): void
    {
        $this->installed();
        $this->app['config']->set('safegrd.schedule', false);
        $this->assertSame([], $this->scheduled());
    }

    public function test_the_surface_is_the_app_name_as_a_slug_before_install(): void
    {
        $this->assertSame('acme-shop', $this->app->make(Cli::class)->surface());
        $this->app['config']->set('safegrd.surface', 'shop-eu');
        $this->assertSame('shop-eu', $this->app->make(Cli::class)->surface());
    }

    public function test_a_safegrd_surface_set_after_install_keeps_the_app_set_up(): void
    {
        $this->installed('acme-shop');
        $this->app['config']->set('safegrd.surface', 'renamed');
        $cli = $this->app->make(Cli::class);
        $this->assertTrue($cli->installed());
        $this->assertSame('acme-shop', $cli->surface());
        $this->refreshApplication();
        $this->app['config']->set('safegrd.surface', 'renamed');
        $this->assertCount(1, $this->scheduled());
    }

    public function test_the_surface_is_the_configs_after_install(): void
    {
        $this->installed('shop-2');
        $this->assertSame('shop-2', $this->app->make(Cli::class)->surface());
    }
}
