<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use SmokeDetector\Application\Day\FinalizeDays;

/**
 * php bin/console days:finalize: makes the days final that ended 48 hours ago and calculates the
 * weekly baselines (see FinalizeDays). A step of cron:run, after mail:send.
 *
 * It can run as often as wanted, also twice at the same time. A run that is out of time leaves the
 * rest to the next run: that is no failure. A user who could not be served is: the command exits
 * with 1, so cron:run says FAILED and writes no heartbeat until somebody has looked at the log.
 */
final readonly class FinalizeDaysCommand implements Command
{
    public function __construct(private FinalizeDays $finalizeDays)
    {
    }

    public function name(): string
    {
        return 'days:finalize';
    }

    public function description(): string
    {
        return 'Makes the days final that ended 48 hours ago and calculates the weekly baselines.';
    }

    public function run(array $arguments, Output $output): int
    {
        if ($arguments !== []) {
            $output->error($this->name() . ' takes no arguments.');

            return 1;
        }

        $report = $this->finalizeDays->execute();

        // Counts only: nothing here says whose days they were.
        $output->line(sprintf(
            'Finalized %s, calculated %s.%s',
            self::count($report->days, 'day'),
            self::count($report->baselines, 'baseline'),
            $report->outOfTime ? ' Out of time: the rest follows in the next run.' : '',
        ));

        if ($report->failedUsers > 0) {
            $output->error('Could not finalize the days of ' . self::count($report->failedUsers, 'user') . ': see the log.');

            return 1;
        }

        return 0;
    }

    private static function count(int $number, string $noun): string
    {
        return "{$number} {$noun}" . ($number === 1 ? '' : 's');
    }
}
