<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Day;

use LogicException;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\Dashboard;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * GET /me/dashboard (HLD §4.6, FR-LOG-05, FR-GAME-02 to -06): today's count against the limit, and
 * the streaks, XP and level as they stand, with the open days projected.
 */
final class ShowDashboardTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_LOG_05_a_user_who_just_did_the_onboarding_sees_today_and_nothing_earned_yet(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        $dashboard = $this->dashboard();

        self::assertSame(['2026-11-17', 0, 9, 9, false, false], self::view($dashboard->today));
        self::assertSame([[0, 0, 0, 0, 0], 0, [0, 0, 100], false], self::progress($dashboard->progress));
    }

    public function test_FR_LOG_05_today_shows_the_count_the_limit_and_what_is_left_but_never_less_than_nothing(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        $this->givenDay($this->anna, '2026-11-17', 4);
        $under = self::view($this->dashboard()->today);
        $this->givenDay($this->anna, '2026-11-17', 9);
        $at = self::view($this->dashboard()->today);
        $this->givenDay($this->anna, '2026-11-17', 12, '2026-11-17 11:00:00');
        $over = self::view($this->dashboard()->today);

        self::assertSame(['2026-11-17', 4, 9, 5, false, false], $under);
        self::assertSame(['2026-11-17', 9, 9, 0, false, false], $at);
        self::assertSame(['2026-11-17', 12, 9, 0, true, false], $over);
    }

    public function test_FR_GAME_05_stored_progress_is_shown_as_it_is_while_no_open_day_changes_it(): void
    {
        // Today is the only open day, and it is neither checked in nor over.
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-17', streaks: [5, 9, 2, 4, 1], totalXp: 450, level: 2);

        $dashboard = $this->dashboard();

        // Level 2 begins at 300 XP and level 3 at 600.
        self::assertSame([[5, 9, 2, 4, 1], 450, [2, 150, 300], false], self::progress($dashboard->progress));
    }

    public function test_FR_GAME_06_a_check_in_of_today_shows_at_once_and_is_marked_as_provisional(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-17', streaks: [5, 9, 2, 4, 1], totalXp: 450, level: 2);
        $this->givenDay($this->anna, '2026-11-17', 3, '2026-11-17 11:00:00');

        $dashboard = $this->dashboard();

        self::assertSame(['2026-11-17', 3, 9, 6, true, false], self::view($dashboard->today));
        self::assertSame([[6, 9, 3, 4, 1], 480, [2, 180, 300], true], self::progress($dashboard->progress));
        // The stored progress is still what finalization left.
        self::assertSame(450, $this->progressRecords->find($this->anna->id)?->progress->totalXp);
    }

    public function test_FR_GAME_02_an_open_day_that_is_over_without_a_check_in_breaks_the_streak_in_the_projection(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-16', streaks: [5, 9, 2, 4, 0], totalXp: 450, level: 2);
        $this->givenDay($this->anna, '2026-11-16', 3);

        self::assertSame([[0, 9, 0, 4, 0], 450, [2, 150, 300], true], self::progress($this->dashboard()->progress));
    }

    public function test_FR_GAME_04_a_freeze_keeps_the_check_in_streak_over_a_missed_open_day_in_the_projection(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-16', streaks: [5, 9, 2, 4, 1], totalXp: 450, level: 2);

        self::assertSame([[5, 9, 0, 4, 0], 450, [2, 150, 300], true], self::progress($this->dashboard()->progress));
    }

    public function test_FR_GAME_06_the_open_days_are_projected_in_calendar_order_each_with_its_own_limit(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');
        // From the 16th on the baseline is 10.0: the limit drops from 9 to 8.
        $this->baselines->append(new BaselineEntry($this->anna->id, self::date('2026-11-16'), Baseline::fromTenths(100), BaselineSource::Weekly, 10.0, 10.0, 6, 6, 'v1'));
        $this->givenDay($this->anna, '2026-11-15', 9, '2026-11-15 21:00:00');
        $this->givenDay($this->anna, '2026-11-16', 9, '2026-11-16 21:00:00');
        $this->givenDay($this->anna, '2026-11-17', 8, '2026-11-17 11:00:00');

        // Under the limit, over it, under it again: 30 + 10 + 30 XP.
        self::assertSame([[3, 3, 1, 1, 0], 70, [0, 70, 100], true], self::progress($this->dashboard()->progress));
    }

    public function test_NFR_04_a_day_that_is_final_is_not_counted_a_second_time(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-17', streaks: [1, 1, 1, 1, 0], totalXp: 30);
        $this->givenDay($this->anna, '2026-11-16', 2, '2026-11-16 21:00:00', TestData::dayResult(9, 30, [new DayEvent(DayEventType::CheckedIn)]));

        self::assertSame([[1, 1, 1, 1, 0], 30, [0, 30, 100], false], self::progress($this->dashboard()->progress));
    }

    public function test_FR_I18N_02_today_has_the_limit_of_the_plan_that_is_valid_today(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-17');
        $this->planHistory->record(new PlanTerms($this->anna->id, self::date('2026-11-18'), Goal::quit(), TaperSpeed::Fast, 'v1'));

        self::assertSame(['2026-11-17', 0, 9, 9, false, false], self::view($this->dashboard()->today));

        // The next day in Vienna: 70 % of 12.
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));

        self::assertSame(['2026-11-18', 0, 8, 8, false, false], self::view($this->dashboard()->today));
    }

    public function test_FR_I18N_02_today_is_the_users_local_day_also_while_a_new_time_zone_waits_for_tomorrow(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->givenZoneChange($this->anna, '2026-11-18', 'Asia/Tokyo');
        $this->givenDay($this->anna, '2026-11-17', 2);

        self::assertSame(['2026-11-17', 2, 9, 7, false, false], self::view($this->dashboard()->today));

        // The 18th begins at midnight in Tokyo, which is 15:00 UTC.
        $this->clock->travelTo(TestData::time('2026-11-17 15:00:00'));

        self::assertSame(['2026-11-18', 0, 9, 9, false, false], self::view($this->dashboard()->today));
    }

    public function test_FR_ACC_06_without_a_plan_there_is_no_dashboard(): void
    {
        $this->expectException(PlanRequired::class);

        $this->dashboard();
    }

    public function test_a_plan_without_progress_is_damaged_data_and_not_a_dashboard_of_zeros(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->progressRecords->eraseOfUser($this->anna->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A plan exists without progress.');

        $this->dashboard();
    }

    public function test_FR_GAME_06_a_day_without_a_row_is_projected_as_a_missed_day_without_a_session(): void
    {
        // The 16th is over and has no row at all. With a freeze held, missing it uses the freeze.
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-16', streaks: [3, 3, 3, 3, 1], totalXp: 90);
        $this->givenDay($this->anna, '2026-11-17', 0, '2026-11-17 11:00:00');

        // The 17th is smoke-free and checked in: 70 XP, and the under-limit streak begins again.
        self::assertSame([[4, 4, 1, 3, 0], 160, [1, 60, 200], true], self::progress($this->dashboard()->progress));
    }

    private function dashboard(): Dashboard
    {
        return $this->showDashboard()->execute($this->anna->id);
    }
}
