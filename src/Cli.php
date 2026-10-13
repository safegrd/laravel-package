<?php

namespace SafeGrd\Laravel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PharData;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The SafeGrd CLI as this app runs it: one binary, a home of its own under
 * storage/, and the app's database connection handed over in the
 * environment on every run.
 */
class Cli
{
    /** The variable the CLI reads the app's database URL from. */
    public const DATABASE_ENV = 'SAFEGRD_LARAVEL_DATABASE_URL';

    public const RELEASES = 'https://github.com/safegrd/cli/releases';

    /** @param  array<string, mixed>  $config  config('safegrd') */
    public function __construct(private array $config) {}

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

    /** The file the scheduled run appends its output to. */
    public function logPath(): string
    {
        return storage_path('logs/safegrd.log');
    }

    /** Whether safegrd:install has added this app to the CLI's config. */
    public function installed(): bool
    {
        return $this->entry() !== null;
    }

    /** Whether this host is enrolled: its config holds a node token. */
    public function enrolled(): bool
    {
        $c = @file_get_contents($this->configPath());

        return is_string($c) && preg_match('/^\s*token:\s*["\']?sg_tok_/m', $c) === 1;
    }

    /**
     * The surface's id: the one the CLI's config has for this app's root,
     * so renaming the app or changing SAFEGRD_SURFACE later changes
     * nothing. Before install, the id install would give it.
     */
    public function surface(): string
    {
        return $this->entry()['id'] ?? $this->derivedSurface();
    }

    /** The id safegrd:install gives a new surface: SAFEGRD_SURFACE, or APP_NAME as a slug. */
    public function derivedSurface(): string
    {
        $id = (string) ($this->config['surface'] ?? '');
        if ($id === '') {
            $id = Str::slug((string) config('app.name'));
        }

        return $id !== '' && $id !== 'laravel' ? $id : 'app';
    }

    /**
     * This app's surface in the CLI's config: the laravel surface whose root
     * is this app's. Found by root, not by id, so a SAFEGRD_SURFACE set after
     * install cannot make a set-up app look as if it were not.
     *
     * @return array{id: string, roots: list<string>}|null
     */
    public function entry(): ?array
    {
        $c = @file_get_contents($this->configPath());
        if (! is_string($c)) {
            return null;
        }
        $root = $this->root();
        $roots = array_unique([$root, realpath($root) ?: $root]);
        foreach (self::laravelSurfaces($c) as $s) {
            if (array_intersect($roots, $s['roots']) !== []) {
                return $s;
            }
        }

        return null;
    }

    /**
     * The laravel surfaces in the CLI's config.yaml, read without a YAML
     * library: the file is the one the CLI writes, a list under surfaces:
     * with one key per line.
     *
     * @return list<array{id: string, roots: list<string>}>
     */
    public static function laravelSurfaces(string $yaml): array
    {
        $items = [];
        $in = false;
        $indent = null;
        $cur = null;
        $inRoots = false;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (strlen($line) === strlen(ltrim($line))) {
                $in = str_starts_with($line, 'surfaces:');
                $indent = null;

                continue;
            }
            if (! $in) {
                continue;
            }
            if (preg_match('/^(\s*)-\s+(.*)$/', $line, $m) && ($indent === null || strlen($m[1]) === $indent)) {
                $indent = strlen($m[1]);
                if ($cur !== null) {
                    $items[] = $cur;
                }
                $cur = ['id' => '', 'type' => '', 'roots' => []];
                $inRoots = false;
                $line = str_repeat(' ', $indent + 2).$m[2];
            }
            if ($cur === null) {
                continue;
            }
            if (preg_match('/^\s*([A-Za-z_]+):\s*(.*)$/', $line, $m)) {
                $inRoots = $m[1] === 'roots';
                if ($m[1] === 'id' || $m[1] === 'type') {
                    $cur[$m[1]] = self::scalar($m[2]);
                } elseif ($inRoots && str_starts_with(trim($m[2]), '[')) {
                    $cur['roots'] = array_values(array_map(self::scalar(...), array_filter(explode(',', trim($m[2], ' []')))));
                }
            } elseif ($inRoots && preg_match('/^\s*-\s+(.*)$/', $line, $m)) {
                $cur['roots'][] = self::scalar($m[1]);
            }
        }
        if ($cur !== null) {
            $items[] = $cur;
        }
        $out = [];
        foreach ($items as $s) {
            if (strtolower($s['type']) === 'laravel' && $s['id'] !== '') {
                $out[] = ['id' => $s['id'], 'roots' => $s['roots']];
            }
        }

        return $out;
    }

    private static function scalar(string $v): string
    {
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
            return substr($v, 1, -1);
        }

        return $v;
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
        $configured = $this->configuredBinary();
        if ($configured !== null) {
            if (! is_file($configured) || ! is_executable($configured)) {
                throw new SafeGrdException("SAFEGRD_BINARY is {$configured}, which is not an executable file.");
            }

            return $configured;
        }
        if (is_executable($this->downloadedBinary())) {
            return $this->downloadedBinary();
        }

        // The scheduler's cron PATH often lacks /usr/local/bin.
        return (new ExecutableFinder)->find('safegrd', null, ['/usr/local/bin', '/opt/homebrew/bin']);
    }

    /** SAFEGRD_BINARY, or null when it is not set. */
    public function configuredBinary(): ?string
    {
        $configured = (string) ($this->config['binary'] ?? '');

        return $configured !== '' ? $configured : null;
    }

    /** Where safegrd:install downloads the CLI to. */
    public function downloadedBinary(): string
    {
        return $this->home().'/bin/safegrd';
    }

    /**
     * The CLI's version, as `safegrd version` prints it, or null when it does not run.
     *
     * @phpstan-impure
     */
    public function version(): ?string
    {
        $out = '';
        try {
            $code = $this->run(['version'], function (string $line, bool $stderr) use (&$out) {
                if (! $stderr) {
                    $out .= $line."\n";
                }
            }, false, [], 30);
        } catch (SafeGrdException) {
            return null;
        }

        return $code === 0 && preg_match('/safegrd version (\S+)/', $out, $m) ? $m[1] : null;
    }

    /**
     * Whether the binary can set up a Laravel app. Releases before that have
     * no init --laravel; the binary is asked, rather than its version
     * compared with a number.
     *
     * @phpstan-impure
     */
    public function supportsLaravel(): bool
    {
        $help = '';
        try {
            $this->run(['init', '--help'], function (string $line) use (&$help) {
                $help .= $line."\n";
            }, false, [], 30);
        } catch (SafeGrdException) {
            return false;
        }

        return str_contains($help, '--laravel');
    }

    /** The release tag the version setting names, asking GitHub for "latest". */
    public function releaseTag(): string
    {
        $tag = (string) ($this->config['version'] ?? 'latest');
        if ($tag !== '' && $tag !== 'latest') {
            return $tag;
        }
        // The releases/latest page redirects to the tag. Unlike the API it
        // has no hourly limit per address, which a host behind a shared NAT
        // or a CI runner reaches.
        try {
            $res = Http::withOptions(['allow_redirects' => false])->timeout(30)->get(self::RELEASES.'/latest');
        } catch (Throwable $e) {
            throw new SafeGrdException('Could not ask GitHub for the latest SafeGrd CLI release: '.$e->getMessage().'. Set SAFEGRD_VERSION to a tag from '.self::RELEASES.', or SAFEGRD_BINARY to a safegrd on this host.');
        }
        if ($res->redirect() && preg_match('#/releases/tag/([^/?\#]+)#', (string) $res->header('Location'), $m)) {
            return rawurldecode($m[1]);
        }

        throw new SafeGrdException('GitHub did not name the latest SafeGrd CLI release: '.self::RELEASES.'/latest answered '.$res->status().'. Set SAFEGRD_VERSION to a tag from '.self::RELEASES.'.');
    }

    /**
     * Downloads the CLI release named by the version setting into home/bin,
     * after checking it against the release's checksums.txt. A binary
     * already there is replaced only once the new one has checked out.
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
        $tag = $this->releaseTag();
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
        foreach (preg_split('/\R/', $sums->body()) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) === 2 && ltrim($parts[1], '*') === $name.'.tar.gz') {
                $want = strtolower($parts[0]);
            }
        }
        if ($want === null) {
            throw new SafeGrdException("{$name}.tar.gz is not in release {$tag}'s checksums.txt, so it cannot be checked.");
        }

        $dir = dirname($this->downloadedBinary());
        $this->makeHome();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new SafeGrdException("Could not create {$dir}.");
        }
        $work = $dir.'/.download-'.bin2hex(random_bytes(4));
        if (! @mkdir($work, 0700)) {
            throw new SafeGrdException("Could not create {$work}.");
        }
        try {
            $tmp = $work.'/'.$name.'.tar.gz';
            try {
                $res = Http::timeout(300)->sink($tmp)->get($base.'/'.$name.'.tar.gz');
            } catch (Throwable $e) {
                throw new SafeGrdException("Could not download {$base}/{$name}.tar.gz: ".$e->getMessage());
            }
            if (! $res->successful()) {
                throw new SafeGrdException("Downloading {$base}/{$name}.tar.gz answered {$res->status()}.");
            }
            $got = hash_file('sha256', $tmp);
            if (! is_string($got) || ! hash_equals($want, $got)) {
                throw new SafeGrdException("{$name}.tar.gz does not match its SHA-256 in checksums.txt (want {$want}, got {$got}). Nothing was installed.");
            }
            $extracted = $this->extract($tmp, $work, $name.'/safegrd');
            chmod($extracted, 0755);
            // One rename over the old binary, so a scheduled run starting now
            // gets the old one or the new one, never part of either.
            if (! @rename($extracted, $this->downloadedBinary())) {
                throw new SafeGrdException("Could not move the CLI into {$dir}.");
            }
        } finally {
            self::removeTree($work);
        }
        $say('Checked against checksums.txt and installed at '.$this->downloadedBinary());

        return $this->downloadedBinary();
    }

    /** Extracts one file from a .tar.gz, with PHP's phar and zlib, else tar(1). */
    private function extract(string $archive, string $into, string $member): string
    {
        if (extension_loaded('phar') && extension_loaded('zlib')) {
            try {
                (new PharData($archive))->extractTo($into, $member, true);
            } catch (Throwable $e) {
                throw new SafeGrdException("Could not unpack {$archive}: ".$e->getMessage());
            }
        } else {
            $tar = (new ExecutableFinder)->find('tar');
            if ($tar === null) {
                throw new SafeGrdException('Unpacking the CLI needs PHP\'s phar and zlib extensions, or tar on PATH. This host has neither.');
            }
            $p = new Process([$tar, '-xzf', $archive, '-C', $into, $member]);
            $p->run();
            if (! $p->isSuccessful()) {
                throw new SafeGrdException("tar could not unpack {$archive}: ".trim($p->getErrorOutput()));
            }
        }
        $out = $into.'/'.$member;
        if (! is_file($out)) {
            throw new SafeGrdException("The release archive has no {$member}.");
        }

        return $out;
    }

    private static function removeTree(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                self::removeTree($dir.'/'.$f);
            }
        }
        @rmdir($dir);
    }

    /**
     * Runs the CLI with args, passing each line of its output to $out as it
     * comes, and returns its exit code. With $database, the app's database
     * URL reaches it in the environment. A CLI that could not start, ran
     * past the timeout or was killed is a SafeGrdException, never exit 0.
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
        if ($timeout === null) {
            $configured = (float) ($this->config['timeout'] ?? 0);
            $timeout = $configured > 0 ? $configured : null;
        }
        $command = $args[0] ?? '';
        $p = new Process(array_merge([$bin], $args), base_path(), $env, null, $timeout);
        $lines = new OutputLines($out);
        try {
            $p->run(fn (string $type, string $chunk) => $lines->feed($type === Process::ERR, $chunk));
        } catch (ProcessTimedOutException $e) {
            $lines->flush();
            throw new SafeGrdException("safegrd {$command} ran past {$e->getExceededTimeout()} seconds and was stopped. Raise SAFEGRD_TIMEOUT, or unset it for no limit.");
        } catch (ProcessSignaledException $e) {
            $lines->flush();
            throw new SafeGrdException(self::signalled($command, $e->getSignal()));
        } catch (ProcessRuntimeException $e) {
            $lines->flush();
            throw new SafeGrdException("Could not start {$bin}: ".$e->getMessage().'. '.self::NOEXEC);
        }
        $lines->flush();
        if ($p->hasBeenSignaled()) {
            throw new SafeGrdException(self::signalled($command, $p->getTermSignal()));
        }
        $code = $p->getExitCode();
        if ($code === null) {
            throw new SafeGrdException("safegrd {$command} stopped without an exit code.");
        }
        // What the system answers for a file it cannot execute, which the CLI
        // itself never exits with.
        if ($code === 126 || $code === 127) {
            throw new SafeGrdException("{$bin} could not be executed (exit {$code}). ".self::NOEXEC);
        }

        return $code;
    }

    private const NOEXEC = 'If storage is on a filesystem mounted noexec, set SAFEGRD_BINARY to a safegrd elsewhere, such as /usr/local/bin/safegrd';

    private static function signalled(string $command, int $signal): string
    {
        $why = match ($signal) {
            9 => ' (SIGKILL, often the kernel\'s out-of-memory killer: see dmesg)',
            15 => ' (SIGTERM)',
            2 => ' (SIGINT)',
            default => '',
        };

        return "safegrd {$command} was killed by signal {$signal}{$why} before it finished.";
    }

    /** The home directory, readable only by the app's user and kept out of git. */
    public function makeHome(): void
    {
        $home = $this->home();
        if (! is_dir($home) && ! @mkdir($home, 0700, true) && ! is_dir($home)) {
            throw new SafeGrdException(self::ownerHint(dirname($home), "Could not create {$home}"));
        }
        if (! is_writable($home)) {
            throw new SafeGrdException(self::ownerHint($home, "{$home} is not writable by this user"));
        }
        if (! file_exists($home.'/.gitignore')) {
            @file_put_contents($home.'/.gitignore', "*\n");
        }
    }

    /**
     * The name of the user $path belongs to, when that is not the user
     * running this. Null when it is, or when the posix extension is missing.
     */
    public static function otherOwner(string $path): ?string
    {
        if (! function_exists('posix_geteuid') || ! file_exists($path)) {
            return null;
        }
        $owner = fileowner($path);
        if ($owner === false || $owner === posix_geteuid()) {
            return null;
        }
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid($owner) : false;

        return is_array($pw) ? $pw['name'] : (string) $owner;
    }

    private static function ownerHint(string $path, string $what): string
    {
        $owner = self::otherOwner($path);

        return $owner === null ? $what.'.' : "{$what}: it belongs to {$owner}. Run the command as that user: sudo -u {$owner} php artisan ...";
    }
}
