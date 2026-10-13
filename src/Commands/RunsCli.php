<?php

namespace SafeGrd\Laravel\Commands;

use Illuminate\Support\Facades\Log;
use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\Events\BackupFailed;
use SafeGrd\Laravel\SafeGrdException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Runs the CLI from an artisan command, with its output as the command's:
 * its stdout on stdout, its stderr on stderr, so --json stays JSON.
 */
trait RunsCli
{
    /** @var list<string> the last lines the CLI printed */
    private array $tail = [];

    private string $lastError = '';

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    protected function cli(Cli $cli, array $args, bool $database = true, array $env = []): int
    {
        $this->tail = [];
        $this->lastError = '';
        try {
            return $cli->run($args, function (string $line, bool $stderr) {
                $this->tail[] = $line;
                if (count($this->tail) > 20) {
                    array_shift($this->tail);
                }
                if (! $stderr) {
                    $this->line(OutputFormatter::escape($line));

                    return;
                }
                if (str_starts_with($line, 'Error: ')) {
                    $this->lastError = substr($line, 7);
                }
                $this->stderr($line);
            }, $database, $env);
        } catch (SafeGrdException $e) {
            $this->lastError = $e->getMessage();
            $this->stderr('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** A line on stderr, coloured as the CLI's Error: and Warning: lines are. */
    protected function stderr(string $line): void
    {
        $escaped = OutputFormatter::escape($line);
        if (str_starts_with($line, 'Error: ')) {
            $escaped = '<fg=red>'.$escaped.'</>';
        } elseif (str_starts_with($line, 'Warning: ')) {
            $escaped = '<fg=yellow>'.$escaped.'</>';
        }
        $this->output->getErrorStyle()->writeln($escaped);
    }

    /**
     * Tells the app that a backup or a run failed: an error in its log, which
     * its error tracker picks up, and a BackupFailed event.
     */
    protected function reportFailure(Cli $cli, int $code): void
    {
        $surface = $cli->surface();
        $message = $this->lastError !== '' ? $this->lastError : "the SafeGrd CLI exited {$code}";
        try {
            Log::channel(config('safegrd.log_channel') ?: null)->error("SafeGrd: {$this->getName()} failed for {$surface}: {$message}", [
                'surface' => $surface,
                'exit_code' => $code,
                'output' => $this->tail,
            ]);
        } catch (Throwable $e) {
            $this->stderr('Warning: could not write the failure to the app\'s log: '.$e->getMessage());
        }
        try {
            event(new BackupFailed((string) $this->getName(), $surface, $code, $message, $this->tail));
        } catch (Throwable $e) {
            $this->stderr('Warning: a listener for BackupFailed threw: '.$e->getMessage());
        }
    }
}
