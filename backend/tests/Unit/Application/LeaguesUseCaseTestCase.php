<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\CalendarPeriod;
use SmokeDetector\Application\Competition\LeagueAward;
use SmokeDetector\Application\Competition\LeagueEntry;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Application\Competition\LeagueView;
use SmokeDetector\Application\Competition\RankLeagues;
use SmokeDetector\Application\Competition\ShowLeague;
use SmokeDetector\Application\Competition\ShowXpLeague;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\TestData;

/**
 * The world of the leagues (HLD §4.9), without any I/O: what the duels have, plus the in-memory
 * league results (which the day finalization of LoggingUseCaseTestCase writes to already).
 *
 * It is noon UTC on Tuesday, 17 November 2026: the second day of the week 2026-W47 for users in
 * Vienna. The week before, 2026-W46, ran from Monday the 9th to Sunday the 15th. A result of that
 * week against a baseline of 12.0 has an expected total of 84.
 */
abstract class LeaguesUseCaseTestCase extends DuelsUseCaseTestCase
{
    protected function rankLeagues(): RankLeagues
    {
        return new RankLeagues(
            $this->transactions,
            $this->clock,
            $this->users,
            $this->friendLinks,
            $this->leagueResults,
            $this->calendars(),
            $this->progressRecords,
            $this->xpLedger,
            $this->ids,
            $this->logger,
        );
    }

    protected function showLeague(): ShowLeague
    {
        return new ShowLeague($this->transactions, $this->clock, $this->users, $this->friendLinks, $this->calendars(), $this->dayRecords, $this->leagueResults);
    }

    protected function showXpLeague(): ShowXpLeague
    {
        return new ShowXpLeague($this->transactions, $this->clock, $this->users, $this->friendLinks, $this->calendars(), $this->xpLedger);
    }

    /**
     * A user with this name who did the onboarding on $startDate with a baseline of 12. Every day
     * before the 16th is final.
     */
    protected function givenPlayer(string $name, string $startDate = '2026-10-01'): User
    {
        $user = $this->givenNamed($name);
        $this->givenOnboarded($user, $startDate, max($startDate, '2026-11-16'));

        return $user;
    }

    /**
     * The stored result of a user for a period, against a baseline of 12.0 over every day of the
     * period, scored at $scoredAt and to be looked at for ranking from then on.
     *
     * @param int $sessions What counted over the period, in sessions.
     */
    protected function givenResult(
        User $user,
        int $sessions,
        int $smokeFreeDays = 0,
        ?int $checkIns = null,
        string $key = '2026-W46',
        LeaguePeriod $type = LeaguePeriod::Week,
        string $scoredAt = '2026-11-17 11:00:00',
        ?LeagueAward $award = null,
    ): LeagueResult {
        $period = CalendarPeriod::fromKey($type, $key);
        $result = new LeagueResult(
            $user->id, $period, 120, $sessions * 10, 120 * $period->days(), $smokeFreeDays, $checkIns ?? $period->days(), $period->days(), 'v1',
            TestData::time($scoredAt), TestData::time($scoredAt), $award,
        );
        $this->leagueResults->save($result);

        return $result;
    }

    protected function resultOf(User $user, string $key = '2026-W46', LeaguePeriod $type = LeaguePeriod::Week): LeagueResult
    {
        return $this->leagueResults->find($user->id, CalendarPeriod::fromKey($type, $key)) ?? self::fail('The user has no result for that period.');
    }

    /**
     * @return array{int, int, int}|null Rank, participants and XP of a stored result.
     */
    protected function awardOf(User $user, string $key = '2026-W46', LeaguePeriod $type = LeaguePeriod::Week): ?array
    {
        $award = $this->resultOf($user, $key, $type)->award;

        return $award === null ? null : [$award->rank, $award->participants, $award->xp];
    }

    /**
     * @return list<array{int|string|null, string, int|null, int|null, int|null, int|null}> Of every entry: the
     *                                                                                      rank, or where the
     *                                                                                      friend stands against
     *                                                                                      the user (ahead, level,
     *                                                                                      behind), or null; then
     *                                                                                      name, percent, smoke-free
     *                                                                                      days, check-ins and
     *                                                                                      counted days.
     */
    protected static function entries(LeagueView $view): array
    {
        return array_map(
            static fn (LeagueEntry $entry): array => [
                $entry->rank ?? $entry->standing?->value, $entry->user->displayName->value . ($entry->you ? ' (you)' : ''), $entry->reductionPercent,
                $entry->smokeFreeDays, $entry->checkIns, $entry->countedDays,
            ],
            $view->entries,
        );
    }
}
