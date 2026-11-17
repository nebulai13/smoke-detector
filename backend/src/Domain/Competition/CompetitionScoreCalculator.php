<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Competition;

use InvalidArgumentException;
use SmokeDetector\Domain\Day\DayOutcome;

/**
 * Scores one participant over the days of a competition window (SRS FR-COMP-03, HLD §5.4):
 *
 *   expected  = B0 × counted days
 *   actual    = sum of the counts of checked-in days
 *             + max(B0, logged count) for every day without a check-in
 *   reduction = 1 − actual / expected
 *
 * A day without a check-in can therefore never score better than the baseline, and never better
 * than what was actually logged on it: skipping the check-in is never worth more than checking in.
 *
 * A streak freeze is deliberately not an input: it protects the check-in streak and nothing else
 * (SRS FR-GAME-04), so a frozen day is scored like any other day without a check-in.
 */
final readonly class CompetitionScoreCalculator
{
    /** Baselines are stored in tenths of a session (see Baseline), so whole sessions are converted. */
    private const int TENTHS_PER_SESSION = 10;

    /**
     * @param list<DayOutcome> $days The counted days of the window in any order: the days from the
     *                               window start (or the later join date) on. At least one.
     */
    public function score(FrozenBaseline $baseline, array $days): CompetitionScore
    {
        if ($days === []) {
            // 0 / 0 is undefined. Calling it 0 % would rank someone who has not played yet above
            // everyone who is over baseline, so the caller has to treat this participant as unrated.
            throw new InvalidArgumentException('A window without counted days cannot be scored.');
        }

        $expectedTenths = 0;
        $actualTenths = 0;
        $checkIns = 0;
        $smokeFreeDays = 0;

        foreach ($days as $day) {
            $expectedTenths = self::add($expectedTenths, $baseline->tenths);

            if (!$day->checkedIn) {
                // The logged count of an unconfirmed day is not trusted as complete, so it can only
                // raise the day above B0, never lower it. Otherwise skipping the check-in on a
                // heavy day would score better than confirming it.
                $actualTenths = self::add(
                    $actualTenths,
                    max($baseline->tenths, self::sessionsToTenths($day->count)),
                );
                continue;
            }

            $checkIns++;
            if ($day->isSmokeFree()) {
                $smokeFreeDays++;
            }
            $actualTenths = self::add($actualTenths, self::sessionsToTenths($day->count));
        }

        return new CompetitionScore(
            Reduction::fromTotals($actualTenths, $expectedTenths),
            $smokeFreeDays,
            $checkIns,
            count($days),
        );
    }

    /**
     * Checked before adding: past PHP_INT_MAX a sum silently turns into a float, and the score must
     * stay inside the bound in which Reduction compares exactly.
     */
    private static function add(int $totalTenths, int $tenths): int
    {
        if ($tenths > Reduction::MAX_TENTHS - $totalTenths) {
            throw new InvalidArgumentException('The window totals are too large to be scored exactly.');
        }

        return $totalTenths + $tenths;
    }

    private static function sessionsToTenths(int $sessions): int
    {
        if ($sessions > intdiv(Reduction::MAX_TENTHS, self::TENTHS_PER_SESSION)) {
            throw new InvalidArgumentException('A daily count is too large to be scored exactly.');
        }

        return $sessions * self::TENTHS_PER_SESSION;
    }
}
