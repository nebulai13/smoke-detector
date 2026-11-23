<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Badge;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Badge\AwardBadges;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\Fake\CountingBadgeRecords;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * The badges of the days (HLD §4.10 "When a badge is awarded"; FR-GAME-08, Appendix B): awarded in
 * the transaction that makes a day final, and never before. A badge is stored once, with the time
 * of its first award.
 *
 * It is noon UTC on Tuesday, 17 November 2026, and Anna lives in Vienna: her 14th became final at
 * 23:00 UTC on the 16th. With a baseline of 12 and the normal taper speed her limit is 9.
 */
final class DayBadgesTest extends LoggingUseCaseTestCase
{
    private User $anna;
    private CountingBadgeRecords $counted;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
        $this->counted = new CountingBadgeRecords($this->badgeRecords);
    }

    protected function awardBadges(): AwardBadges
    {
        return new AwardBadges($this->counted, $this->duels, $this->publishToFeed());
    }

    public function test_FR_GAME_08_the_first_check_in_is_a_badge_once_its_day_is_final_and_not_before(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenChecked('2026-11-14', 3);

        // A second before the 14th is final.
        $this->clock->travelTo(TestData::time('2026-11-16 22:59:59'));
        $this->finalizeDays()->execute();
        self::assertSame([], $this->badgesOf($this->anna));

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame([['first_check_in', '2026-11-16 23:00:00']], $this->badgesOf($this->anna));
    }

    public function test_FR_GAME_08_a_day_without_a_check_in_earns_no_badge(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        // No session and no check-in: nobody said that the day was smoke-free.
        $this->givenDay($this->anna, '2026-11-14', 0);

        $this->finalizeDays()->execute();

        self::assertNotNull($this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->result);
        self::assertSame([], $this->badgesOf($this->anna));
    }

    public function test_FR_GAME_08_a_checked_in_day_without_a_session_is_the_first_smoke_free_day(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-13');
        $this->givenChecked('2026-11-13', 2);
        $this->givenChecked('2026-11-14', 0);

        $this->clock->travelTo(TestData::time('2026-11-15 23:00:00'));
        $this->finalizeDays()->execute();
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        // The first check-in keeps the time of its first award.
        self::assertSame(
            [['first_check_in', '2026-11-15 23:00:00'], ['first_smoke_free_day', '2026-11-16 23:00:00']],
            $this->badgesOf($this->anna),
        );
    }

    public function test_FR_GAME_08_the_seventh_day_of_a_streak_earns_the_streak_badges(): void
    {
        // Six checked-in days at or under the limit so far. The 14th is the seventh.
        $this->givenOnboarded($this->anna, '2026-11-08', '2026-11-13', streaks: [5, 5, 5, 5, 0]);
        $this->givenChecked('2026-11-13', 9);
        $this->givenChecked('2026-11-14', 9);

        $this->clock->travelTo(TestData::time('2026-11-15 23:00:00'));
        $this->finalizeDays()->execute();
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(
            [['first_check_in', '2026-11-15 23:00:00'], ['check_in_streak_7', '2026-11-16 23:00:00'], ['under_limit_streak_7', '2026-11-16 23:00:00']],
            $this->badgesOf($this->anna),
        );
    }

    public function test_FR_GAME_08_a_day_over_the_limit_keeps_the_check_in_streak_badge_and_not_the_under_limit_one(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-08', '2026-11-14', streaks: [6, 6, 6, 6, 0]);
        $this->givenChecked('2026-11-14', 10);

        $this->finalizeDays()->execute();

        self::assertSame(['first_check_in', 'check_in_streak_7'], $this->badgeKeysOf($this->anna));
    }

    public function test_FR_GAME_08_a_streak_badge_that_was_missed_is_made_up_for_with_the_next_final_day(): void
    {
        // The longest streak is 30 days, from a time before there were badges. Today's day was missed.
        $this->givenOnboarded($this->anna, '2026-10-01', '2026-11-14', streaks: [0, 30, 0, 8, 0]);
        $this->givenDay($this->anna, '2026-11-14', 4);

        $this->finalizeDays()->execute();

        self::assertSame(['check_in_streak_7', 'check_in_streak_30', 'under_limit_streak_7'], $this->badgeKeysOf($this->anna));
    }

    public function test_FR_GAME_08_limit_halved_is_a_kept_day_whose_limit_is_half_the_limit_of_the_first_day_or_less(): void
    {
        // Quit: the limit follows the baseline all the way down. First limit: 9.
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-12', goal: Goal::quit());
        // 80 % of 6.3 is 5.04: a limit of 5, and twice 5 is more than 9.
        $this->givenWeeklyBaseline($this->anna, '2026-11-12', 63);
        // 80 % of 5.0: a limit of 4.
        $this->givenWeeklyBaseline($this->anna, '2026-11-13', 50);
        $this->givenChecked('2026-11-12', 4);
        $this->givenChecked('2026-11-13', 5);
        $this->givenChecked('2026-11-14', 4);

        // The 12th: 4 sessions are under half of 9, but the limit of the day is 5.
        $this->clock->travelTo(TestData::time('2026-11-14 23:00:00'));
        $this->finalizeDays()->execute();
        // The 13th: the limit is 4, but the day was not kept.
        $this->clock->travelTo(TestData::time('2026-11-15 23:00:00'));
        $this->finalizeDays()->execute();
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));
        self::assertSame([5, 4], [$this->limitOf('2026-11-12'), $this->limitOf('2026-11-13')]);

        // The 14th: a limit of 4, and kept.
        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(
            [['first_check_in', '2026-11-14 23:00:00'], ['limit_halved', '2026-11-16 23:00:00']],
            $this->badgesOf($this->anna),
        );
    }

    public function test_FR_GAME_08_the_first_limit_is_the_limit_of_the_first_day_of_the_plan_whatever_came_later(): void
    {
        // The plan began with a baseline of 12 (limit 9). Since the 12th the baseline is 20 (limit 16),
        // and on the 14th it is 10 (limit 8): half of 16, but not half of 9.
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-14', goal: Goal::quit());
        $this->givenWeeklyBaseline($this->anna, '2026-11-12', 200);
        $this->givenWeeklyBaseline($this->anna, '2026-11-14', 100);
        $this->givenChecked('2026-11-14', 8);

        $this->finalizeDays()->execute();

        self::assertSame(8, $this->limitOf('2026-11-14'));
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));
    }

    public function test_FR_GAME_08_target_reached_is_a_kept_day_whose_limit_has_arrived_at_the_target(): void
    {
        // Reduce to 5. 80 % of 6.3 is 5.04, a limit of 5: the target. 80 % of 7.5 is a limit of 6.
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-12');
        $this->givenWeeklyBaseline($this->anna, '2026-11-12', 75);
        $this->givenWeeklyBaseline($this->anna, '2026-11-13', 63);
        $this->givenChecked('2026-11-12', 5);
        $this->givenChecked('2026-11-13', 6);
        $this->givenChecked('2026-11-14', 5);

        // The 12th: 5 sessions, but the limit is still 6. The 13th: the limit is 5, but the day was not kept.
        $this->clock->travelTo(TestData::time('2026-11-15 23:00:00'));
        $this->finalizeDays()->execute();
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));
        self::assertSame([6, 5], [$this->limitOf('2026-11-12'), $this->limitOf('2026-11-13')]);

        $this->clock->travelTo(TestData::time('2026-11-16 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(
            [['first_check_in', '2026-11-15 23:00:00'], ['target_reached', '2026-11-16 23:00:00']],
            $this->badgesOf($this->anna),
        );
    }

    public function test_FR_GAME_08_a_plan_that_begins_at_its_target_has_not_reached_it(): void
    {
        // A baseline of 6, to reduce to 5: 80 % of 6 is 4, so the limit is the target from the first day.
        $this->givenOnboarded($this->anna, '2026-11-14', startingBaseline: 6);
        $this->givenChecked('2026-11-14', 5);

        $this->finalizeDays()->execute();

        self::assertSame(5, $this->limitOf('2026-11-14'));
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));
    }

    public function test_FR_GAME_08_a_target_that_is_set_above_the_limit_is_not_reached_by_setting_it(): void
    {
        // The limit is 9. The user changes the target to 10, valid from the 14th: the limit goes up to it.
        $plan = $this->givenPlan($this->anna, 12, Goal::reduceTo(5), startDate: '2026-11-10');
        $this->givenProgress($this->anna, '2026-11-14');
        $this->plans->save(new Plan($this->anna->id, Goal::reduceTo(10), TaperSpeed::Normal, ProductType::Cigarette, $plan->startDate, 'v1'));
        $this->planHistory->record(new PlanTerms($this->anna->id, self::date('2026-11-14'), Goal::reduceTo(10), TaperSpeed::Normal, 'v1'));
        $this->givenChecked('2026-11-14', 10);

        $this->finalizeDays()->execute();

        self::assertSame(10, $this->limitOf('2026-11-14'));
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));
    }

    public function test_FR_GAME_08_the_target_is_the_one_that_was_valid_on_the_day(): void
    {
        // Reduce to 5 until the 14th. The user has chosen 3 since, valid from the 15th.
        $plan = $this->givenPlan($this->anna, 12, Goal::reduceTo(5), startDate: '2026-11-10');
        $this->givenProgress($this->anna, '2026-11-14');
        $this->plans->save(new Plan($this->anna->id, Goal::reduceTo(3), TaperSpeed::Normal, ProductType::Cigarette, $plan->startDate, 'v1'));
        $this->planHistory->record(new PlanTerms($this->anna->id, self::date('2026-11-15'), Goal::reduceTo(3), TaperSpeed::Normal, 'v1'));
        // 80 % of 5.0 is 4: the limit stops at the target of the day, 5 on the 14th and 4 on the 15th.
        $this->givenWeeklyBaseline($this->anna, '2026-11-14', 50);
        $this->givenChecked('2026-11-14', 5);
        $this->givenChecked('2026-11-15', 4);

        $this->finalizeDays()->execute();
        self::assertSame(['first_check_in', 'target_reached'], $this->badgeKeysOf($this->anna));
    }

    public function test_FR_GAME_08_a_limit_above_the_new_target_is_not_the_target(): void
    {
        // The same change of plan, seen from the day after. 80 % of 6.3 is a limit of 5: the target of
        // the days before, but on the 15th the target is 3.
        $plan = $this->givenPlan($this->anna, 12, Goal::reduceTo(5), startDate: '2026-11-10');
        $this->givenProgress($this->anna, '2026-11-15');
        $this->plans->save(new Plan($this->anna->id, Goal::reduceTo(3), TaperSpeed::Normal, ProductType::Cigarette, $plan->startDate, 'v1'));
        $this->planHistory->record(new PlanTerms($this->anna->id, self::date('2026-11-15'), Goal::reduceTo(3), TaperSpeed::Normal, 'v1'));
        $this->givenWeeklyBaseline($this->anna, '2026-11-14', 63);
        $this->givenChecked('2026-11-15', 5);

        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->finalizeDays()->execute();

        self::assertSame(5, $this->limitOf('2026-11-15'));
        self::assertSame(['first_check_in'], $this->badgeKeysOf($this->anna));
    }

    public function test_NFR_04_a_second_run_awards_nothing_again(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenChecked('2026-11-14', 0);
        $this->finalizeDays()->execute();
        $before = $this->badgeRecords->all();

        $this->clock->travelTo(TestData::time('2026-11-17 12:05:00'));
        $this->finalizeDays()->execute();

        self::assertCount(2, $before);
        self::assertEquals($before, $this->badgeRecords->all());
    }

    public function test_NFR_01_the_badges_are_read_once_and_only_a_new_one_is_written(): void
    {
        // The cron job makes a day final for every user every day: most of them earn nothing new.
        $this->givenOnboarded($this->anna, '2026-11-08', '2026-11-14', streaks: [6, 6, 6, 6, 0]);
        $this->badgeRecords->add(new AwardedBadge($this->anna->id, Badge::FirstCheckIn, TestData::time('2026-11-10 23:00:00')));
        $this->badgeRecords->add(new AwardedBadge($this->anna->id, Badge::UnderLimitStreak7, TestData::time('2026-11-10 23:00:00')));
        $this->givenChecked('2026-11-14', 9);

        $this->finalizeDays()->execute();

        // First check-in and the under-limit streak of 7 are held. The check-in streak of 7 is new.
        self::assertSame(['first_check_in', 'check_in_streak_7', 'under_limit_streak_7'], $this->badgeKeysOf($this->anna));
        self::assertSame([1, 1], [$this->counted->reads, $this->counted->writes]);
    }

    public function test_NFR_01_a_day_that_justifies_nothing_does_not_even_read_the_badges(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenDay($this->anna, '2026-11-14', 3);

        $this->finalizeDays()->execute();

        self::assertNotNull($this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->result);
        self::assertSame([0, 0], [$this->counted->reads, $this->counted->writes]);
    }

    public function test_a_day_that_could_not_be_finalized_leaves_no_badge_behind(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-14');
        $this->givenChecked('2026-11-14', 0);
        // The id the first XP event of the run will get is taken: storing it fails, and the step is
        // rolled back as a whole.
        $this->xpLedger->append(new XpEvent(SequentialIdGenerator::id(2), TestData::userId(99), self::date('2026-11-01'), XpSource::Reaction, 2, null, $this->now()));

        $report = $this->finalizeDays()->execute();

        self::assertSame(1, $report->failedUsers);
        self::assertFalse($this->dayRecords->find($this->anna->id, self::date('2026-11-14'))?->isFinal());
        self::assertSame([], $this->badgeRecords->all());
    }

    /**
     * A day of Anna with a log of that many sessions (none for 0) and a check-in in the evening.
     */
    private function givenChecked(string $date, int $sessions): void
    {
        if ($sessions > 0) {
            $this->givenLog($this->anna, (int) substr($date, -2) + 100, $date, "{$date} 10:00:00", $sessions);
        }
        $this->givenDay($this->anna, $date, $sessions, "{$date} 21:00:00");
    }

    private function limitOf(string $date): ?int
    {
        return $this->dayRecords->find($this->anna->id, self::date($date))?->result?->dailyLimit;
    }
}
