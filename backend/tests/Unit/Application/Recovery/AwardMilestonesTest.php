<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Recovery;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * The health milestones a user has reached for good (HLD §4.11 "Milestones for friends"; FR-GAME-09,
 * FR-FRND-03): stored in the transaction that makes a day final, from the last session up to that day
 * and the end of that day. Open days do not count, and what is stored is never taken back, so friends
 * cannot see a session of today or a relapse in it.
 *
 * It is noon UTC on Tuesday, 17 November 2026, and Anna lives in Vienna: her days end at 23:00 UTC,
 * and her 14th became final at 23:00 UTC on the 16th.
 */
final class AwardMilestonesTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_GAME_09_a_final_day_stores_what_was_reached_by_its_end_with_the_moment_it_was_reached(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        // 13 hours before the end of the day.
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00', 3);

        $this->clock->travelTo(TestData::time('2026-11-16 22:59:59'));
        $this->finalizeDays()->execute();
        self::assertSame([], $this->milestonesOf($this->anna));

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(
            [['heart_rate', '2026-11-14 10:20:00'], ['carbon_monoxide', '2026-11-14 22:00:00']],
            $this->milestonesOf($this->anna),
        );
    }

    public function test_FR_GAME_09_a_milestone_counts_when_its_span_is_over_at_the_end_of_the_day_to_the_second(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenOnboarded($bob, '2026-11-14');
        // Exactly 12 hours before the end of the day, and a second less.
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 11:00:00');
        $this->givenLog($bob, 2, '2026-11-14', '2026-11-14 11:00:01');

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));
        self::assertSame(['heart_rate'], array_column($this->milestonesOf($bob), 0));
    }

    public function test_FR_GAME_09_a_day_without_a_session_counts_on_from_the_session_before(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        // Ten minutes before the end of the 14th. Nothing on the 15th.
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 22:50:00');

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();
        self::assertSame([], $this->milestonesOf($this->anna));

        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(
            [['heart_rate', '2026-11-14 23:10:00'], ['carbon_monoxide', '2026-11-15 10:50:00']],
            $this->milestonesOf($this->anna),
        );
    }

    public function test_FR_GAME_09_two_weeks_without_a_session_are_reached_with_the_day_that_ends_after_them(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-01', '2026-11-14');
        $this->givenLog($this->anna, 1, '2026-11-01', '2026-11-01 08:00:00');

        // The 14th ends 13 days and 15 hours after the session.
        $this->finalizeDays()->execute();
        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));

        // The 15th ends 14 days and 15 hours after it.
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(
            [['heart_rate', '2026-11-01 08:20:00'], ['carbon_monoxide', '2026-11-01 20:00:00'], ['circulation', '2026-11-15 08:00:00']],
            $this->milestonesOf($this->anna),
        );
    }

    public function test_FR_FRND_03_a_session_of_a_day_that_is_not_final_changes_nothing(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00');
        // Yesterday, the 16th, is still open: its session is no business of the 14th.
        $this->givenLog($this->anna, 2, '2026-11-16', '2026-11-16 09:00:00');

        $this->finalizeDays()->execute();

        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));
    }

    public function test_FR_FRND_03_what_was_reached_once_stays_and_keeps_its_first_moment(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00');
        $this->finalizeDays()->execute();
        $before = $this->milestonesOf($this->anna);

        // A session two hours before the end of the 15th: by the end of that day only 20 minutes are reached again.
        $this->givenLog($this->anna, 2, '2026-11-15', '2026-11-15 21:00:00');
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertCount(2, $before);
        self::assertSame($before, $this->milestonesOf($this->anna));
    }

    public function test_FR_GAME_09_without_a_logged_session_nothing_is_reached(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-01', '2026-11-14');
        // Logged by mistake, and deleted again.
        $this->givenLog($this->anna, 1, '2026-11-02', '2026-11-02 08:00:00', deletedAt: '2026-11-02 08:05:00');

        $this->finalizeDays()->execute();

        self::assertNotNull($this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->result);
        self::assertSame([], $this->milestoneRecords->all());
    }

    public function test_FR_GAME_09_the_sessions_of_another_user_do_not_count(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenOnboarded($bob, '2026-11-14');
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00');
        $this->givenLog($bob, 2, '2026-11-14', '2026-11-14 22:55:00');

        $this->finalizeDays()->execute();

        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));
        self::assertSame([], $this->milestonesOf($bob));
    }

    public function test_a_day_that_could_not_be_finalized_leaves_no_milestone_behind(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00');
        $this->givenDay($this->anna, '2026-11-14', 1, '2026-11-14 21:00:00');
        // The id the first XP event of the run will get is taken: storing it fails, and the step is
        // rolled back as a whole.
        $this->xpLedger->append(new XpEvent(SequentialIdGenerator::id(2), TestData::userId(99), self::date('2026-11-01'), XpSource::Reaction, 2, null, $this->now()));

        $report = $this->finalizeDays()->execute();

        self::assertSame(1, $report->failedUsers);
        self::assertSame([], $this->milestoneRecords->all());
    }
}
