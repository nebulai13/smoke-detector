<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use SmokeDetector\Application\Competition\DecideDuels;

/**
 * php bin/console competitions:finalize: lets duel proposals expire after 72 hours and decides the
 * duels whose last days are final (see DecideDuels). A step of cron:run, after days:finalize, so a
 * duel is decided in the run that makes its last day final (HLD §4.8, §5.2).
 *
 * It can run as often as wanted. A duel that could not be decided makes it exit with 1, so cron:run
 * says FAILED and writes no heartbeat until somebody has looked at the log.
 */
final readonly class FinalizeCompetitionsCommand implements Command
{
    public function __construct(private DecideDuels $decideDuels)
    {
    }

    public function name(): string
    {
        return 'competitions:finalize';
    }

    public function description(): string
    {
        return 'Lets duel proposals expire after 72 hours and decides the duels whose last days are final.';
    }

    public function run(array $arguments, Output $output): int
    {
        if ($arguments !== []) {
            $output->error($this->name() . ' takes no arguments.');

            return 1;
        }

        $report = $this->decideDuels->execute();

        // Counts only: nothing here says whose duels they were.
        $output->line(sprintf(
            'Expired %s, decided %s.',
            self::count($report->expired, 'duel proposal'),
            self::count($report->decided, 'duel'),
        ));

        if ($report->failed > 0) {
            $output->error('Could not decide ' . self::count($report->failed, 'duel') . ': see the log.');

            return 1;
        }

        return 0;
    }

    private static function count(int $number, string $noun): string
    {
        return "{$number} {$noun}" . ($number === 1 ? '' : 's');
    }
}
