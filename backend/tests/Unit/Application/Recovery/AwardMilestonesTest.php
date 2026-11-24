<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Recovery;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * The health milestones a user has reached for good (HLD §4.11 "Milestones for friends"; FR-GAME-09,
 * FR-FRND-03): stored in the transaction that makes a day final. They count confirmed time only: from
 * the last session logged up to that day, or from the end of the last day without a check-in if that
 * is later, to the end of the day. A day without a check-in is not a day without a session (FR-LOG-03),
 * so an account that is not used reaches nothing. Open days do not count, and what is stored is never
 * taken back, so friends cannot see a session of today or a relapse in it.
 *
 * It is noon UTC on Tuesday, 17 November 2026, and Anna lives in Vienna: her days end at 23:00 UTC,
 * and her 14th became final at 23:00 UTC on the 16th.
 */
final class AwardMilestonesTest extends LoggingUseCaseTestCase
{
    private User $anna;
    private int $logs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_GAME_09_a_final_day_stores_what_was_reached_by_its_end_with_the_moment_it_was_reached(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        // 13 hours before the end of the day.
        $this->givenChecked($this->anna, '2026-11-14', '2026-11-14 10:00:00');

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
        $this->givenChecked($this->anna, '2026-11-14', '2026-11-14 11:00:00');
        $this->givenChecked($bob, '2026-11-14', '2026-11-14 11:00:01');

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));
        self::assertSame(['heart_rate'], array_column($this->milestonesOf($bob), 0));
    }

    public function test_FR_GAME_09_a_checked_in_day_without_a_session_counts_on_from_the_session_before(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        // Ten minutes before the end of the 14th. The 15th is checked in with no session.
        $this->givenChecked($this->anna, '2026-11-14', '2026-11-14 22:50:00');
        $this->givenChecked($this->anna, '2026-11-15');

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
        $this->givenTwoWeeksFromTheFirst();

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

    public function test_FR_LOG_03_a_day_without_a_check_in_is_not_a_day_without_a_session(): void
    {
        // As before, but the 10th has no check-in: nobody said that it was smoke-free.
        $this->givenTwoWeeksFromTheFirst(missed: '2026-11-10');

        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->finalizeDays()->execute();

        // The count begins again at the end of the 10th, 23:00 UTC: five days until the end of the 15th.
        self::assertSame(
            [['heart_rate', '2026-11-10 23:20:00'], ['carbon_monoxide', '2026-11-11 11:00:00']],
            $this->milestonesOf($this->anna),
        );
    }

    public function test_FR_GAME_09_a_final_day_without_a_check_in_stores_nothing(): void
    {
        $this->givenTwoWeeksFromTheFirst();
        // The 14th was checked in, the 15th is not.
        $this->givenDay($this->anna, '2026-11-15');
        $this->finalizeDays()->execute();
        $before = $this->milestonesOf($this->anna);

        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->finalizeDays()->execute();

        // Two weeks are over by the clock, but the last of the days is not confirmed.
        self::assertTrue($this->dayRecords->find($this->anna->id, self::date('2026-11-15'))?->isFinal());
        self::assertSame($before, $this->milestonesOf($this->anna));
        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($before, 0));
    }

    public function test_FR_GAME_09_somebody_who_quit_before_joining_reaches_them_from_the_first_day_of_the_plan(): void
    {
        // Anna says her last session was in 2019, joined on the 1st, logged nothing and checked in every day.
        $this->givenOnboarded($this->anna, '2026-11-01');
        $plan = $this->plans->find($this->anna->id) ?? self::fail('No plan.');
        $this->plans->save(new Plan($plan->userId, $plan->goal, $plan->taperSpeed, $plan->defaultProduct, $plan->startDate, $plan->rulesVersion, LocalDate::fromString('2019-03-31')));
        for ($day = 1; $day <= 14; $day++) {
            $this->givenChecked($this->anna, sprintf('2026-11-%02d', $day));
        }

        $this->finalizeDays()->execute();

        // For friends only confirmed time counts: from the start of the 1st in Vienna (23:00 UTC on
        // the 31st), not from 2019. Two weeks are over with the end of the 14th.
        self::assertSame(
            [['heart_rate', '2026-10-31 23:20:00'], ['carbon_monoxide', '2026-11-01 11:00:00'], ['circulation', '2026-11-14 23:00:00']],
            $this->milestonesOf($this->anna),
        );
    }

    public function test_FR_GAME_09_a_day_without_a_check_in_begins_the_count_again_also_after_a_stated_day(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-01');
        $plan = $this->plans->find($this->anna->id) ?? self::fail('No plan.');
        $this->plans->save(new Plan($plan->userId, $plan->goal, $plan->taperSpeed, $plan->defaultProduct, $plan->startDate, $plan->rulesVersion, LocalDate::fromString('2019-03-31')));
        for ($day = 1; $day <= 14; $day++) {
            $date = sprintf('2026-11-%02d', $day);
            $day === 10 ? $this->givenDay($this->anna, $date) : $this->givenChecked($this->anna, $date);
        }

        $this->finalizeDays()->execute();

        // From the end of the 10th: the short ones once more would be stored then, but they are stored already.
        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));
    }

    public function test_FR_GAME_09_an_account_that_is_not_used_reaches_nothing(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        // One session on the first day, and then the app is never opened again.
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 10:00:00');

        // Three weeks later the cron job has made every day final, as a missed day.
        $this->clock->travelTo(TestData::time('2026-12-05 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertTrue($this->dayRecords->find($this->anna->id, self::date('2026-12-03'))?->isFinal());
        self::assertSame([], $this->milestoneRecords->all());
    }

    public function test_FR_FRND_03_a_session_of_a_day_that_is_not_final_changes_nothing(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenChecked($this->anna, '2026-11-14', '2026-11-14 10:00:00');
        // Yesterday, the 16th, is still open: its session is no business of the 14th.
        $this->givenChecked($this->anna, '2026-11-16', '2026-11-16 09:00:00');

        $this->finalizeDays()->execute();

        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));
    }

    public function test_FR_FRND_03_what_was_reached_once_stays_and_keeps_its_first_moment(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenChecked($this->anna, '2026-11-14', '2026-11-14 10:00:00');
        $this->finalizeDays()->execute();
        $before = $this->milestonesOf($this->anna);

        // A session two hours before the end of the 15th: by the end of that day only 20 minutes are reached again.
        $this->givenChecked($this->anna, '2026-11-15', '2026-11-15 21:00:00');
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertCount(2, $before);
        self::assertSame($before, $this->milestonesOf($this->anna));
    }

    public function test_FR_GAME_09_without_a_logged_session_nothing_is_reached(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        // Logged by mistake, and deleted again. The day is checked in.
        $this->givenLog($this->anna, 1, '2026-11-14', '2026-11-14 08:00:00', deletedAt: '2026-11-14 08:05:00');
        $this->givenChecked($this->anna, '2026-11-14');

        $this->finalizeDays()->execute();

        self::assertTrue($this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->isFinal());
        self::assertSame([], $this->milestoneRecords->all());
    }

    public function test_FR_GAME_09_the_sessions_and_days_of_another_user_do_not_count(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenOnboarded($bob, '2026-11-14');
        $this->givenChecked($this->anna, '2026-11-14', '2026-11-14 10:00:00');
        // Bob's session is late, and his day has no check-in.
        $this->givenLog($bob, 900, '2026-11-14', '2026-11-14 22:55:00');

        $this->finalizeDays()->execute();

        self::assertSame(['heart_rate', 'carbon_monoxide'], array_column($this->milestonesOf($this->anna), 0));
        self::assertSame([], $this->milestonesOf($bob));
    }

    public function test_a_day_that_could_not_be_finalized_leaves_no_milestone_behind(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenChecked($this->anna, '2026-11-14', '2026-11-14 10:00:00');
        // The id the first XP event of the run will get is taken: storing it fails, and the step is
        // rolled back as a whole.
        $this->xpLedger->append(new XpEvent(SequentialIdGenerator::id(2), TestData::userId(99), self::date('2026-11-01'), XpSource::Reaction, 2, null, $this->now()));

        $report = $this->finalizeDays()->execute();

        self::assertSame(1, $report->failedUsers);
        self::assertSame([], $this->milestoneRecords->all());
    }

    /**
     * A day of the user with a check-in in the evening and, if a time is given, one session at it.
     */
    private function givenChecked(User $user, string $date, ?string $smokedAt = null): void
    {
        if ($smokedAt !== null) {
            $this->givenLog($user, ++$this->logs, $date, $smokedAt);
        }
        $this->givenDay($user, $date, $smokedAt === null ? 0 : 1, "{$date} 21:00:00");
    }

    /**
     * Anna did the onboarding on the 1st and logged one session that day at 08:00 UTC. Every day up
     * to the 15th is checked in, except the one that is named. The days up to the 13th are final.
     */
    private function givenTwoWeeksFromTheFirst(?string $missed = null): void
    {
        $this->givenOnboarded($this->anna, '2026-11-01', '2026-11-14');
        $this->givenChecked($this->anna, '2026-11-01', '2026-11-01 08:00:00');
        for ($day = 2; $day <= 15; $day++) {
            $date = sprintf('2026-11-%02d', $day);
            if ($date === $missed) {
                $this->givenDay($this->anna, $date);
            } else {
                $this->givenChecked($this->anna, $date);
            }
        }
    }
}
