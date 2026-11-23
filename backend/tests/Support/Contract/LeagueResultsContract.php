<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\CalendarPeriod;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Application\Competition\LeagueResults;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the league results does (HLD §4.9): the PDO one against the
 * database and the in-memory one the use case tests run on.
 */
trait LeagueResultsContract
{
    abstract protected function leagueResults(): LeagueResults;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_COMP_06_a_saved_result_is_found_by_user_and_period_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $week = TestData::leagueResult(1);
        $half = TestData::leagueResult(1, LeaguePeriod::HalfYear, '2026-H1', 805, 145600, 145705, 3, 180, 181, '2026-07-02 22:00:41', '2026-07-02 22:15:41');
        $ranked = TestData::leagueResult(2, LeaguePeriod::Quarter, '2026-Q3', 10, 0, 920, 92, 92, 92, '2026-10-02 22:00:00', '2026-10-02 22:00:00', [1, 3, 450], '2026-10-03 23:01:05');

        foreach ([$week, $half, $ranked] as $result) {
            $this->leagueResults()->save($result);
        }

        self::assertEquals($week, $this->leagueResults()->find(TestData::userId(1), CalendarPeriod::fromKey(LeaguePeriod::Week, '2026-W46')));
        self::assertEquals($half, $this->leagueResults()->find(TestData::userId(1), CalendarPeriod::fromKey(LeaguePeriod::HalfYear, '2026-H1')));
        self::assertEquals($ranked, $this->leagueResults()->find(TestData::userId(2), CalendarPeriod::fromKey(LeaguePeriod::Quarter, '2026-Q3')));
        // Another user, another period of the same type, the same key under another type.
        self::assertNull($this->leagueResults()->find(TestData::userId(2), CalendarPeriod::fromKey(LeaguePeriod::Week, '2026-W46')));
        self::assertNull($this->leagueResults()->find(TestData::userId(1), CalendarPeriod::fromKey(LeaguePeriod::Week, '2026-W45')));
        self::assertNull($this->leagueResults()->find(TestData::userId(1), CalendarPeriod::fromKey(LeaguePeriod::Month, '2026-11')));
    }

    public function test_FR_COMP_08_saving_the_result_of_a_user_and_period_again_replaces_it(): void
    {
        $this->givenUsers(TestData::userId(1));
        $this->leagueResults()->save(TestData::leagueResult(1));

        $ranked = TestData::leagueResult(1, award: [2, 4, 100], checkAt: '2026-11-17 23:15:20');
        $this->leagueResults()->save($ranked);

        self::assertEquals([$ranked], $this->leagueResults()->ofUser(TestData::userId(1)));
    }

    public function test_FR_COMP_08_the_results_that_wait_for_their_rank_are_found_longest_waiting_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->leagueResults()->save(TestData::leagueResult(2, checkAt: '2026-11-17 23:30:00'));
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Week, '2026-W46', checkAt: '2026-11-17 23:00:20'));
        // Due in the same second: by user, then by type, then by key.
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Month, '2026-10', checkAt: '2026-11-17 23:00:20'));
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Month, '2026-09', checkAt: '2026-11-17 23:00:20'));
        $this->leagueResults()->save(TestData::leagueResult(3, checkAt: '2026-11-17 23:00:20'));
        // Not due yet, and ranked already.
        $this->leagueResults()->save(TestData::leagueResult(3, LeaguePeriod::Month, '2026-10', checkAt: '2026-11-17 23:30:01'));
        $this->leagueResults()->save(TestData::leagueResult(2, LeaguePeriod::Month, '2026-10', checkAt: '2026-11-01 00:00:00', award: [1, 1, 0]));

        $found = $this->leagueResults()->unranked(TestData::time('2026-11-17 23:30:00'), 50);

        self::assertSame(
            ['1 month 2026-09', '1 month 2026-10', '1 week 2026-W46', '3 week 2026-W46', '2 week 2026-W46'],
            self::labels($found),
        );
        self::assertSame(['1 month 2026-09', '1 month 2026-10'], self::labels($this->leagueResults()->unranked(TestData::time('2026-11-17 23:30:00'), 2)));
        self::assertSame([], $this->leagueResults()->unranked(TestData::time('2026-11-17 23:00:19'), 50));
    }

    public function test_FR_ACC_07_the_results_of_a_user_are_listed_in_the_order_they_were_scored(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Week, '2026-W46', scoredAt: '2026-11-17 23:00:20'));
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Week, '2026-W45', scoredAt: '2026-11-10 23:00:20'));
        // Scored in the same second: the 30th of September ends a month and a quarter.
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Quarter, '2026-Q3', scoredAt: '2026-10-02 22:00:00'));
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Month, '2026-09', scoredAt: '2026-10-02 22:00:00'));
        $this->leagueResults()->save(TestData::leagueResult(2, LeaguePeriod::Week, '2026-W44', scoredAt: '2026-11-03 23:00:20'));

        self::assertSame(
            ['1 month 2026-09', '1 quarter 2026-Q3', '1 week 2026-W45', '1 week 2026-W46'],
            self::labels($this->leagueResults()->ofUser(TestData::userId(1))),
        );
        self::assertSame([], $this->leagueResults()->ofUser(TestData::userId(3)));
    }

    /**
     * @param list<LeagueResult> $results
     *
     * @return list<string> "<user number> <type> <key>" of each.
     */
    private static function labels(array $results): array
    {
        return array_map(
            static fn (LeagueResult $result): string => ((int) substr($result->userId->value, -12)) . " {$result->period->type->value} {$result->period->key}",
            $results,
        );
    }
}
