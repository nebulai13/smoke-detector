<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Feed;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * What is written to the activity feed, and when (HLD §4.12 "What is written"; FR-SOC-01): level-ups,
 * badges, duel results and health milestones, each in the transaction that makes it final. Nothing
 * comes from an open day. Streak milestones are in the feed as their badges.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Users live in Vienna; with a baseline of 12 the limit is 9.
 */
final class PublishToFeedTest extends LeaguesUseCaseTestCase
{
    private const string NOW = '2026-11-17 12:00:00';

    // ---- Days -------------------------------------------------------------------------------------

    public function test_FR_SOC_01_a_final_day_writes_its_new_badges_its_new_milestones_and_a_level_that_was_reached(): void
    {
        $anna = $this->givenNamed('Anna');
        // 90 XP so far. A day at the limit earns 30: level 1 begins at 100.
        $this->givenOnboarded($anna, '2026-11-14', totalXp: 90);
        $this->givenLog($anna, 1, '2026-11-14', '2026-11-14 10:00:00', 9);
        $this->givenDay($anna, '2026-11-14', 9, '2026-11-14 21:00:00');

        // The check-in alone writes nothing: the day is open.
        self::assertSame([], $this->feedEvents->all());
        $this->finalizeDays()->execute();

        self::assertEqualsCanonicalizing(
            [
                ['badge_earned', 'first_check_in', null],
                ['health_milestone', 'heart_rate', null],
                ['health_milestone', 'carbon_monoxide', null],
                ['level_up', '1', null],
            ],
            $this->feedOf($anna),
        );
        // Every entry has an id of its own and the time of the step.
        $events = $this->feedEvents->all();
        self::assertEqualsCanonicalizing(
            [SequenceFeedIds::id(1), SequenceFeedIds::id(2), SequenceFeedIds::id(3), SequenceFeedIds::id(4)],
            array_map(static fn (FeedEvent $event): string => $event->id, $events),
        );
        self::assertSame([self::NOW], array_values(array_unique(array_map(static fn (FeedEvent $event): string => $event->createdAt->format('Y-m-d H:i:s'), $events))));
    }

    public function test_FR_SOC_01_what_the_user_holds_already_is_not_written_again(): void
    {
        $anna = $this->givenNamed('Anna');
        $this->givenOnboarded($anna, '2026-11-13');
        foreach (['2026-11-13', '2026-11-14'] as $number => $date) {
            $this->givenLog($anna, $number + 1, $date, "{$date} 10:00:00", 9);
            $this->givenDay($anna, $date, 9, "{$date} 21:00:00");
        }

        // Two final days, 60 XP: the first check-in and the two short milestones once, and no level.
        $this->finalizeDays()->execute();

        self::assertEqualsCanonicalizing(
            [['badge_earned', 'first_check_in', null], ['health_milestone', 'heart_rate', null], ['health_milestone', 'carbon_monoxide', null]],
            $this->feedOf($anna),
        );
    }

    public function test_FR_SOC_01_a_day_without_anything_new_writes_nothing(): void
    {
        $anna = $this->givenNamed('Anna');
        $this->givenOnboarded($anna, '2026-11-14');
        $this->givenLog($anna, 1, '2026-11-14', '2026-11-14 10:00:00', 4);

        $this->finalizeDays()->execute();

        self::assertTrue($this->dayRecords->find($anna->id, self::date('2026-11-14'))?->isFinal());
        self::assertSame([], $this->feedEvents->all());
    }

    public function test_FR_SOC_01_two_levels_reached_with_one_day_are_one_entry_with_the_higher_level(): void
    {
        $anna = $this->givenNamed('Anna');
        // A check-in streak of 99 days and no XP (as if the XP had come later than the streaks).
        $this->givenOnboarded($anna, '2026-08-01', '2026-11-14', streaks: [99, 99, 0, 0, 0]);
        $this->givenLog($anna, 1, '2026-11-14', '2026-11-14 10:00:00', 20);
        $this->givenDay($anna, '2026-11-14', 20, '2026-11-14 21:00:00');

        $this->finalizeDays()->execute();

        // 10 XP for the check-in and 500 for the streak of 100. Level 2 begins at 300, level 3 at 600.
        self::assertSame(510, $this->progressRecords->find($anna->id)?->progress->totalXp);
        self::assertSame(
            [['level_up', '2', null]],
            array_values(array_filter($this->feedOf($anna), static fn (array $entry): bool => $entry[0] === 'level_up')),
        );
    }

    public function test_a_day_that_could_not_be_finalized_leaves_no_entry_behind(): void
    {
        $anna = $this->givenNamed('Anna');
        $this->givenOnboarded($anna, '2026-11-14');
        $this->givenLog($anna, 1, '2026-11-14', '2026-11-14 10:00:00', 9);
        $this->givenDay($anna, '2026-11-14', 9, '2026-11-14 21:00:00');
        // The id the first XP event of the run will get is taken: storing it fails, and the step is rolled back.
        $this->xpLedger->append(new XpEvent(SequentialIdGenerator::id($this->nextIdNumber()), TestData::userId(99), self::date('2026-11-01'), XpSource::Reaction, 2, null, $this->now()));

        $report = $this->finalizeDays()->execute();

        self::assertSame(1, $report->failedUsers);
        self::assertSame([], $this->feedEvents->all());
    }

    // ---- Duels ------------------------------------------------------------------------------------

    public function test_FR_SOC_01_a_duel_that_was_won_is_an_entry_of_the_winner_with_the_opponent_and_none_of_the_loser(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $this->givenDays($anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($bob, '2026-11-03', [10, 10, 10, 10, 10, 10, 10]);

        $this->decideDuels()->execute();

        // The win, the badge of the first win, and level 1 with the 100 XP of the win.
        self::assertEqualsCanonicalizing(
            [['duel_won', null, $bob->id->value], ['badge_earned', 'first_duel_won', null], ['level_up', '1', null]],
            $this->feedOf($anna),
        );
        // Bob lost. A loss is not announced, and 20 XP are no level.
        self::assertSame([], $this->feedOf($bob));
        self::assertSame([self::NOW], array_values(array_unique(array_map(static fn (FeedEvent $event): string => $event->createdAt->format('Y-m-d H:i:s'), $this->feedEvents->all()))));
    }

    public function test_FR_SOC_01_the_opponent_who_wins_has_the_entry_also_when_the_challenger_has_no_reduction(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        // Anna, who challenged, smoked exactly her baseline. Bob has half of his.
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $this->givenDays($anna, '2026-11-03', [12, 12, 12, 12, 12, 12, 12]);
        $this->givenDays($bob, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);

        $this->decideDuels()->execute();

        self::assertSame([], $this->feedOf($anna));
        self::assertEqualsCanonicalizing(
            [['duel_won', null, $anna->id->value], ['badge_earned', 'first_duel_won', null], ['level_up', '1', null]],
            $this->feedOf($bob),
        );
    }

    public function test_FR_SOC_01_a_draw_is_an_entry_of_both(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $this->givenDays($anna, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);
        $this->givenDays($bob, '2026-11-03', [6, 6, 6, 6, 6, 6, 6]);

        $this->decideDuels()->execute();

        self::assertSame([['duel_draw', null, $bob->id->value]], $this->feedOf($anna));
        self::assertSame([['duel_draw', null, $anna->id->value]], $this->feedOf($bob));
    }

    public function test_FR_SOC_01_a_duel_without_a_reduction_is_no_entry(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        // Two who did nothing: a draw that pays nothing.
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        // Carol smoked exactly her baseline and Dave more than his: Carol wins, and nobody is rewarded.
        $carol = $this->givenPlayer('Carol', '2026-11-01');
        $dave = $this->givenPlayer('Dave', '2026-11-01');
        $this->givenRunning(2, $carol, $dave, '2026-11-03');
        $this->givenDays($carol, '2026-11-03', [12, 12, 12, 12, 12, 12, 12]);
        $this->givenDays($dave, '2026-11-03', [14, 14, 14, 14, 14, 14, 12]);

        self::assertSame(2, $this->decideDuels()->execute()->decided);

        self::assertSame([], $this->feedEvents->all());
    }

    // ---- Leagues ----------------------------------------------------------------------------------

    public function test_FR_SOC_01_a_league_rank_writes_the_champion_badge_and_the_levels_its_xp_reaches(): void
    {
        $anna = $this->givenPlayer('Anna');
        $bob = $this->givenPlayer('Bob');
        $carol = $this->givenPlayer('Carol');
        $this->givenFriends($anna, $bob);
        $this->givenFriends($anna, $carol);
        $this->givenFriends($bob, $carol);
        $this->givenResult($anna, 42);
        $this->givenResult($bob, 60);
        $this->givenResult($carol, 72);

        $this->rankLeagues()->execute();

        // 150 XP and 100 XP are level 1. 50 XP are not, and a rank is no entry of its own.
        self::assertEqualsCanonicalizing([['badge_earned', 'league_champion_week', null], ['level_up', '1', null]], $this->feedOf($anna));
        self::assertSame([['level_up', '1', null]], $this->feedOf($bob));
        self::assertSame([], $this->feedOf($carol));
    }

    private function nextIdNumber(): int
    {
        // The user of this test has the first id, so the first XP event of the run gets the second.
        return 2;
    }
}
