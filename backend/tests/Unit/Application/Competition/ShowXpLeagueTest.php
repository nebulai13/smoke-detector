<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Competition;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\XpLeagueEntry;
use SmokeDetector\Application\Competition\XpLeagueView;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * The weekly XP ranking (SRS FR-COMP-07, HLD §4.9): the user and their friends by the XP that became
 * theirs in the user's current ISO week.
 *
 * For somebody in Vienna the week 2026-W47 runs from Sunday the 15th, 23:00 UTC, to Sunday the 22nd,
 * 23:00 UTC. It is Tuesday the 17th, noon UTC.
 */
final class ShowXpLeagueTest extends LeaguesUseCaseTestCase
{
    private User $anna;
    private User $bob;
    private User $carol;
    private int $events = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenPlayer('Anna');
        $this->bob = $this->givenPlayer('Bob');
        $this->carol = $this->givenPlayer('Carol');
        $this->givenFriends($this->anna, $this->bob);
        $this->givenFriends($this->carol, $this->anna);
    }

    public function test_FR_COMP_07_friends_are_ranked_by_the_xp_of_the_week(): void
    {
        $this->givenXp($this->anna, 30, '2026-11-16 23:00:10');
        $this->givenXp($this->anna, 50, '2026-11-17 08:00:00', XpSource::Duel);
        $this->givenXp($this->bob, 70, '2026-11-16 23:00:20');
        $this->givenXp($this->bob, 150, '2026-11-17 11:00:00', XpSource::League);
        $this->givenXp($this->carol, 10, '2026-11-16 23:00:30');

        $view = $this->showXpLeague()->show($this->anna->id);

        self::assertSame(['2026-W47', '2026-11-16', '2026-11-22'], [$view->period->key, $view->period->first()->value, $view->period->last()->value]);
        self::assertSame([[1, 'Bob', 220], [2, 'Anna (you)', 80], [3, 'Carol', 10]], self::ranking($view));
        self::assertSame([$this->bob->id->value, 'Bob', 'avatar-07'], self::profile($view->entries[0]->user));
        // Bob is not Carol's friend: she is not in his ranking.
        self::assertSame([[1, 'Bob (you)', 220], [2, 'Anna', 80]], self::ranking($this->showXpLeague()->show($this->bob->id)));
    }

    public function test_FR_COMP_07_the_week_is_the_users_week_to_the_second(): void
    {
        // The last second before the week, its first instant, its last second, and the instant after it.
        $this->givenXp($this->anna, 1, '2026-11-15 22:59:59');
        $this->givenXp($this->anna, 2, '2026-11-15 23:00:00');
        $this->givenXp($this->anna, 4, '2026-11-22 22:59:59');
        $this->givenXp($this->anna, 8, '2026-11-22 23:00:00');
        // The same instants for Bob, whose own week (in Auckland) began twelve hours earlier.
        $bob = $this->givenZoneChange($this->bob, '2026-10-02', 'Pacific/Auckland');
        $this->givenXp($bob, 16, '2026-11-15 10:59:59');
        $this->givenXp($bob, 32, '2026-11-15 11:00:00');
        $this->givenXp($bob, 64, '2026-11-15 22:59:59');
        $this->givenXp($bob, 128, '2026-11-15 23:00:00');

        // What counts is when the XP was written, not the day it belongs to. And the week is the
        // viewer's, for everybody in the list.
        self::assertSame([[1, 'Bob', 128], [2, 'Anna (you)', 6], [3, 'Carol', 0]], self::ranking($this->showXpLeague()->show($this->anna->id)));
        self::assertSame([[1, 'Bob (you)', 224], [2, 'Anna', 3]], self::ranking($this->showXpLeague()->show($bob->id)));
    }

    public function test_FR_COMP_07_the_same_xp_shares_a_rank_and_somebody_without_xp_is_listed_with_zero(): void
    {
        $dave = $this->givenPlayer('Dave');
        // No plan yet: nothing to earn, and a friend all the same.
        $zoe = $this->givenNamed('Zoe');
        $this->givenFriends($this->anna, $dave);
        $this->givenFriends($this->anna, $zoe);
        $this->givenXp($this->anna, 30, '2026-11-16 23:00:10');
        $this->givenXp($this->carol, 30, '2026-11-16 23:00:30');
        $this->givenXp($dave, 100, '2026-11-17 09:00:00');

        // 1, 2, 2, 4, 4: by XP, then by name.
        self::assertSame(
            [[1, 'Dave', 100], [2, 'Anna (you)', 30], [2, 'Carol', 30], [4, 'Bob', 0], [4, 'Zoe', 0]],
            self::ranking($this->showXpLeague()->show($this->anna->id)),
        );
    }

    public function test_FR_FRND_02_only_friends_are_in_the_ranking(): void
    {
        $asking = $this->givenPlayer('Dave');
        $this->givenRequest($asking, $this->anna);
        $stranger = $this->givenPlayer('Erin');
        $this->givenXp($asking, 500, '2026-11-17 09:00:00');
        $this->givenXp($stranger, 500, '2026-11-17 09:00:00');

        self::assertSame([[1, 'Anna (you)', 0], [1, 'Bob', 0], [1, 'Carol', 0]], self::ranking($this->showXpLeague()->show($this->anna->id)));
    }

    public function test_FR_COMP_07_a_new_week_starts_at_zero(): void
    {
        $this->givenXp($this->anna, 80, '2026-11-17 08:00:00');

        // Monday the 23rd, one o'clock at night in Vienna.
        $this->clock->travelTo(TestData::time('2026-11-23 00:00:00'));
        $view = $this->showXpLeague()->show($this->anna->id);

        self::assertSame(['2026-W48', '2026-11-23'], [$view->period->key, $view->period->first()->value]);
        self::assertSame([[1, 'Anna (you)', 0], [1, 'Bob', 0], [1, 'Carol', 0]], self::ranking($view));
    }

    public function test_FR_ACC_06_without_a_plan_there_is_no_ranking_and_without_an_account_neither(): void
    {
        $noPlan = $this->givenNamed('Zoe');

        try {
            $this->showXpLeague()->show($noPlan->id);
            self::fail('Should have been refused.');
        } catch (PlanRequired) {
            $this->addToAssertionCount(1);
        }

        $this->users->delete($this->anna->id);
        try {
            $this->showXpLeague()->show($this->anna->id);
            self::fail('Should have been refused.');
        } catch (AccountNotFound) {
            self::assertSame([], $this->users->lockLog);
        }
    }

    /**
     * XP of a user, written to the ledger at $writtenAt.
     */
    private function givenXp(User $user, int $amount, string $writtenAt, XpSource $source = XpSource::CheckIn): void
    {
        $this->xpLedger->append(new XpEvent(TestData::uuid(900_000 + ++$this->events), $user->id, self::date('2026-11-10'), $source, $amount, null, TestData::time($writtenAt)));
    }

    /**
     * @return list<array{int, string, int}> Rank, name and XP of every entry.
     */
    private static function ranking(XpLeagueView $view): array
    {
        return array_map(
            static fn (XpLeagueEntry $entry): array => [$entry->rank, $entry->user->displayName->value . ($entry->you ? ' (you)' : ''), $entry->xp],
            $view->entries,
        );
    }
}
