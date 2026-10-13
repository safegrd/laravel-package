<?php

namespace SafeGrd\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\Commands\RunCommand;
use SafeGrd\Laravel\Events\BackupFailed;

final class CommandsTest extends TestCase
{
    public function test_install_with_a_token_enrolls_adds_the_app_and_backs_up(): void
    {
        putenv('SAFEGRD_TEST_TOKEN=sg_pat_secret');
        try {
            [$code, $out, $err] = $this->artisanSplit('safegrd:install', ['--token' => 'env:SAFEGRD_TEST_TOKEN']);
        } finally {
            putenv('SAFEGRD_TEST_TOKEN');
        }
        $this->assertSame(0, $code, $out.$err);
        $calls = $this->calls();
        $this->assertStringContainsString('enroll --storage hosted --key-custody safegrd --token env:SAFEGRD_INSTALL_TOKEN', $calls);
        $this->assertStringContainsString('token=sg_pat_secret', $calls);
        $this->assertStringNotContainsString('sg_pat_secret ', str_replace('token=sg_pat_secret', '', $calls), 'the token is on no command line');
        $this->assertStringContainsString('init --laravel '.base_path().' --surface-id acme-shop --database-url env:SAFEGRD_LARAVEL_DATABASE_URL', $calls);
        $this->assertStringContainsString('daemon run --once --interval 5m', $calls);
        $this->assertStringContainsString('db=sqlite://'.$this->dir.'/app.sqlite', $calls);
        $this->assertStringContainsString('every five minutes', $out);
        $this->assertTrue($this->app->make(Cli::class)->installed());
        $this->assertSame("*\n", file_get_contents($this->home.'/.gitignore'));
    }

    public function test_install_reads_the_token_from_a_file(): void
    {
        file_put_contents($this->dir.'/token', "sg_pat_fromfile\n");
        [$code, $out, $err] = $this->artisanSplit('safegrd:install', ['--token' => 'file:'.$this->dir.'/token', '--no-backup' => true]);
        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString('token=sg_pat_fromfile ', $this->calls());
        $this->assertStringNotContainsString('daemon run', $this->calls());
    }

    public function test_install_with_no_interaction_and_no_token_is_refused_before_anything_runs(): void
    {
        [$code, , $err] = $this->artisanSplit('safegrd:install', ['--no-interaction' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('pass --token=env:SAFEGRD_TOKEN', $err);
        $this->assertSame('', $this->calls());
    }

    public function test_install_refuses_a_cli_on_safegrd_binary_that_cannot_set_up_laravel(): void
    {
        $this->mode('old');
        [$code, , $err] = $this->artisanSplit('safegrd:install', ['--token' => 'sg_pat_x']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('safegrd 0.0.99, which cannot set up a Laravel app (it has no init --laravel)', $err);
        $this->assertStringNotContainsString('enroll', $this->calls());
    }

    public function test_install_talks_to_the_server_safegrd_server_url_names(): void
    {
        $this->app['config']->set('safegrd.server', 'https://eu.safegrd.example/');
        [$code, $out, $err] = $this->artisanSplit('safegrd:install', ['--token' => 'sg_pat_x', '--no-backup' => true]);
        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString('--server-url https://eu.safegrd.example enroll', $this->calls());
    }

    public function test_install_refuses_a_connection_the_cli_cannot_back_up_before_running_it(): void
    {
        $this->app['config']->set('database.connections.app', ['driver' => 'sqlsrv', 'host' => 'db', 'database' => 'x']);
        [$code, , $err] = $this->artisanSplit('safegrd:install', ['--token' => 'sg_pat_x']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('SQL Server', $err);
        $this->assertSame('', $this->calls());
    }

    public function test_backup_keeps_the_surface_id_after_the_app_is_renamed(): void
    {
        $this->installed('acme-shop');
        $this->app['config']->set('app.name', 'Acme Store');
        [$code, $out, $err] = $this->artisanSplit('safegrd:backup');
        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString('backup --surface acme-shop', $this->calls());
        $this->assertStringContainsString('Backed up acme-shop', $out);
    }

    public function test_commands_refuse_an_app_that_is_not_set_up(): void
    {
        foreach (['safegrd:backup', 'safegrd:run', 'safegrd:status'] as $command) {
            [$code, , $err] = $this->artisanSplit($command);
            $this->assertSame(1, $code, $command);
            $this->assertStringContainsString('Run php artisan safegrd:install', $err, $command);
        }
        $this->assertSame('', $this->calls());
    }

    public function test_a_failed_run_exits_with_the_clis_code_logs_an_error_and_dispatches_backup_failed(): void
    {
        $this->installed();
        $this->mode('fail');
        Event::fake([BackupFailed::class]);
        [$code, $out, $err] = $this->artisanSplit('safegrd:run');
        $this->assertSame(10, $code);
        $this->assertStringContainsString('Backing up acme-shop', $out);
        $this->assertStringContainsString('Error: Surface acme-shop: connection refused', $err);
        $this->assertStringNotContainsString('connection refused', $out, 'the CLI\'s stderr stays on stderr');
        Event::assertDispatched(BackupFailed::class, fn (BackupFailed $e) => $e->command === 'safegrd:run' && $e->surface === 'acme-shop'
            && $e->exitCode === 10 && $e->message === 'Surface acme-shop: connection refused' && in_array('Backing up acme-shop', $e->output, true));
        $log = (string) file_get_contents($this->dir.'/laravel.log');
        $this->assertStringContainsString('ERROR: SafeGrd: safegrd:run failed for acme-shop: Surface acme-shop: connection refused', $log);
    }

    public function test_a_failed_backup_dispatches_backup_failed(): void
    {
        $this->installed();
        $this->mode('fail');
        Event::fake([BackupFailed::class]);
        [$code] = $this->artisanSplit('safegrd:backup');
        $this->assertSame(10, $code);
        Event::assertDispatched(BackupFailed::class, fn (BackupFailed $e) => $e->command === 'safegrd:backup');
    }

    public function test_a_cli_killed_by_signal_is_a_failure_not_a_success(): void
    {
        $this->installed();
        $this->mode('kill');
        Event::fake([BackupFailed::class]);
        [$code, , $err] = $this->artisanSplit('safegrd:run');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('safegrd daemon was killed by signal 9', $err);
        Event::assertDispatched(BackupFailed::class, fn (BackupFailed $e) => str_contains($e->message, 'signal 9'));
    }

    public function test_a_cli_past_safegrd_timeout_is_stopped_and_a_failure(): void
    {
        $this->installed();
        $this->mode('sleep');
        $this->app['config']->set('safegrd.timeout', 1);
        $start = microtime(true);
        [$code, , $err] = $this->artisanSplit('safegrd:backup');
        $this->assertSame(1, $code);
        $this->assertLessThan(4, microtime(true) - $start);
        $this->assertStringContainsString('ran past 1 seconds and was stopped', $err);
    }

    public function test_a_binary_that_cannot_execute_says_so(): void
    {
        $this->installed();
        file_put_contents($this->fake, "\x7fELF garbage");
        [$code, , $err] = $this->artisanSplit('safegrd:backup');
        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/could not be executed|Could not start/', $err);
        $this->assertStringContainsString('noexec', $err);
    }

    public function test_status_json_is_json_on_stdout_with_the_clis_warnings_on_stderr(): void
    {
        $this->installed();
        [$code, $out, $err] = $this->artisanSplit('safegrd:status', ['--json' => true]);
        $this->assertSame(0, $code);
        $this->assertSame('acme-shop', json_decode($out, true, flags: JSON_THROW_ON_ERROR)['surfaces'][0]['id']);
        $this->assertStringContainsString('Warning: the host\'s clock is 3s off', $err);
    }

    public function test_run_moves_the_log_aside_once_past_its_limit(): void
    {
        $this->installed();
        $log = $this->app->make(Cli::class)->logPath();
        @mkdir(dirname($log), 0755, true);
        $f = fopen($log, 'w');
        ftruncate($f, RunCommand::LOG_LIMIT + 1);
        fclose($f);
        try {
            [$code] = $this->artisanSplit('safegrd:run');
            $this->assertSame(0, $code);
            $this->assertFileDoesNotExist($log);
            $this->assertSame(RunCommand::LOG_LIMIT + 1, filesize($log.'.1'));
        } finally {
            @unlink($log.'.1');
        }
    }

    public function test_cli_passes_through_with_a_broken_connection(): void
    {
        $this->app['config']->set('database.connections.app', ['driver' => 'sqlite', 'database' => ':memory:']);
        [$code, $out, $err] = $this->artisanSplit('safegrd:cli', ['cli_args' => ['list']]);
        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString('in-memory SQLite', $err);
        $this->assertStringContainsString('[]', $out);
    }

    public function test_list_passes_json_through(): void
    {
        [$code, $out] = $this->artisanSplit('safegrd:list', ['--json' => true]);
        $this->assertSame(0, $code);
        $this->assertSame('[]', trim($out));
        $this->assertStringContainsString("list --json\n", $this->calls());
    }

    public function test_doctor_passes_when_everything_is_in_place(): void
    {
        $this->installed();
        [$code, $out, $err] = $this->artisanSplit('safegrd:doctor');
        $this->assertSame(0, $code, $out.$err);
        foreach (['Connection  sqlite, file '.$this->dir.'/app.sqlite', 'CLI         '.$this->fake.', safegrd 0.0.99', 'App         surface acme-shop', 'Surface acme-shop: ok'] as $want) {
            $this->assertStringContainsString($want, $out);
        }
        $this->assertStringNotContainsString('fail', $out);
    }

    public function test_doctor_warns_when_safegrd_surface_disagrees_with_the_setup(): void
    {
        $this->installed('acme-shop');
        $this->app['config']->set('safegrd.surface', 'renamed');
        [$code, $out] = $this->artisanSplit('safegrd:doctor');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('SAFEGRD_SURFACE is renamed, and the app was set up as acme-shop', $out);
    }

    public function test_doctor_fails_before_install(): void
    {
        [$code, $out] = $this->artisanSplit('safegrd:doctor');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('fail  App', $out);
        $this->assertStringNotContainsString("doctor\n", $this->calls(), 'the CLI\'s doctor is not run for an app that is not set up');
    }

    public function test_doctor_names_a_cli_too_old_for_laravel(): void
    {
        $this->installed();
        $this->mode('old');
        [$code, $out] = $this->artisanSplit('safegrd:doctor');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('cannot back up a Laravel app. Run php artisan safegrd:upgrade', $out);
    }

    public function test_upgrade_leaves_safegrd_binary_to_the_operator(): void
    {
        [$code, , $err] = $this->artisanSplit('safegrd:upgrade');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('SAFEGRD_BINARY is '.$this->fake, $err);
    }

    public function test_about_has_a_safegrd_section(): void
    {
        $this->installed();
        [$code, $out] = $this->artisanSplit('about', ['--only' => 'safegrd']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('acme-shop', $out);
        $this->assertStringContainsString('every five minutes', $out);
    }
}
