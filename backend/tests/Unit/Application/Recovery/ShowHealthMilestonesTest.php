<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Recovery;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanRequired;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Recovery\HealthView;
use SmokeDetector\Application\Recovery\ShowHealthMilestones;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Recovery\HealthMilestoneState;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * `GET /me/health-milestones` (HLD §4.10 "Health milestones"; FR-GAME-09, Appendix C): where the user
 * stands by the time since the last logged session. For the user alone, worked out when asked and
 * stored nowhere.
 *
 * It is noon UTC on Tuesday, 17 November 2026.
 */
final class ShowHealthMilestonesTest extends LoggingUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
        $this->givenOnboarded($this->anna);
    }

    public function test_FR_GAME_09_the_milestones_count_from_the_last_logged_session(): void
    {
        // Stored in another order than they happened: the session of 23:30 last night is the last.
        $this->givenLog($this->anna, 1, '2026-11-17', '2026-11-16 23:30:00');
        $this->givenLog($this->anna, 2, '2026-11-16', '2026-11-16 08:00:00', 4);

        $view = $this->show();

        self::assertEquals(TestData::time('2026-11-16 23:30:00'), $view->since);
        // Twelve and a half hours ago: the first two are reached, and every one says when it is.
        self::assertSame(
            [
                ['heart_rate', '2026-11-16 23:50:00', true],
                ['carbon_monoxide', '2026-11-17 11:30:00', true],
                ['circulation', '2026-11-30 23:30:00', false],
                ['breathing', '2026-12-16 23:30:00', false],
                ['heart_disease_halved', '2027-11-16 23:30:00', false],
                ['stroke', '2031-11-15 23:30:00', false],
                ['lung_cancer_halved', '2036-11-13 23:30:00', false],
                ['heart_disease_gone', '2041-11-12 23:30:00', false],
            ],
            self::milestones($view),
        );
    }

    public function test_FR_GAME_09_a_milestone_is_reached_at_its_moment_by_the_clock(): void
    {
        $this->givenLog($this->anna, 1, '2026-11-17', '2026-11-17 11:45:00');

        self::assertSame([], $this->reachedAt('2026-11-17 12:00:00'));
        self::assertSame([], $this->reachedAt('2026-11-17 12:04:59'));
        self::assertSame(['heart_rate'], $this->reachedAt('2026-11-17 12:05:00'));
    }

    public function test_FR_GAME_09_a_new_session_starts_the_count_again(): void
    {
        $this->givenLog($this->anna, 1, '2026-10-01', '2026-10-01 08:00:00');
        self::assertSame(['heart_rate', 'carbon_monoxide', 'circulation', 'breathing'], self::reached($this->show()));

        $this->givenLog($this->anna, 2, '2026-11-17', '2026-11-17 11:55:00');

        $view = $this->show();
        self::assertEquals(TestData::time('2026-11-17 11:55:00'), $view->since);
        self::assertSame([], self::reached($view));
    }

    public function test_FR_GAME_09_a_deleted_log_is_no_session(): void
    {
        $this->givenLog($this->anna, 1, '2026-10-01', '2026-10-01 08:00:00');
        // Logged by mistake this morning, and deleted again.
        $this->givenLog($this->anna, 2, '2026-11-17', '2026-11-17 09:00:00', deletedAt: '2026-11-17 09:05:00');

        self::assertEquals(TestData::time('2026-10-01 08:00:00'), $this->show()->since);
    }

    public function test_FR_GAME_09_without_a_logged_session_there_is_nothing_to_count_from(): void
    {
        // The app does not know when somebody smoked last who has never logged a session.
        $this->givenLog($this->anna, 1, '2026-11-17', '2026-11-17 09:00:00', deletedAt: '2026-11-17 09:05:00');
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($bob);
        $this->givenLog($bob, 3, '2026-11-17', '2026-11-17 10:00:00');

        $view = $this->show();

        self::assertNull($view->since);
        self::assertSame([], $view->milestones);
    }

    public function test_FR_GAME_09_the_sessions_of_another_user_do_not_count(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenOnboarded($bob);
        $this->givenLog($this->anna, 1, '2026-11-10', '2026-11-10 08:00:00');
        $this->givenLog($bob, 2, '2026-11-17', '2026-11-17 11:59:00');

        self::assertEquals(TestData::time('2026-11-10 08:00:00'), $this->show()->since);
    }

    public function test_FR_GAME_09_somebody_who_logged_only_vaping_is_told_that_the_evidence_differs(): void
    {
        $this->givenLog($this->anna, 1, '2026-11-15', '2026-11-15 08:00:00', productType: ProductType::Vape);
        $this->givenLog($this->anna, 2, '2026-11-16', '2026-11-16 08:00:00', productType: ProductType::Vape);
        self::assertTrue($this->show()->vapingOnly);

        // A cigarette that was logged and deleted again changes nothing.
        $this->givenLog($this->anna, 3, '2026-11-16', '2026-11-16 09:00:00', deletedAt: '2026-11-16 09:05:00');
        self::assertTrue($this->show()->vapingOnly);

        // One session of anything else: the milestones are the ones for smoking.
        $this->givenLog($this->anna, 4, '2026-11-01', '2026-11-01 09:00:00', productType: ProductType::HeatedTobacco);
        self::assertFalse($this->show()->vapingOnly);
    }

    public function test_FR_GAME_09_without_a_log_the_product_of_the_plan_decides(): void
    {
        // Anna's plan names cigarettes.
        self::assertFalse($this->show()->vapingOnly);

        $vaper = $this->givenUser('vaper@example.org');
        $this->givenPlan($vaper, defaultProduct: ProductType::Vape);
        self::assertTrue($this->showHealthMilestones()->of($vaper->id)->vapingOnly);

        // What was logged counts for more than what the plan names.
        $this->givenLog($vaper, 1, '2026-11-16', '2026-11-16 08:00:00', productType: ProductType::Cigarette);
        self::assertFalse($this->showHealthMilestones()->of($vaper->id)->vapingOnly);
    }

    public function test_FR_GAME_09_without_a_logged_session_they_count_from_the_end_of_the_day_of_the_last_session_before_joining(): void
    {
        // Anna quit on the 1st, before she joined on the 10th, and has logged nothing since.
        $this->givenLastSessionBefore('2026-11-01');

        $view = $this->show();

        // The end of the 1st in Vienna. Fifteen and a half days ago.
        self::assertEquals(TestData::time('2026-11-01 23:00:00'), $view->since);
        self::assertSame(['heart_rate', 'carbon_monoxide', 'circulation'], self::reached($view));
    }

    public function test_FR_GAME_09_a_logged_session_counts_for_more_than_the_stated_day(): void
    {
        $this->givenLastSessionBefore('2026-11-01');
        $this->givenLog($this->anna, 1, '2026-11-16', '2026-11-16 20:00:00');

        self::assertEquals(TestData::time('2026-11-16 20:00:00'), $this->show()->since);
    }

    public function test_FR_ACC_06_before_the_onboarding_there_are_no_milestones(): void
    {
        $new = $this->givenUser('new@example.org');

        $this->expectException(PlanRequired::class);

        $this->showHealthMilestones()->of($new->id);
    }

    private function showHealthMilestones(): ShowHealthMilestones
    {
        return new ShowHealthMilestones($this->clock, $this->calendars(), $this->smokeLogs);
    }

    /**
     * Anna's plan as it is, with the day of her last session before she joined.
     */
    private function givenLastSessionBefore(string $day): void
    {
        $plan = $this->plans->find($this->anna->id) ?? self::fail('No plan.');
        $this->plans->save(new Plan($plan->userId, $plan->goal, $plan->taperSpeed, $plan->defaultProduct, $plan->startDate, $plan->rulesVersion, LocalDate::fromString($day)));
    }

    private function show(): HealthView
    {
        return $this->showHealthMilestones()->of($this->anna->id);
    }

    /**
     * @return list<array{string, string, bool}> Key, moment and whether it is reached, of every milestone.
     */
    private static function milestones(HealthView $view): array
    {
        return array_map(
            static fn (HealthMilestoneState $state): array => [$state->milestone->value, $state->reachedAt->format('Y-m-d H:i:s'), $state->reached],
            $view->milestones,
        );
    }

    /**
     * @return list<string> The keys of the milestones Anna has reached at that time.
     */
    private function reachedAt(string $now): array
    {
        $this->clock->travelTo(TestData::time($now));

        return self::reached($this->show());
    }

    /**
     * @return list<string> The keys of the milestones that are reached.
     */
    private static function reached(HealthView $view): array
    {
        return array_column(array_filter(self::milestones($view), static fn (array $milestone): bool => $milestone[2]), 0);
    }
}
