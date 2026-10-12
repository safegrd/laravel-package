<?php

namespace SafeGrd\Laravel\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\TestCase;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\SafeGrdServiceProvider;

final class ServiceProviderTest extends TestCase
{
    private string $home;

    protected function getPackageProviders($app): array
    {
        return [SafeGrdServiceProvider::class];
    }

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/safegrd-test-'.bin2hex(random_bytes(4));
        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('safegrd.home', $this->home);
        $app['config']->set('app.name', 'Acme Shop');
    }

    protected function tearDown(): void
    {
        @unlink($this->home.'/.safegrd/config.yaml');
        @rmdir($this->home.'/.safegrd');
        @unlink($this->home.'/.gitignore');
        @rmdir($this->home);
        parent::tearDown();
    }

    private function scheduled(): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'safegrd:run'),
        ));
    }

    public function testTheCommandsAreRegistered(): void
    {
        $names = array_keys($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all());
        foreach (['safegrd:install', 'safegrd:run', 'safegrd:backup', 'safegrd:status', 'safegrd:cli'] as $c) {
            $this->assertContains($c, $names);
        }
    }

    public function testNothingIsScheduledBeforeInstall(): void
    {
        $this->assertSame([], $this->scheduled());
    }

    public function testSafegrdRunIsScheduledEveryFiveMinutesOnceInstalled(): void
    {
        mkdir($this->home.'/.safegrd', 0700, true);
        file_put_contents($this->home.'/.safegrd/config.yaml', "surfaces:\n  - id: acme-shop\n    type: laravel\n");
        $this->refreshApplication();
        $events = $this->scheduled();
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
    }

    public function testTheScheduleCanBeTurnedOff(): void
    {
        mkdir($this->home.'/.safegrd', 0700, true);
        file_put_contents($this->home.'/.safegrd/config.yaml', "surfaces:\n  - id: acme-shop\n    type: laravel\n");
        $this->app['config']->set('safegrd.schedule', false);
        $this->assertSame([], $this->scheduled());
    }

    public function testTheSurfaceIsTheAppNameAsASlug(): void
    {
        $this->assertSame('acme-shop', Cli::fromConfig()->surface());
        $this->app['config']->set('safegrd.surface', 'shop-eu');
        $this->assertSame('shop-eu', Cli::fromConfig()->surface());
    }
}
