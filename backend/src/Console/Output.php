<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

/**
 * Where a console command writes: results to standard output, failures to standard error.
 */
interface Output
{
    public function line(string $text): void;

    public function error(string $text): void;
}
