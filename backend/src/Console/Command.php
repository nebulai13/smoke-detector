<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

/**
 * One command of bin/console.
 */
interface Command
{
    /** What is typed after "php bin/console", e.g. "migrate" or "cron:run". */
    public function name(): string;

    /** One sentence for the command list. */
    public function description(): string;

    /**
     * @param list<string> $arguments What was typed after the command name.
     *
     * @return int The exit code: 0 for success.
     */
    public function run(array $arguments, Output $output): int;
}
