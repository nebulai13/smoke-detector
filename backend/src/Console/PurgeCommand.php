<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use SmokeDetector\Application\Housekeeping\PurgeExpiredData;

/**
 * php bin/console housekeeping:purge: deletes what is no longer needed (see PurgeExpiredData), first
 * of all the registrations that were not confirmed within 24 hours (FR-ACC-03).
 *
 * The cron job runs it as a step of `cron:run`, every minute (HLD §5.2, §8). It can run as often
 * as wanted. Where no cron job calls bin/cron, nothing is deleted.
 */
final readonly class PurgeCommand implements Command
{
    public function __construct(private PurgeExpiredData $purge)
    {
    }

    public function name(): string
    {
        return 'housekeeping:purge';
    }

    public function description(): string
    {
        return 'Deletes unconfirmed registrations older than 24 hours, dead tokens, old rate limit counters and old queued mails.';
    }

    public function run(array $arguments, Output $output): int
    {
        // An ignored option such as --dry-run would delete for real against the caller's intention.
        if ($arguments !== []) {
            $output->error($this->name() . ' takes no arguments.');

            return 1;
        }

        $report = $this->purge->execute();

        // Counts only: nothing here says whose rows they were.
        $output->line(sprintf(
            'Deleted %s, %s, %s, %s and %s.',
            self::count($report->unconfirmedRegistrations, 'unconfirmed registration'),
            self::count($report->oneTimeTokens, 'mail link token'),
            self::count($report->refreshTokens, 'session'),
            self::count($report->rateLimitWindows, 'rate limit counter'),
            self::count($report->queuedMails, 'queued mail'),
        ));

        return 0;
    }

    private static function count(int $number, string $what): string
    {
        return $number . ' ' . $what . ($number === 1 ? '' : 's');
    }
}
