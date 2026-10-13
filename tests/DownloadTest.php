<?php

namespace SafeGrd\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use PharData;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\SafeGrdException;

final class DownloadTest extends TestCase
{
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
        $arch = in_array(strtolower(php_uname('m')), ['aarch64', 'arm64'], true) ? 'arm64' : 'amd64';
        $this->name = "safegrd_0.0.99_{$os}_{$arch}";
        $this->app['config']->set('safegrd.binary', null);
    }

    /** A release archive holding $script as the CLI, the way the release workflow packs it. */
    private function tarball(string $script): string
    {
        $tar = $this->dir.'/'.$this->name.'.tar';
        $p = new PharData($tar);
        $p->addFromString($this->name.'/safegrd', $script);
        $p->addFromString($this->name.'/LICENSE', 'BSL');
        $p->compress(\Phar::GZ);
        unset($p);

        return (string) file_get_contents($tar.'.gz');
    }

    /** Fakes GitHub: releases/latest redirecting to v0.0.99, its checksums.txt and the archive. */
    private function fakeRelease(string $archive, ?string $sum = null): void
    {
        $sum ??= hash('sha256', $archive);
        $base = 'https://github.com/safegrd/cli/releases';
        Http::fake([
            $base.'/latest' => Http::response('', 302, ['Location' => $base.'/tag/v0.0.99']),
            $base.'/download/v0.0.99/checksums.txt' => Http::response("0000  safegrd_0.0.99_windows_amd64.zip\n{$sum}  {$this->name}.tar.gz\n"),
            $base.'/download/v0.0.99/'.$this->name.'.tar.gz' => Http::response($archive),
        ]);
    }

    public function test_latest_is_read_from_the_releases_redirect_not_the_api(): void
    {
        $this->fakeRelease($this->tarball('#!/bin/sh'));
        $this->assertSame('v0.0.99', $this->app->make(Cli::class)->releaseTag());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.github.com'));
    }

    public function test_the_pinned_version_asks_git_hub_nothing(): void
    {
        Http::fake();
        $this->app['config']->set('safegrd.version', 'v0.0.42');
        $this->assertSame('v0.0.42', $this->app->make(Cli::class)->releaseTag());
        Http::assertNothingSent();
    }

    public function test_download_checks_the_sum_and_installs_the_binary(): void
    {
        $this->fakeRelease($this->tarball("#!/bin/sh\necho new\n"));
        $cli = $this->app->make(Cli::class);
        $said = [];
        $path = $cli->download(function (string $line) use (&$said) {
            $said[] = $line;
        });
        $this->assertSame($this->home.'/bin/safegrd', $path);
        $this->assertSame("#!/bin/sh\necho new\n", file_get_contents($path));
        $this->assertSame(0755, fileperms($path) & 0777);
        $this->assertSame(['safegrd'], array_values(array_diff(scandir($this->home.'/bin') ?: [], ['.', '..'])), 'nothing but the binary is left');
        $this->assertStringContainsString('Checked against checksums.txt', implode("\n", $said));
    }

    public function test_a_mismatched_sum_installs_nothing_and_keeps_the_old_binary(): void
    {
        mkdir($this->home.'/bin', 0700, true);
        file_put_contents($this->home.'/bin/safegrd', 'old');
        $this->fakeRelease($this->tarball("#!/bin/sh\necho tampered\n"), str_repeat('a', 64));
        try {
            $this->app->make(Cli::class)->download(fn () => null);
            $this->fail('a download that does not match checksums.txt was installed');
        } catch (SafeGrdException $e) {
            $this->assertStringContainsString('does not match its SHA-256', $e->getMessage());
        }
        $this->assertSame('old', file_get_contents($this->home.'/bin/safegrd'));
        $this->assertSame(['safegrd'], array_values(array_diff(scandir($this->home.'/bin') ?: [], ['.', '..'])));
    }

    public function test_a_release_without_this_platform_is_refused_in_words(): void
    {
        $base = 'https://github.com/safegrd/cli/releases';
        Http::fake([
            $base.'/latest' => Http::response('', 302, ['Location' => $base.'/tag/v0.0.99']),
            $base.'/download/v0.0.99/checksums.txt' => Http::response("0000  safegrd_0.0.99_windows_amd64.zip\n"),
        ]);
        $this->expectException(SafeGrdException::class);
        $this->expectExceptionMessage($this->name.'.tar.gz is not in release v0.0.99\'s checksums.txt');
        $this->app->make(Cli::class)->download(fn () => null);
    }

    public function test_install_replaces_an_old_cli_on_path_with_a_download(): void
    {
        // The fake on PATH predates init --laravel; the release has one.
        $this->mode('old');
        $path = getenv('PATH');
        putenv('PATH='.$this->dir.'/bin:'.$path);
        $this->fakeRelease($this->tarball((string) file_get_contents($this->fake)));
        try {
            [$code, $out, $err] = $this->artisanSplit('safegrd:install', ['--token' => 'sg_pat_x', '--no-backup' => true]);
        } finally {
            putenv('PATH='.$path);
        }
        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString($this->fake.' is safegrd 0.0.99, which cannot set up a Laravel app. Downloading a newer one', $out);
        $this->assertStringContainsString('init --laravel', (string) file_get_contents($this->home.'/bin/calls.log'));
        $this->assertSame($this->home.'/bin/safegrd', $this->app->make(Cli::class)->binary());
    }
}
