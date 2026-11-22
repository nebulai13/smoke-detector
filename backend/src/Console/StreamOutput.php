<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

/**
 * Writes to the standard output and standard error of the process.
 */
final readonly class StreamOutput implements Output
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private mixed $out,
        private mixed $err,
    ) {
    }

    public function line(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    public function error(string $text): void
    {
        fwrite($this->err, $text . "\n");
    }
}
