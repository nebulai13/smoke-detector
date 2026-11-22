<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Logging;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\FinalizeDays;
use SmokeDetector\Application\Day\FinalizeReport;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;

/**
 * Day finalization and the weekly baseline with the real application and database (HLD §4.6): what
 * the cron step `days:finalize` stores, and what the API says afterwards. The rules are tested
 * without I/O in tests/Unit/Application/Day.
 *
 * Users live in Berlin: their days begin at 23:00 UTC.
 */
final class FinalizationTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    public function test_FR_LOG_03_48_hours_after_a_day_ended_it_is_final_and_its_streaks_and_xp_are_stored(): void
    {
        $anna = $this->onboarded();
        $this->sync($anna, [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 3]]);
        $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));

        $this->clock()->travelTo(new DateTimeImmutable('2026-11-19 22:59:59 UTC'));
        $early = $this->finalize();
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-19 23:00:00 UTC'));
        $onTime = $this->finalize();

        self::assertSame([[0, 0, 0, false], [1, 0, 0, false]], [self::report($early), self::report($onTime)]);
        self::assertSame(
            [[
                'user_id' => $anna->id->value, 'local_date' => '2026-11-17', 'count' => 3, 'checked_in_at' => '2026-11-17 12:00:00', 'daily_limit' => 9, 'xp' => 30,
                'events' => '[{"type":"checked_in"},{"type":"under_limit_day","value":1},{"type":"streak_extended","value":1}]',
                'freeze_used' => 0, 'finalized_at' => '2026-11-19 23:00:00', 'rules_version' => 'v1',
            ]],
            $this->sql()->rows('SELECT * FROM day_summaries'),
        );
        self::assertSame(
            [[
                'check_in_current' => 1, 'check_in_longest' => 1, 'under_limit_current' => 1, 'under_limit_longest' => 1, 'freezes_held' => 0,
                'xp_total' => 30, 'level' => 0, 'next_day' => '2026-11-18', 'finalize_due_at' => '2026-11-20 23:00:00',
            ]],
            $this->sql()->rows(
                'SELECT check_in_current, check_in_longest, under_limit_current, under_limit_longest, freezes_held, xp_total, level, next_day, finalize_due_at FROM user_progress',
            ),
        );
        self::assertSame(
            [
                ['local_date' => '2026-11-17', 'source' => 'check_in', 'amount' => 10, 'detail' => null, 'created_at' => '2026-11-19 23:00:00'],
                ['local_date' => '2026-11-17', 'source' => 'under_limit', 'amount' => 20, 'detail' => null, 'created_at' => '2026-11-19 23:00:00'],
            ],
            $this->sql()->rows('SELECT local_date, source, amount, detail, created_at FROM xp_events ORDER BY id'),
        );
        // What is stored is the sum of the ledger.
        self::assertSame(30, $this->sql()->value('SELECT CAST(SUM(amount) AS UNSIGNED) FROM xp_events'));
    }

    public function test_FR_LOG_07_a_final_day_takes_no_log_no_correction_and_no_check_in_any_more(): void
    {
        $anna = $this->onboarded();
        $log = ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 3];
        $this->sync($anna, [$log]);
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-19 23:00:00 UTC'));
        $this->finalize();

        $response = $this->sync($anna, [
            ['id' => TestData::uuid(2), 'smokedAt' => '2026-11-17T09:00:00Z', 'productType' => 'cigarette', 'count' => 1],
            ['count' => 1] + $log,
            ['id' => TestData::uuid(1), 'deleted' => true],
            // The app never got the answer for this one and sends it again as it was.
            $log,
        ]);

        self::assertSame(
            [
                'results' => [
                    ['id' => TestData::uuid(2), 'status' => 'rejected', 'reason' => 'day_finalized'],
                    ['id' => TestData::uuid(1), 'status' => 'rejected', 'reason' => 'day_finalized'],
                    ['id' => TestData::uuid(1), 'status' => 'rejected', 'reason' => 'day_finalized'],
                    ['id' => TestData::uuid(1), 'status' => 'accepted'],
                ],
                // Not checked in: the day counted as missed, and nothing about it changes any more.
                'days' => [['date' => '2026-11-17', 'count' => 3, 'limit' => 9, 'remaining' => 6, 'checkedIn' => false, 'status' => 'final']],
            ],
            $response->json(),
        );
        self::assertSame([[TestData::uuid(1), 3, null]], array_map(array_values(...), $this->sql()->rows('SELECT id, `count`, deleted_at FROM smoke_logs')));
        self::assertProblem(409, 'day_finalized', $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna)));
        self::assertSame([null], $this->sql()->column('SELECT checked_in_at FROM day_summaries WHERE local_date = ?', ['2026-11-17']));
    }

    public function test_FR_GAME_06_after_finalization_days_and_dashboard_show_what_is_stored_and_what_is_still_open(): void
    {
        $anna = $this->onboarded();
        $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));
        // Noon on the 20th: the 17th is final, the 18th and the 19th are over and were not checked in.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-20 12:00:00 UTC'));
        $this->finalize();

        $days = $this->call('GET', '/days?from=2026-11-17&to=2026-11-20', headers: $this->authorization($anna))->json();
        $dashboard = $this->call('GET', '/me/dashboard', headers: $this->authorization($anna))->json();

        self::assertSame(
            ['days' => [
                ['date' => '2026-11-17', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => true, 'status' => 'final'],
                ['date' => '2026-11-18', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => false, 'status' => 'provisional'],
                ['date' => '2026-11-19', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => false, 'status' => 'provisional'],
                ['date' => '2026-11-20', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => false, 'status' => 'provisional'],
            ]],
            $days,
        );
        // Stored: a streak of 1 and 70 XP. The two missed days break the streak in the projection,
        // until they are checked in after all.
        self::assertSame(
            [
                'checkInStreak' => ['current' => 0, 'longest' => 1],
                'underLimitStreak' => ['current' => 0, 'longest' => 1],
                'freezes' => 0,
                'xp' => 70,
                'level' => ['number' => 0, 'xpIntoLevel' => 70, 'xpForNextLevel' => 100],
                'provisional' => true,
            ],
            $dashboard['progress'] ?? null,
        );

        $this->call('POST', '/days/2026-11-18/check-in', headers: $this->authorization($anna));
        $caughtUp = $this->call('POST', '/days/2026-11-19/check-in', headers: $this->authorization($anna))->json();

        self::assertSame(
            ['checkInStreak' => ['current' => 3, 'longest' => 3], 'underLimitStreak' => ['current' => 3, 'longest' => 3], 'xp' => 210],
            array_intersect_key(is_array($caughtUp['progress'] ?? null) ? $caughtUp['progress'] : [], ['checkInStreak' => true, 'underLimitStreak' => true, 'xp' => true]),
        );
    }

    public function test_FR_PLAN_04_on_the_users_monday_the_weekly_baseline_is_stored_and_gives_the_new_limit(): void
    {
        // Onboarding on Monday the 9th. Four days are logged and checked in, with 10, 8, 9 and 7 sessions.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-09 12:00:00 UTC'));
        $anna = $this->onboarded();
        foreach (['2026-11-09' => 10, '2026-11-10' => 8, '2026-11-11' => 9, '2026-11-12' => 7] as $date => $count) {
            $this->clock()->travelTo(new DateTimeImmutable("{$date} 20:00:00 UTC"));
            $this->sync($anna, [['id' => TestData::uuid((int) substr($date, -2)), 'smokedAt' => "{$date}T12:00:00Z", 'productType' => 'cigarette', 'count' => $count]]);
            self::assertSame(200, $this->call('POST', "/days/{$date}/check-in", headers: $this->authorization($anna))->status());
        }

        // Monday the 16th begins in Berlin.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-15 23:00:00 UTC'));
        $report = $this->finalize();

        // The days from the 9th to the 13th are final by now, and the baseline is calculated.
        self::assertSame([5, 1, 0, false], self::report($report));
        self::assertSame(
            [
                ['valid_from' => '2026-11-09', 'value' => '12.0', 'source' => 'onboarding', 'w_mean' => null, 'm_mean' => null, 'w_days' => null, 'm_days' => null],
                // 34 sessions on 4 checked-in days.
                ['valid_from' => '2026-11-16', 'value' => '8.5', 'source' => 'weekly', 'w_mean' => 8.5, 'm_mean' => 8.5, 'w_days' => 4, 'm_days' => 4],
            ],
            $this->sql()->rows('SELECT valid_from, value, source, w_mean, m_mean, w_days, m_days FROM baseline_history ORDER BY valid_from'),
        );
        self::assertSame(['2026-11-22 23:00:00'], $this->sql()->column('SELECT baseline_due_at FROM user_progress'));
        // 80 % of 8.5 is 6.8.
        $plan = $this->call('GET', '/me', headers: $this->authorization($anna))->json()['plan'] ?? null;
        self::assertIsArray($plan);
        self::assertSame([8.5, 6], [$plan['baseline'] ?? null, $plan['dailyLimit'] ?? null]);
        $history = $this->call('GET', '/me/baseline-history', headers: $this->authorization($anna))->json()['entries'] ?? null;
        self::assertIsArray($history);
        self::assertSame(
            ['validFrom' => '2026-11-16', 'value' => 8.5, 'source' => 'weekly', 'weekMean' => 8.5, 'monthMean' => 8.5, 'weekDays' => 4, 'monthDays' => 4, 'rulesVersion' => 'v1'],
            $history[1] ?? null,
        );
    }

    public function test_FR_ACC_08_deleting_the_account_takes_days_progress_and_xp_with_it(): void
    {
        $anna = $this->onboarded();
        $bob = $this->onboarded('bob@example.org');
        foreach ([$anna, $bob] as $number => $user) {
            $this->sync($user, [['id' => TestData::uuid($number + 1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 3]]);
            $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($user));
        }
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-19 23:00:00 UTC'));
        $this->finalize();

        $deleted = $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        self::assertSame(204, $deleted->status());
        foreach (['smoke_logs', 'day_summaries', 'user_progress', 'xp_events', 'plan_history', 'timezone_history'] as $table) {
            // Fixed names from the list above.
            self::assertSame([$bob->id->value], $this->sql()->column("SELECT DISTINCT user_id FROM `{$table}`"), $table);
        }
    }

    private function finalize(): FinalizeReport
    {
        return $this->service(FinalizeDays::class)->execute();
    }

    /**
     * @return array{int, int, int, bool}
     */
    private static function report(FinalizeReport $report): array
    {
        return [$report->days, $report->baselines, $report->failedUsers, $report->outOfTime];
    }

    private function onboarded(string $email = 'anna@example.org'): User
    {
        $user = $this->givenUser($email);
        self::assertSame(200, $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($user))->status());

        return $user;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function sync(User $user, array $items): ApiResponse
    {
        return $this->call('POST', '/logs/sync', ['items' => $items], $this->authorization($user));
    }
}
