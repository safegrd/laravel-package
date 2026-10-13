<?php

namespace SafeGrd\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Testbench;
use SafeGrd\Laravel\SafeGrdServiceProvider;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * An app with the package, a SQLite database on disk, and a fake safegrd
 * named by SAFEGRD_BINARY. The fake is a shell script: it records every
 * call and its environment in calls.log, writes the config.yaml the real
 * CLI's enroll and init would, and fails as the file named mode says.
 */
abstract class TestCase extends Testbench
{
    protected string $dir;

    protected string $home;

    protected string $fake;

    protected function getPackageProviders($app): array
    {
        return [SafeGrdServiceProvider::class];
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/safegrd-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0700, true);
        $this->home = $this->dir.'/home';
        $this->fake = $this->dir.'/bin/safegrd';
        file_put_contents($this->fake, self::FAKE);
        chmod($this->fake, 0755);
        touch($this->dir.'/app.sqlite');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('safegrd.home', $this->home);
        $app['config']->set('safegrd.binary', $this->fake);
        $app['config']->set('app.name', 'Acme Shop');
        $app['config']->set('database.default', 'app');
        $app['config']->set('database.connections.app', ['driver' => 'sqlite', 'database' => $this->dir.'/app.sqlite']);
        $app['config']->set('logging.channels.safegrd_test', ['driver' => 'single', 'path' => $this->dir.'/laravel.log']);
        $app['config']->set('safegrd.log_channel', 'safegrd_test');
    }

    /** Makes the fake behave as $mode on its next calls: ok, fail, kill, sleep, old. */
    protected function mode(string $mode): void
    {
        file_put_contents($this->dir.'/bin/mode', $mode);
    }

    /** Every call the fake took, one line each: its arguments. */
    protected function calls(): string
    {
        return (string) @file_get_contents($this->dir.'/bin/calls.log');
    }

    /** Writes the config safegrd:install leaves, for a test that starts after it. */
    protected function installed(string $id = 'acme-shop', ?string $root = null): void
    {
        @mkdir($this->home.'/.safegrd', 0700, true);
        $root ??= base_path();
        file_put_contents($this->home.'/.safegrd/config.yaml', "server:\n    url: https://safegrd.dev\n    token: sg_tok_test\nsurfaces:\n    - id: {$id}\n      type: laravel\n      name: Acme Shop\n      roots:\n        - {$root}\n      database_url: env:SAFEGRD_LARAVEL_DATABASE_URL\n");
    }

    /**
     * Runs an artisan command with stdout and stderr kept apart, as a
     * terminal has them.
     *
     * @param  array<string, mixed>  $params
     * @return array{int, string, string} the exit code, stdout, stderr
     */
    protected function artisanSplit(string $command, array $params = []): array
    {
        $out = new SplitOutput;
        $code = Artisan::call($command, $params, $out);

        return [$code, $out->stdout(), $out->stderr()];
    }

    private const FAKE = <<<'SH'
#!/bin/sh
dir=$(dirname "$0")
mode=$(cat "$dir/mode" 2>/dev/null || echo ok)
echo "$*" >> "$dir/calls.log"
echo "env db=${SAFEGRD_LARAVEL_DATABASE_URL:-} token=${SAFEGRD_INSTALL_TOKEN:-} home=$HOME" >> "$dir/calls.log"
cfg="$HOME/.safegrd/config.yaml"
[ "$1" = "--server-url" ] && shift 2
case "$1" in
version)
	echo "safegrd version 0.0.99 (commit: abc, built: today)" ;;
init)
	if [ "$2" = "--help" ]; then
		echo "Usage: safegrd init [flags]"
		[ "$mode" = old ] || echo "      --laravel string   Set up the Laravel app at this root"
		exit 0
	fi
	[ "$mode" = old ] && { echo "Error: unknown flag: --laravel" >&2; exit 1; }
	root=$3; id=$5
	mkdir -p "$HOME/.safegrd"
	grep -q '^surfaces:' "$cfg" 2>/dev/null || echo "surfaces:" >> "$cfg"
	printf '    - id: %s\n      type: laravel\n      roots:\n        - %s\n      database_url: env:SAFEGRD_LARAVEL_DATABASE_URL\n' "$id" "$root" >> "$cfg"
	echo "Added surface $id: Laravel 12 on sqlite" ;;
enroll)
	mkdir -p "$HOME/.safegrd"
	printf 'server:\n    url: https://safegrd.dev\n    token: sg_tok_fake\n' > "$cfg"
	echo "Enrolled" ;;
login)
	echo "Code: SG-ABCD-EFGH" ;;
daemon|backup)
	case "$mode" in
	fail) echo "Backing up acme-shop"; echo "Error: Surface acme-shop: connection refused" >&2; exit 10 ;;
	kill) echo "Backing up acme-shop"; kill -9 $$ ;;
	sleep) sleep 5 ;;
	esac
	if [ "$2" = "status" ]; then
		echo "Warning: the host's clock is 3s off" >&2
		if [ "$3" = "--json" ]; then echo '{"surfaces": [{"id": "acme-shop"}]}'; else echo "acme-shop  last backup 2m ago"; fi
		exit 0
	fi
	echo "Backed up acme-shop (1 MB, 1s)" ;;
list)
	echo '[]' ;;
doctor)
	echo "Surface acme-shop: ok" ;;
*)
	echo "Error: unknown command $1" >&2; exit 1 ;;
esac
SH;
}

/** Stdout and stderr in two buffers, so a test can tell them apart. */
final class SplitOutput extends StreamOutput implements ConsoleOutputInterface
{
    private StreamOutput $err;

    public function __construct()
    {
        parent::__construct(fopen('php://memory', 'w+'));
        $this->err = new StreamOutput(fopen('php://memory', 'w+'));
    }

    public function getErrorOutput(): StreamOutput
    {
        return $this->err;
    }

    public function setErrorOutput(OutputInterface $error): void {}

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('not used');
    }

    public function stdout(): string
    {
        rewind($this->getStream());

        return (string) stream_get_contents($this->getStream());
    }

    public function stderr(): string
    {
        rewind($this->err->getStream());

        return (string) stream_get_contents($this->err->getStream());
    }
}
