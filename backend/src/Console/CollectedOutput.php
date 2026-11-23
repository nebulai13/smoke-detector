<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

/**
 * Keeps what a command prints instead of printing it: for a command that runs other commands and
 * reports on them in a line of its own.
 */
final class CollectedOutput implements Output
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $errors = [];

    public function line(string $text): void
    {
        $this->lines[] = $text;
    }

    public function error(string $text): void
    {
        $this->errors[] = $text;
    }

    /**
     * @return list<string> What was printed as a result, in order.
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string> What was printed as a failure, in order.
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
