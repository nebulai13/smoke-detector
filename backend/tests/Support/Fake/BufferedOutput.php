<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Console\Output;

/**
 * Collects what a console command prints.
 */
final class BufferedOutput implements Output
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function line(string $text): void
    {
        $this->lines[] = $text;
    }

    public function error(string $text): void
    {
        $this->errors[] = $text;
    }
}
