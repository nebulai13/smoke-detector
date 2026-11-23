<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use SmokeDetector\Application\Competition\DecideDuels;
use SmokeDetector\Application\Competition\RankLeagues;

/**
 * php bin/console competitions:finalize: lets duel proposals expire after 72 hours, decides the
 * duels whose last days are final (see DecideDuels) and ranks the stored league results (see
 * RankLeagues). A step of cron:run, after days:finalize, so a duel is decided and a league result
 * ranked in the run that makes the last day final (HLD §4.8, §4.9, §5.2).
 *
 * It can run as often as wanted. A duel that could not be decided or a league result that could not
 * be ranked makes it exit with 1, so cron:run says FAILED and writes no heartbeat until somebody has
 * looked at the log.
 */
final readonly class FinalizeCompetitionsCommand implements Command
{
    public function __construct(
        private DecideDuels $decideDuels,
        private RankLeagues $rankLeagues,
    ) {
    }

    public function name(): string
    {
        return 'competitions:finalize';
    }

    public function description(): string
    {
        return 'Lets duel proposals expire after 72 hours, decides the duels whose last days are final and ranks league results.';
    }

    public function run(array $arguments, Output $output): int
    {
        if ($arguments !== []) {
            $output->error($this->name() . ' takes no arguments.');

            return 1;
        }

        $duels = $this->decideDuels->execute();
        $leagues = $this->rankLeagues->execute();

        // Counts only: nothing here says whose duels or results they were.
        $output->line(sprintf(
            'Expired %s, decided %s, ranked %s.',
            self::count($duels->expired, 'duel proposal'),
            self::count($duels->decided, 'duel'),
            self::count($leagues->ranked, 'league result'),
        ));

        if ($duels->failed > 0) {
            $output->error('Could not decide ' . self::count($duels->failed, 'duel') . ': see the log.');
        }
        if ($leagues->failed > 0) {
            $output->error('Could not rank ' . self::count($leagues->failed, 'league result') . ': see the log.');
        }

        return $duels->failed + $leagues->failed > 0 ? 1 : 0;
    }

    private static function count(int $number, string $noun): string
    {
        return "{$number} {$noun}" . ($number === 1 ? '' : 's');
    }
}
