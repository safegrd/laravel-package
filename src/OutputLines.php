<?php

namespace SafeGrd\Laravel;

/**
 * Splits a process's stdout and stderr into lines as the chunks arrive, so
 * each line reaches the operator whole and on the stream it was written to.
 */
final class OutputLines
{
    private string $stdout = '';

    private string $stderr = '';

    /** @param  callable(string, bool): void  $out  a line, and whether it was stderr */
    public function __construct(private $out) {}

    public function feed(bool $stderr, string $chunk): void
    {
        $buf = ($stderr ? $this->stderr : $this->stdout).$chunk;
        while (($n = strpos($buf, "\n")) !== false) {
            ($this->out)(substr($buf, 0, $n), $stderr);
            $buf = substr($buf, $n + 1);
        }
        $stderr ? $this->stderr = $buf : $this->stdout = $buf;
    }

    /** Passes on what is left of each stream after its last newline. */
    public function flush(): void
    {
        foreach ([false => $this->stdout, true => $this->stderr] as $stderr => $rest) {
            if ($rest !== '') {
                ($this->out)($rest, (bool) $stderr);
            }
        }
        $this->stdout = $this->stderr = '';
    }
}
