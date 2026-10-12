<?php

namespace SafeGrd\Laravel\Commands;

use SafeGrd\Laravel\Cli;
use SafeGrd\Laravel\SafeGrdException;

/** Runs the CLI from an artisan command, with its output as the command's. */
trait RunsCli
{
    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    protected function cli(Cli $cli, array $args, bool $database = true, array $env = []): int
    {
        try {
            return $cli->run($args, function (string $line, bool $stderr) {
                if ($stderr && str_starts_with($line, 'Error: ')) {
                    $this->error($line);
                } elseif ($stderr && str_starts_with($line, 'Warning: ')) {
                    $this->warn($line);
                } else {
                    $this->line($line);
                }
            }, $database, $env);
        } catch (SafeGrdException $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
