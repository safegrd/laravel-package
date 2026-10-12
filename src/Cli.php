<?php

namespace SafeGrd\Laravel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PharData;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The SafeGrd CLI as this app runs it: one binary, a home of its own under
 * storage/, and the app's database connection handed over in the
 * environment on every run.
 */
final class Cli
{
    /** The variable the CLI reads the app's database URL from. */
    public const DATABASE_ENV = 'SAFEGRD_LARAVEL_DATABASE_URL';

    private const RELEASES = 'https://github.com/safegrd/cli/releases';

    /** @param  array<string, mixed>  $config  config('safegrd') */
    public function __construct(private array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('safegrd', []));
    }

    /** Where the CLI keeps its config, its key and its state. */
    public function home(): string
    {
        $home = (string) ($this->config['home'] ?? '');
        if ($home === '') {
            // Through the link Envoyer and Deployer make from each release's
            // storage to the shared one: the CLI writes absolute paths (the
            // key's) into its config, and a release's own path is pruned.
            $storage = realpath(storage_path()) ?: storage_path();
            $home = $storage.'/safegrd';
        }

        return rtrim($home, '/');
    }

    public function configPath(): string
    {
        return $this->home().'/.safegrd/config.yaml';
    }

    /** Whether safegrd:install has added the app. */
    public function installed(): bool
    {
        $c = @file_get_contents($this->configPath());

        return is_string($c) && str_contains($c, 'type: laravel');
    }

    /** Whether this host is enrolled: its config holds a node token. */
    public function enrolled(): bool
    {
        $c = @file_get_contents($this->configPath());

        return is_string($c) && preg_match('/^\s*token:\s*["\']?sg_tok_/m', $c) === 1;
    }

    /** The surface's id: the configured one, or APP_NAME as a slug. */
    public function surface(): string
    {
        $id = (string) ($this->config['surface'] ?? '');
        if ($id === '') {
            $id = Str::slug((string) config('app.name'));
        }

        return $id !== '' && $id !== 'laravel' ? $id : 'app';
    }

    /**
     * The app's root as it stays across deploys: an app deployed into
     * releases/<n> with a current link beside them (Envoyer, Deployer) is
     * backed up through current, which follows each deploy.
     */
    public function root(): string
    {
        $base = rtrim(base_path(), '/');
        $releases = dirname($base);
        if (basename($releases) === 'releases' && is_dir(dirname($releases).'/current')) {
            return dirname($releases).'/current';
        }

        return $base;
    }

    /** The connection to back up, as the CLI connects to it. */
    public function databaseUrl(): string
    {
        $name = (string) ($this->config['connection'] ?? '') ?: (string) config('database.default');
        $c = config('database.connections.'.$name);
        if (! is_array($c)) {
            throw new SafeGrdException("config/database.php has no connection named {$name}.");
        }

        return DatabaseUrl::fromConfig($c, $name, base_path());
    }

    /** The binary to run, or null when there is none yet. */
    public function binary(): ?string
    {
        $configured = (string) ($this->config['binary'] ?? '');
        if ($configured !== '') {
            if (! is_executable($configured)) {
                throw new SafeGrdException("SAFEGRD_BINARY is {$configured}, which is not an executable file.");
            }

            return $configured;
        }
        $downloaded = $this->home().'/bin/safegrd';
        if (is_executable($downloaded)) {
            return $downloaded;
        }

        // The scheduler's cron PATH often lacks /usr/local/bin.
        return (new ExecutableFinder())->find('safegrd', null, ['/usr/local/bin', '/opt/homebrew/bin']);
    }

    /**
     * Downloads the CLI release named by the version setting into home/bin,
     * after checking it against the release's checksums.txt.
     *
     * @param  callable(string): void  $say
     */
    public function download(callable $say): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'darwin',
            default => throw new SafeGrdException('The SafeGrd CLI runs on Linux and macOS, and this host is '.PHP_OS_FAMILY.'.'),
        };
        $arch = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new SafeGrdException('The SafeGrd CLI is built for amd64 and arm64, and this host is '.php_uname('m').'.'),
        };
        $tag = (string) ($this->config['version'] ?? 'latest');
        if ($tag === '' || $tag === 'latest') {
            try {
                $tag = (string) Http::accept('application/vnd.github+json')->timeout(30)
                    ->get('https://api.github.com/repos/safegrd/cli/releases/latest')->json('tag_name');
            } catch (Throwable $e) {
                throw new SafeGrdException('Could not ask GitHub for the latest SafeGrd CLI release: '.$e->getMessage().'. Set SAFEGRD_VERSION to a tag from '.self::RELEASES.', or SAFEGRD_BINARY to a safegrd on this host.');
            }
            if ($tag === '') {
                throw new SafeGrdException('GitHub did not name the latest SafeGrd CLI release. Set SAFEGRD_VERSION to a tag from '.self::RELEASES.'.');
            }
        }
        $name = 'safegrd_'.ltrim($tag, 'v').'_'.$os.'_'.$arch;
        $base = self::RELEASES.'/download/'.$tag;
        $say("Downloading safegrd {$tag} for {$os}/{$arch}");

        try {
            $sums = Http::timeout(30)->get($base.'/checksums.txt');
        } catch (Throwable $e) {
            throw new SafeGrdException("Could not download {$base}/checksums.txt: ".$e->getMessage());
        }
        if (! $sums->successful()) {
            throw new SafeGrdException("No checksums.txt in release {$tag}, so the download cannot be checked: {$base}/checksums.txt answered {$sums->status()}.");
        }
        $want = null;
        foreach (preg_split('/\R/', $sums->body()) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2 && $parts[1] === $name.'.tar.gz') {
                $want = strtolower($parts[0]);
            }
        }
        if ($want === null) {
            throw new SafeGrdException("{$name}.tar.gz is not in release {$tag}'s checksums.txt, so it cannot be checked.");
        }

        $dir = $this->home().'/bin';
        $this->makeHome();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new SafeGrdException("Could not create {$dir}.");
        }
        $tmp = $dir.'/'.$name.'.tar.gz';
        try {
            $res = Http::timeout(300)->sink($tmp)->get($base.'/'.$name.'.tar.gz');
        } catch (Throwable $e) {
            @unlink($tmp);
            throw new SafeGrdException("Could not download {$base}/{$name}.tar.gz: ".$e->getMessage());
        }
        if (! $res->successful()) {
            @unlink($tmp);
            throw new SafeGrdException("Downloading {$base}/{$name}.tar.gz answered {$res->status()}.");
        }
        $got = hash_file('sha256', $tmp);
        if (! is_string($got) || ! hash_equals($want, $got)) {
            @unlink($tmp);
            throw new SafeGrdException("{$name}.tar.gz does not match its SHA-256 in checksums.txt (want {$want}, got {$got}). Nothing was installed.");
        }
        $out = $dir.'/'.$name;
        (new PharData($tmp))->extractTo($dir, $name.'/safegrd', true);
        @unlink($tmp);
        if (! rename($out.'/safegrd', $dir.'/safegrd')) {
            throw new SafeGrdException("Could not move the CLI into {$dir}.");
        }
        @rmdir($out);
        chmod($dir.'/safegrd', 0755);
        $say("Checked against checksums.txt and installed at {$dir}/safegrd");

        return $dir.'/safegrd';
    }

    /**
     * Runs the CLI with args, passing each line of its output to $out as it
     * comes, and returns its exit code. With $database, the app's database
     * URL reaches it in the environment.
     *
     * @param  list<string>  $args
     * @param  callable(string, bool): void  $out  a line, and whether it was stderr
     * @param  array<string, string>  $env
     */
    public function run(array $args, callable $out, bool $database = true, array $env = [], ?float $timeout = null): int
    {
        $bin = $this->binary();
        if ($bin === null) {
            throw new SafeGrdException('The SafeGrd CLI is not installed. Run php artisan safegrd:install.');
        }
        $this->makeHome();
        $env = ['HOME' => $this->home()] + $env;
        if ($database) {
            $env[self::DATABASE_ENV] = $this->databaseUrl();
        }
        $p = new Process(array_merge([$bin], $args), base_path(), $env, null, $timeout);
        $buf = ['', ''];
        $p->run(function (string $type, string $chunk) use (&$buf, $out) {
            $i = $type === Process::ERR ? 1 : 0;
            $buf[$i] .= $chunk;
            while (($n = strpos($buf[$i], "\n")) !== false) {
                $out(substr($buf[$i], 0, $n), $i === 1);
                $buf[$i] = substr($buf[$i], $n + 1);
            }
        });
        foreach ($buf as $i => $rest) {
            if ($rest !== '') {
                $out($rest, $i === 1);
            }
        }

        return (int) $p->getExitCode();
    }

    /** The home directory, readable only by the app's user and kept out of git. */
    private function makeHome(): void
    {
        $home = $this->home();
        if (! is_dir($home) && ! mkdir($home, 0700, true) && ! is_dir($home)) {
            throw new SafeGrdException("Could not create {$home}.");
        }
        if (! file_exists($home.'/.gitignore')) {
            file_put_contents($home.'/.gitignore', "*\n");
        }
    }
}
