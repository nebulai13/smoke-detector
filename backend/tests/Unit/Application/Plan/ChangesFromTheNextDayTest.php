<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Plan;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Account\ProfileChange;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Plan\PlanChoice;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * FR-I18N-02 and HLD §4.6 "Plan and limit", "Local days": the onboarding starts the histories of
 * plan and time zone and the progress of the user. After it, a change of goal, taper speed or time
 * zone counts from the next local day, so that today cannot be changed after the fact.
 */
final class ChangesFromTheNextDayTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_ACC_06_the_onboarding_starts_both_histories_and_the_progress_of_the_user(): void
    {
        $this->savePlan()->execute($this->anna->id, new PlanChoice(12, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Rolled));

        self::assertEquals(
            [new PlanTerms($this->anna->id, self::date('2026-11-17'), Goal::reduceTo(5), TaperSpeed::Gentle, 'v1')],
            $this->planHistory->ofUser($this->anna->id),
        );
        self::assertEquals(
            [new ZoneChange(self::date('2026-11-17'), TimeZone::fromInput('Europe/Vienna'))],
            $this->zoneHistory->ofUser($this->anna->id),
        );
        self::assertEquals(
            new ProgressRecord(
                $this->anna->id,
                UserProgress::initial(),
                0,
                // The first day to become final is the first day of the plan: 48 hours after it ends
                // (midnight in Vienna is 23:00 UTC).
                self::date('2026-11-17'),
                TestData::time('2026-11-19 23:00:00'),
                // The first weekly baseline: the first Monday on which the plan is 7 days old.
                TestData::time('2026-11-29 23:00:00'),
            ),
            $this->progressRecords->find($this->anna->id),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function firstBaselines(): iterable
    {
        yield 'begun on a Monday: the Monday after' => ['2026-11-16 12:00:00', '2026-11-22 23:00:00'];
        yield 'begun on a Tuesday: the Monday after next' => ['2026-11-17 12:00:00', '2026-11-29 23:00:00'];
        yield 'begun on a Sunday: the Monday after next' => ['2026-11-22 12:00:00', '2026-11-29 23:00:00'];
        // 23:30 UTC on Sunday the 22nd is Monday the 23rd in Vienna.
        yield 'begun on the user\'s Monday while it is Sunday in UTC' => ['2026-11-22 23:30:00', '2026-11-29 23:00:00'];
    }

    #[DataProvider('firstBaselines')]
    public function test_FR_PLAN_04_the_first_weekly_baseline_is_due_on_the_first_monday_on_which_the_plan_is_seven_days_old(string $now, string $due): void
    {
        $this->clock->travelTo(TestData::time($now));

        $this->savePlan()->execute($this->anna->id, new PlanChoice(12, Goal::quit(), TaperSpeed::Normal, ProductType::Cigarette));

        self::assertEquals(TestData::time($due), $this->progressRecords->find($this->anna->id)?->baselineDueAt);
    }

    public function test_FR_ACC_06_an_onboarding_that_is_sent_again_starts_nothing_a_second_time(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15', streaks: [4, 4, 2, 2, 0], totalXp: 120, level: 1);
        $before = $this->progressRecords->find($this->anna->id);

        $this->savePlan()->execute($this->anna->id, new PlanChoice(12, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Cigarette));

        self::assertEquals($before, $this->progressRecords->find($this->anna->id));
        self::assertEquals(
            [new PlanTerms($this->anna->id, self::date('2026-11-10'), Goal::reduceTo(5), TaperSpeed::Normal, 'v1')],
            $this->planHistory->ofUser($this->anna->id),
        );
        self::assertCount(1, $this->zoneHistory->ofUser($this->anna->id));
    }

    public function test_FR_I18N_02_a_changed_plan_counts_from_the_next_local_day(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');

        $this->savePlan()->execute($this->anna->id, new PlanChoice(null, Goal::quit(), TaperSpeed::Fast, ProductType::Vape));

        self::assertEquals(
            [
                new PlanTerms($this->anna->id, self::date('2026-11-10'), Goal::reduceTo(5), TaperSpeed::Normal, 'v1'),
                new PlanTerms($this->anna->id, self::date('2026-11-18'), Goal::quit(), TaperSpeed::Fast, 'v1'),
            ],
            $this->planHistory->ofUser($this->anna->id),
        );
        // The account shows what the user chose, the limit of today, and from when the choice counts.
        $plan = $this->showAccount()->execute($this->anna->id)->plan;
        self::assertNotNull($plan);
        self::assertSame([0, TaperSpeed::Fast, ProductType::Vape], [$plan->plan->goal->target, $plan->plan->taperSpeed, $plan->plan->defaultProduct]);
        self::assertSame(9, $plan->dailyLimit);
        self::assertEquals(self::date('2026-11-18'), $plan->pendingFrom);

        // Midnight in Vienna: 70 % of 12.
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));

        $plan = $this->showAccount()->execute($this->anna->id)->plan;
        self::assertNotNull($plan);
        self::assertSame(8, $plan->dailyLimit);
        self::assertNull($plan->pendingFrom);
    }

    public function test_FR_I18N_02_a_second_change_on_the_same_day_replaces_the_first(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');
        $this->savePlan()->execute($this->anna->id, new PlanChoice(null, Goal::quit(), TaperSpeed::Fast, ProductType::Vape));

        $this->savePlan()->execute($this->anna->id, new PlanChoice(null, Goal::reduceTo(3), TaperSpeed::Gentle, ProductType::Vape));

        self::assertEquals(
            [
                new PlanTerms($this->anna->id, self::date('2026-11-10'), Goal::reduceTo(5), TaperSpeed::Normal, 'v1'),
                new PlanTerms($this->anna->id, self::date('2026-11-18'), Goal::reduceTo(3), TaperSpeed::Gentle, 'v1'),
            ],
            $this->planHistory->ofUser($this->anna->id),
        );
    }

    public function test_FR_I18N_02_a_change_on_the_first_day_of_the_plan_leaves_that_day_as_it_began(): void
    {
        $this->savePlan()->execute($this->anna->id, new PlanChoice(12, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Cigarette));

        $this->savePlan()->execute($this->anna->id, new PlanChoice(12, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Cigarette));

        self::assertEquals(
            [
                new PlanTerms($this->anna->id, self::date('2026-11-17'), Goal::reduceTo(5), TaperSpeed::Normal, 'v1'),
                new PlanTerms($this->anna->id, self::date('2026-11-18'), Goal::reduceTo(5), TaperSpeed::Gentle, 'v1'),
            ],
            $this->planHistory->ofUser($this->anna->id),
        );
        self::assertSame(9, $this->showAccount()->execute($this->anna->id)->plan?->dailyLimit);
    }

    public function test_FR_ACC_06_another_usual_product_alone_counts_at_once_and_is_no_entry_of_the_history(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');

        $this->savePlan()->execute($this->anna->id, new PlanChoice(null, Goal::reduceTo(5), TaperSpeed::Normal, ProductType::Pipe));

        self::assertCount(1, $this->planHistory->ofUser($this->anna->id));
        $plan = $this->showAccount()->execute($this->anna->id)->plan;
        self::assertNotNull($plan);
        self::assertSame(ProductType::Pipe, $plan->plan->defaultProduct);
        self::assertNull($plan->pendingFrom);
    }

    public function test_FR_I18N_02_the_next_day_of_a_plan_change_follows_the_zone_that_is_valid_today(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');
        // At 03:00 UTC it is the 17th in Vienna and still the 16th in Los Angeles, the zone that
        // counts from the 18th.
        $this->clock->travelTo(TestData::time('2026-11-17 03:00:00'));
        $this->givenZoneChange($this->anna, '2026-11-18', 'America/Los_Angeles');

        $this->savePlan()->execute($this->anna->id, new PlanChoice(null, Goal::quit(), TaperSpeed::Fast, ProductType::Vape));

        $history = $this->planHistory->ofUser($this->anna->id);
        self::assertSame(['2026-11-10', '2026-11-18'], array_map(static fn (PlanTerms $terms): string => $terms->validFrom->value, $history));
    }

    public function test_FR_ACC_09_before_the_onboarding_a_new_time_zone_simply_counts(): void
    {
        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        self::assertSame('America/New_York', $this->users->find($this->anna->id)?->timeZone->name);
        self::assertSame([], $this->zoneHistory->ofUser($this->anna->id));
        self::assertNull($this->progressRecords->find($this->anna->id));
    }

    public function test_FR_I18N_02_after_the_onboarding_a_new_time_zone_counts_from_the_next_local_day(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17', streaks: [2, 3, 1, 1, 1], totalXp: 90);

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        self::assertSame('America/New_York', $this->users->find($this->anna->id)?->timeZone->name);
        self::assertEquals(
            [
                new ZoneChange(self::date('2026-11-17'), TimeZone::fromInput('Europe/Vienna')),
                new ZoneChange(self::date('2026-11-18'), TimeZone::fromInput('America/New_York')),
            ],
            $this->zoneHistory->ofUser($this->anna->id),
        );
    }

    public function test_FR_I18N_02_a_new_time_zone_moves_the_instants_at_which_the_cron_job_is_due(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17', streaks: [2, 3, 1, 1, 1], totalXp: 90);
        $before = $this->progressRecords->find($this->anna->id);
        self::assertNotNull($before);
        self::assertEquals([TestData::time('2026-11-19 23:00:00'), TestData::time('2026-11-22 23:00:00')], [$before->finalizeDueAt, $before->baselineDueAt]);

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        // The 17th now ends when the 18th begins in New York, at 05:00 UTC, and Monday the 23rd
        // begins at 05:00 UTC as well. Everything else stays as it is.
        self::assertEquals(
            new ProgressRecord($this->anna->id, $before->progress, $before->level, $before->nextDay, TestData::time('2026-11-20 05:00:00'), TestData::time('2026-11-23 05:00:00')),
            $this->progressRecords->find($this->anna->id),
        );
    }

    public function test_FR_I18N_02_a_day_that_ended_before_the_change_keeps_its_due_instant(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        // The 15th ended at midnight in Vienna, whatever comes on the 18th.
        self::assertEquals(TestData::time('2026-11-17 23:00:00'), $this->progressRecords->find($this->anna->id)?->finalizeDueAt);
    }

    public function test_FR_I18N_02_a_second_zone_change_on_the_same_day_replaces_the_first(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('Asia/Tokyo')));

        self::assertEquals(
            [new ZoneChange(self::date('2026-11-17'), TimeZone::fromInput('Europe/Vienna')), new ZoneChange(self::date('2026-11-18'), TimeZone::fromInput('Asia/Tokyo'))],
            $this->zoneHistory->ofUser($this->anna->id),
        );
        $progress = $this->progressRecords->find($this->anna->id);
        // Midnight in Tokyo is 15:00 UTC.
        self::assertEquals([TestData::time('2026-11-19 15:00:00'), TestData::time('2026-11-22 15:00:00')], [$progress?->finalizeDueAt, $progress?->baselineDueAt]);
    }

    public function test_FR_I18N_02_going_back_to_the_zone_of_today_takes_the_change_back(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $before = $this->progressRecords->find($this->anna->id);
        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('Europe/Vienna')));

        self::assertSame('Europe/Vienna', $this->users->find($this->anna->id)?->timeZone->name);
        self::assertEquals($before, $this->progressRecords->find($this->anna->id));
        // The history is as if nothing had been changed: no entry for tomorrow is left over.
        self::assertEquals(
            [new ZoneChange(self::date('2026-11-17'), TimeZone::fromInput('Europe/Vienna'))],
            $this->zoneHistory->ofUser($this->anna->id),
        );
    }

    public function test_FR_I18N_02_a_move_east_past_midnight_counts_from_the_first_day_that_has_not_begun_there(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->givenDay($this->anna, '2026-11-17', 4);
        // 23:30 on the 17th in Vienna is 07:30 on the 18th in Tokyo. If Tokyo counted from the 18th,
        // that day would have begun hours ago, and today would turn into tomorrow by a profile change.
        $this->clock->travelTo(TestData::time('2026-11-17 22:30:00'));

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('Asia/Tokyo')));

        self::assertEquals(
            [new ZoneChange(self::date('2026-11-17'), TimeZone::fromInput('Europe/Vienna')), new ZoneChange(self::date('2026-11-19'), TimeZone::fromInput('Asia/Tokyo'))],
            $this->zoneHistory->ofUser($this->anna->id),
        );
        // Today is still the 17th, with everything that was logged on it.
        self::assertSame(['2026-11-17', 4, 9, 5, false, false], self::view($this->showDashboard()->execute($this->anna->id)->today));
        // The 17th ends at midnight in Vienna as before; the 18th is a short day that ends when
        // the 19th begins in Tokyo (15:00 UTC), and Monday the 23rd begins at 15:00 UTC the day before.
        $progress = $this->progressRecords->find($this->anna->id);
        self::assertEquals([TestData::time('2026-11-19 23:00:00'), TestData::time('2026-11-22 15:00:00')], [$progress?->finalizeDueAt, $progress?->baselineDueAt]);
        $days = new LocalDays($this->zoneHistory->ofUser($this->anna->id));
        self::assertEquals(
            [TestData::time('2026-11-17 23:00:00'), TestData::time('2026-11-18 15:00:00')],
            [$days->startOf(self::date('2026-11-18')), $days->endOf(self::date('2026-11-18'))],
        );
    }

    public function test_FR_I18N_02_a_new_zone_never_lets_a_day_begin_in_the_past(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');
        $zones = ['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Asia/Tokyo', 'America/Los_Angeles', 'Pacific/Auckland', 'Europe/Vienna', 'Pacific/Honolulu', 'Asia/Kolkata'];

        // A change every five hours, around the world and back, for four days.
        for ($hour = 0; $hour < 96; $hour += 5) {
            $now = TestData::time('2026-11-17 00:00:00')->modify("+{$hour} hours");
            $this->clock->travelTo($now);
            $days = new LocalDays($this->zoneHistory->ofUser($this->anna->id));
            $today = $days->dateOf($now);

            $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput($zones[intdiv($hour, 5) % count($zones)])));

            $after = new LocalDays($this->zoneHistory->ofUser($this->anna->id));
            self::assertEquals($today, $after->dateOf($now), "The change at hour {$hour} changed what today is.");
            self::assertEquals($days->startOf($today), $after->startOf($today), "The change at hour {$hour} moved the start of today.");
            self::assertGreaterThan($now, $after->endOf($today), "After the change at hour {$hour} today has ended already.");
        }
    }

    public function test_FR_I18N_02_a_new_zone_replaces_the_changes_that_were_waiting_from_its_own_first_day_on(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->clock->travelTo(TestData::time('2026-11-17 22:30:00'));
        // West first: Los Angeles counts from the 18th. Then east past midnight: Tokyo counts from the 19th.
        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/Los_Angeles')));
        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('Asia/Tokyo')));

        // The entry for the 18th stays: it decides when today ends, and that must not move into the past.
        self::assertEquals(
            [
                new ZoneChange(self::date('2026-11-17'), TimeZone::fromInput('Europe/Vienna')),
                new ZoneChange(self::date('2026-11-18'), TimeZone::fromInput('America/Los_Angeles')),
                new ZoneChange(self::date('2026-11-19'), TimeZone::fromInput('Asia/Tokyo')),
            ],
            $this->zoneHistory->ofUser($this->anna->id),
        );

        // New York counts from the 18th: both waiting changes are replaced by it.
        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        self::assertEquals(
            [new ZoneChange(self::date('2026-11-17'), TimeZone::fromInput('Europe/Vienna')), new ZoneChange(self::date('2026-11-18'), TimeZone::fromInput('America/New_York'))],
            $this->zoneHistory->ofUser($this->anna->id),
        );
    }

    public function test_a_plan_without_progress_is_damaged_data_and_stops_the_change_of_zone(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $this->progressRecords->eraseOfUser($this->anna->id);

        try {
            $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('Asia/Tokyo')));
            self::fail('Expected the damaged data to stop the request.');
        } catch (LogicException $damaged) {
            self::assertSame('A plan exists without progress.', $damaged->getMessage());
        }

        // Nothing of the change is stored.
        self::assertSame('Europe/Vienna', $this->users->find($this->anna->id)?->timeZone->name);
        self::assertCount(1, $this->zoneHistory->ofUser($this->anna->id));
    }

    public function test_FR_ACC_09_the_zone_the_account_already_has_is_no_change(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $before = $this->progressRecords->find($this->anna->id);

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('Europe/Vienna')));

        self::assertCount(1, $this->zoneHistory->ofUser($this->anna->id));
        self::assertEquals($before, $this->progressRecords->find($this->anna->id));
    }

    public function test_FR_ACC_09_a_change_of_the_profile_without_the_zone_leaves_history_and_progress_alone(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');
        $before = $this->progressRecords->find($this->anna->id);
        $saves = 0;
        $this->progressRecords->afterSave = static function () use (&$saves): void {
            $saves++;
        };

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(locale: \SmokeDetector\Application\Account\Locale::En));

        self::assertCount(1, $this->zoneHistory->ofUser($this->anna->id));
        self::assertEquals($before, $this->progressRecords->find($this->anna->id));
        self::assertSame(0, $saves);
    }

    public function test_FR_I18N_02_the_next_day_of_a_zone_change_follows_the_zone_that_is_valid_today(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-10', '2026-11-15');
        // 23:30 UTC: the 18th has begun in Vienna. In New York it is still the 17th.
        $this->clock->travelTo(TestData::time('2026-11-17 23:30:00'));

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('America/New_York')));

        self::assertEquals(
            [new ZoneChange(self::date('2026-11-10'), TimeZone::fromInput('Europe/Vienna')), new ZoneChange(self::date('2026-11-19'), TimeZone::fromInput('America/New_York'))],
            $this->zoneHistory->ofUser($this->anna->id),
        );
    }

    public function test_FR_ACC_09_profile_and_history_change_in_one_transaction(): void
    {
        $this->givenOnboarded($this->anna, '2026-11-17');

        $this->updateProfile()->execute($this->anna->id, new ProfileChange(timeZone: TimeZone::fromInput('Asia/Tokyo')));

        self::assertSame(1, $this->transactions->commits);
        self::assertSame('users', $this->transactions->locks[0] ?? null);
    }
}
