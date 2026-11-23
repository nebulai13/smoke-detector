<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Logging;

use BackedEnum;
use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Day\ShowDays;
use SmokeDetector\Application\Logging\Rejection;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Application\Logging\SyncLogs;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Yaml\Yaml;

/**
 * Logging, check-in, days and dashboard through the real application and database (HLD §4.6).
 * The rules themselves are tested without I/O in tests/Unit/Application/Logging and …/Day.
 *
 * It is noon UTC on Tuesday, 17 November 2026, and users live in Berlin, one hour ahead. A user who
 * does the onboarding here starts on the 17th with a baseline of 12 and a limit of 9.
 */
final class LoggingTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    private const array NOTHING_YET = [
        'checkInStreak' => ['current' => 0, 'longest' => 0],
        'underLimitStreak' => ['current' => 0, 'longest' => 0],
        'freezes' => 0,
        'xp' => 0,
        'level' => ['number' => 0, 'xpIntoLevel' => 0, 'xpForNextLevel' => 100],
        'provisional' => false,
    ];

    public function test_FR_ACC_06_the_onboarding_starts_the_histories_and_the_progress_of_the_user(): void
    {
        $anna = $this->onboarded();

        self::assertSame(
            [['user_id' => $anna->id->value, 'valid_from' => '2026-11-17', 'goal_mode' => 'reduce', 'target' => 5, 'taper_speed' => 'normal', 'rules_version' => 'v1']],
            $this->sql()->rows('SELECT * FROM plan_history'),
        );
        self::assertSame(
            [['user_id' => $anna->id->value, 'valid_from' => '2026-11-17', 'timezone' => 'Europe/Berlin']],
            $this->sql()->rows('SELECT * FROM timezone_history'),
        );
        self::assertSame(
            [[
                'user_id' => $anna->id->value, 'check_in_current' => 0, 'check_in_longest' => 0, 'under_limit_current' => 0, 'under_limit_longest' => 0,
                'freezes_held' => 0, 'xp_total' => 0, 'level' => 0, 'next_day' => '2026-11-17',
                // 48 hours after the 17th ends in Berlin, and the start of Monday the 30th.
                'finalize_due_at' => '2026-11-19 23:00:00', 'baseline_due_at' => '2026-11-29 23:00:00',
            ]],
            $this->sql()->rows('SELECT * FROM user_progress'),
        );
    }

    public function test_FR_LOG_02_synced_logs_are_stored_and_the_day_comes_back(): void
    {
        $anna = $this->onboarded();

        $response = $this->sync($anna, [
            ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'rolled', 'count' => 2],
            // Half past midnight in Berlin, sent with the offset of the phone and with milliseconds.
            ['id' => TestData::uuid(2), 'smokedAt' => '2026-11-17T00:30:00.250+01:00', 'productType' => 'vape', 'count' => 1],
        ]);

        self::assertSame(200, $response->status());
        self::assertSame(
            [
                'results' => [['id' => TestData::uuid(1), 'status' => 'accepted'], ['id' => TestData::uuid(2), 'status' => 'accepted']],
                'days' => [['date' => '2026-11-17', 'count' => 3, 'limit' => 9, 'remaining' => 6, 'checkedIn' => false, 'status' => 'provisional']],
            ],
            $response->json(),
        );
        self::assertSame(
            [
                [
                    'id' => TestData::uuid(1), 'user_id' => $anna->id->value, 'smoked_at' => '2026-11-17 08:15:00', 'local_date' => '2026-11-17',
                    'product_type' => 'rolled', 'count' => 2, 'created_at' => '2026-11-17 12:00:00', 'updated_at' => '2026-11-17 12:00:00', 'deleted_at' => null,
                ],
                [
                    'id' => TestData::uuid(2), 'user_id' => $anna->id->value, 'smoked_at' => '2026-11-16 23:30:00', 'local_date' => '2026-11-17',
                    'product_type' => 'vape', 'count' => 1, 'created_at' => '2026-11-17 12:00:00', 'updated_at' => '2026-11-17 12:00:00', 'deleted_at' => null,
                ],
            ],
            $this->sql()->rows('SELECT * FROM smoke_logs ORDER BY id'),
        );
        self::assertSame(
            [['local_date' => '2026-11-17', 'count' => 3, 'checked_in_at' => null, 'finalized_at' => null]],
            $this->sql()->rows('SELECT local_date, `count`, checked_in_at, finalized_at FROM day_summaries'),
        );
    }

    public function test_FR_LOG_02_a_batch_that_is_sent_twice_is_stored_once(): void
    {
        $anna = $this->onboarded();
        $items = [
            ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 2],
            ['id' => TestData::uuid(2), 'smokedAt' => '2026-11-17T09:15:00Z', 'productType' => 'cigarette', 'count' => 1],
        ];
        $first = $this->sync($anna, $items);
        $this->clock()->advanceSeconds(90);

        $second = $this->sync($anna, $items);

        self::assertSame($first->json(), $second->json());
        self::assertSame(
            [[TestData::uuid(1), 2, '2026-11-17 12:00:00'], [TestData::uuid(2), 1, '2026-11-17 12:00:00']],
            array_map(array_values(...), $this->sql()->rows('SELECT id, `count`, updated_at FROM smoke_logs ORDER BY id')),
        );
        self::assertSame(3, $this->sql()->value('SELECT `count` FROM day_summaries'));
    }

    public function test_FR_LOG_03_a_log_is_corrected_by_its_id_and_deleted_by_its_id(): void
    {
        $anna = $this->onboarded();
        $this->sync($anna, [
            ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 2],
            ['id' => TestData::uuid(2), 'smokedAt' => '2026-11-17T09:15:00Z', 'productType' => 'cigarette', 'count' => 1],
        ]);
        $this->clock()->advanceSeconds(90);

        $response = $this->sync($anna, [
            ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:20:00Z', 'productType' => 'cigar', 'count' => 5],
            ['id' => TestData::uuid(2), 'deleted' => true],
        ]);

        self::assertSame(
            [
                'results' => [['id' => TestData::uuid(1), 'status' => 'accepted'], ['id' => TestData::uuid(2), 'status' => 'accepted']],
                'days' => [['date' => '2026-11-17', 'count' => 5, 'limit' => 9, 'remaining' => 4, 'checkedIn' => false, 'status' => 'provisional']],
            ],
            $response->json(),
        );
        self::assertSame(
            [
                [TestData::uuid(1), '2026-11-17 08:20:00', 'cigar', 5, '2026-11-17 12:00:00', '2026-11-17 12:01:30', null],
                [TestData::uuid(2), '2026-11-17 09:15:00', 'cigarette', 1, '2026-11-17 12:00:00', '2026-11-17 12:01:30', '2026-11-17 12:01:30'],
            ],
            array_map(array_values(...), $this->sql()->rows('SELECT id, smoked_at, product_type, `count`, created_at, updated_at, deleted_at FROM smoke_logs ORDER BY id')),
        );
    }

    public function test_NFR_03_an_item_that_cannot_be_stored_is_answered_with_the_reason(): void
    {
        $anna = $this->onboarded();
        $bob = $this->onboarded('bob@example.org');
        $this->sync($bob, [['id' => TestData::uuid(9), 'smokedAt' => '2026-11-17T08:00:00Z', 'productType' => 'pipe', 'count' => 4]]);

        $response = $this->sync($anna, [
            ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-16T12:00:00Z', 'productType' => 'cigarette', 'count' => 1],
            ['id' => TestData::uuid(2), 'smokedAt' => '2026-11-17T12:06:00Z', 'productType' => 'cigarette', 'count' => 1],
            ['id' => TestData::uuid(9), 'smokedAt' => '2026-11-17T10:00:00Z', 'productType' => 'cigarette', 'count' => 1],
            ['id' => TestData::uuid(9), 'deleted' => true],
            ['id' => TestData::uuid(3), 'smokedAt' => '2026-11-17T10:00:00Z', 'productType' => 'cigarette', 'count' => 1],
        ]);

        self::assertSame(
            [
                'results' => [
                    ['id' => TestData::uuid(1), 'status' => 'rejected', 'reason' => 'before_start'],
                    ['id' => TestData::uuid(2), 'status' => 'rejected', 'reason' => 'in_future'],
                    ['id' => TestData::uuid(9), 'status' => 'rejected', 'reason' => 'not_yours'],
                    ['id' => TestData::uuid(9), 'status' => 'rejected', 'reason' => 'not_yours'],
                    ['id' => TestData::uuid(3), 'status' => 'accepted'],
                ],
                'days' => [['date' => '2026-11-17', 'count' => 1, 'limit' => 9, 'remaining' => 8, 'checkedIn' => false, 'status' => 'provisional']],
            ],
            $response->json(),
        );
        // The log of Bob is as he stored it.
        self::assertSame(
            [['user_id' => $bob->id->value, 'product_type' => 'pipe', 'count' => 4, 'deleted_at' => null]],
            $this->sql()->rows('SELECT user_id, product_type, `count`, deleted_at FROM smoke_logs WHERE id = ?', [TestData::uuid(9)]),
        );
    }

    public function test_FR_LOG_02_an_id_is_the_same_log_however_the_app_spells_it(): void
    {
        $anna = $this->onboarded();
        $upper = 'A3BB189E-8BF9-4888-9912-ACE4E6543002';

        $first = $this->sync($anna, [['id' => $upper, 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 2]]);
        $second = $this->sync($anna, [['id' => strtolower($upper), 'deleted' => true]]);

        // Each answer names the id as the app sent it, so the app finds the item in its outbox.
        self::assertSame([['id' => $upper, 'status' => 'accepted']], $first->json()['results'] ?? null);
        self::assertSame([['id' => strtolower($upper), 'status' => 'accepted']], $second->json()['results'] ?? null);
        self::assertSame([strtolower($upper)], $this->sql()->column('SELECT id FROM smoke_logs WHERE deleted_at IS NOT NULL'));
    }

    public function test_FR_LOG_02_a_malformed_batch_is_refused_as_a_whole_with_every_error(): void
    {
        $anna = $this->onboarded();
        $log = ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 1];
        $tooMany = array_map(static fn (int $number): array => ['id' => TestData::uuid($number)] + $log, range(1, 201));

        $cases = [
            'no items' => [[], [['items', 'required']]],
            'items is not a list' => [['items' => 'all of them'], [['items', 'wrong_type']]],
            'an empty list' => [['items' => []], [['items', 'too_short']]],
            'more than 200 items' => [['items' => $tooMany], [['items', 'too_long']]],
            'an item that is not an object' => [['items' => [$log, 'a log']], [['items.1', 'wrong_type']]],
            'no id' => [['items' => [array_diff_key($log, ['id' => true])]], [['items.0.id', 'required']]],
            'an id that is no UUID' => [['items' => [['id' => 'log-1'] + $log]], [['items.0.id', 'invalid_format']]],
            'no instant' => [['items' => [array_diff_key($log, ['smokedAt' => true])]], [['items.0.smokedAt', 'required']]],
            'an instant without an offset' => [['items' => [['smokedAt' => '2026-11-17T08:15:00'] + $log]], [['items.0.smokedAt', 'invalid_format']]],
            'a day that does not exist' => [['items' => [['smokedAt' => '2026-02-30T08:15:00Z'] + $log]], [['items.0.smokedAt', 'invalid_format']]],
            'an unknown product' => [['items' => [['productType' => 'cannabis'] + $log]], [['items.0.productType', 'invalid_value']]],
            'a count of nothing' => [['items' => [['count' => 0] + $log]], [['items.0.count', 'out_of_range']]],
            'a count above 50' => [['items' => [['count' => 51] + $log]], [['items.0.count', 'out_of_range']]],
            'a count as text' => [['items' => [['count' => '1'] + $log]], [['items.0.count', 'wrong_type']]],
            'a deletion that is taken back' => [['items' => [['id' => TestData::uuid(1), 'deleted' => false]]], [['items.0.deleted', 'invalid_value']]],
            'a deletion that is a log as well' => [['items' => [['deleted' => true] + $log]], [['items.0.deleted', 'ambiguous']]],
            'an unknown member' => [['items' => [['mood' => 'fine'] + $log]], [['items.0.mood', 'unknown_field']]],
            'errors in two items' => [
                ['items' => [['count' => 99] + $log, $log, ['id' => 'x', 'productType' => 'snus'] + $log]],
                [['items.0.count', 'out_of_range'], ['items.2.id', 'invalid_format'], ['items.2.productType', 'invalid_value']],
            ],
        ];

        // What the contract cannot say (it has no "either a log or a deletion", and no calendar) is
        // refused by the server all the same: these bodies are valid by the contract.
        $validByTheContract = ['no instant', 'a day that does not exist', 'a deletion that is a log as well'];

        foreach ($cases as $case => [$body, $expected]) {
            $response = in_array($case, $validByTheContract, true)
                ? $this->call('POST', '/logs/sync', $body, $this->authorization($anna))
                : $this->callInvalid('POST', '/logs/sync', $body, $this->authorization($anna));
            $problem = self::assertProblem(422, 'validation_failed', $response);
            self::assertSame(
                $expected,
                array_map(static fn (array $error): array => [$error['field'], $error['code']], self::fieldCodes($problem)),
                $case,
            );
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM smoke_logs'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM day_summaries'));
    }

    public function test_FR_ACC_06_before_the_onboarding_logging_check_in_days_and_dashboard_answer_plan_required(): void
    {
        $anna = $this->givenUser();
        $log = ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 1];

        self::assertProblem(409, 'plan_required', $this->sync($anna, [$log]));
        self::assertProblem(409, 'plan_required', $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna)));
        self::assertProblem(409, 'plan_required', $this->call('GET', '/days?from=2026-11-17&to=2026-11-17', headers: $this->authorization($anna)));
        self::assertProblem(409, 'plan_required', $this->call('GET', '/me/dashboard', headers: $this->authorization($anna)));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM smoke_logs'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM day_summaries'));
    }

    public function test_FR_LOG_04_a_check_in_is_stored_and_answered_with_the_celebration_and_the_projected_progress(): void
    {
        $anna = $this->onboarded();

        $response = $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame(
            [
                'day' => ['date' => '2026-11-17', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => true, 'status' => 'provisional'],
                'events' => [
                    ['type' => 'checked_in'],
                    ['type' => 'under_limit_day', 'value' => 1],
                    ['type' => 'smoke_free_day'],
                    ['type' => 'streak_extended', 'value' => 1],
                ],
                'progress' => [
                    'checkInStreak' => ['current' => 1, 'longest' => 1],
                    'underLimitStreak' => ['current' => 1, 'longest' => 1],
                    'freezes' => 0,
                    'xp' => 70,
                    'level' => ['number' => 0, 'xpIntoLevel' => 70, 'xpForNextLevel' => 100],
                    'provisional' => true,
                ],
            ],
            $response->json(),
        );
        self::assertSame(
            [['local_date' => '2026-11-17', 'count' => 0, 'checked_in_at' => '2026-11-17 12:00:00', 'finalized_at' => null, 'xp' => null]],
            $this->sql()->rows('SELECT local_date, `count`, checked_in_at, finalized_at, xp FROM day_summaries'),
        );
        // Nothing is earned for good before the day is final.
        self::assertSame([['xp_total' => 0, 'check_in_current' => 0]], $this->sql()->rows('SELECT xp_total, check_in_current FROM user_progress'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM xp_events'));
    }

    public function test_FR_LOG_04_a_second_check_in_gets_the_same_answer_and_keeps_the_time_of_the_first(): void
    {
        $anna = $this->onboarded();
        $first = $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));
        $this->clock()->advanceSeconds(600);

        $second = $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));

        self::assertSame($first->json(), $second->json());
        self::assertSame(['2026-11-17 12:00:00'], $this->sql()->column('SELECT checked_in_at FROM day_summaries'));
    }

    public function test_FR_LOG_04_a_checked_in_day_can_still_be_corrected_and_the_projection_follows(): void
    {
        $anna = $this->onboarded();
        $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));

        $synced = $this->sync($anna, [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 12]]);

        self::assertSame(
            [['date' => '2026-11-17', 'count' => 12, 'limit' => 9, 'remaining' => 0, 'checkedIn' => true, 'status' => 'provisional']],
            $synced->json()['days'] ?? null,
        );
        $dashboard = $this->call('GET', '/me/dashboard', headers: $this->authorization($anna))->json();
        // Over the limit now: the 10 XP of the check-in are left of the 70.
        self::assertSame(
            ['checkInStreak' => ['current' => 1, 'longest' => 1], 'underLimitStreak' => ['current' => 0, 'longest' => 0], 'xp' => 10, 'provisional' => true],
            array_intersect_key(self::member($dashboard, 'progress'), ['checkInStreak' => true, 'underLimitStreak' => true, 'xp' => true, 'provisional' => true]),
        );
    }

    public function test_FR_LOG_04_a_day_that_is_not_open_cannot_be_checked_in(): void
    {
        $anna = $this->onboarded();

        self::assertProblem(409, 'day_in_future', $this->call('POST', '/days/2026-11-18/check-in', headers: $this->authorization($anna)));
        self::assertProblem(409, 'day_before_start', $this->call('POST', '/days/2026-11-16/check-in', headers: $this->authorization($anna)));
        foreach (['2026-02-30', 'today', '2026-11-7', '17.11.2026'] as $date) {
            $problem = self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', "/days/{$date}/check-in", headers: $this->authorization($anna)));
            self::assertSame([['field' => 'date', 'code' => 'invalid_format']], self::fieldCodes($problem), $date);
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM day_summaries'));
    }

    public function test_FR_LOG_05_the_dashboard_shows_today_against_the_limit_and_the_progress(): void
    {
        $anna = $this->onboarded();

        $fresh = $this->call('GET', '/me/dashboard', headers: $this->authorization($anna));

        self::assertSame(200, $fresh->status());
        self::assertSame(
            ['today' => ['date' => '2026-11-17', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => false, 'status' => 'provisional'], 'progress' => self::NOTHING_YET],
            $fresh->json(),
        );

        $this->sync($anna, [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 4]]);

        self::assertSame(
            ['today' => ['date' => '2026-11-17', 'count' => 4, 'limit' => 9, 'remaining' => 5, 'checkedIn' => false, 'status' => 'provisional'], 'progress' => self::NOTHING_YET],
            $this->call('GET', '/me/dashboard', headers: $this->authorization($anna))->json(),
        );
    }

    public function test_FR_LOG_05_the_days_of_a_range_are_listed_from_the_first_day_of_the_plan_to_today(): void
    {
        $anna = $this->onboarded();
        $this->sync($anna, [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 4]]);
        $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));

        $today = $this->call('GET', '/days?from=2026-11-10&to=2026-11-30', headers: $this->authorization($anna));

        self::assertSame(200, $today->status());
        self::assertSame(
            ['days' => [['date' => '2026-11-17', 'count' => 4, 'limit' => 9, 'remaining' => 5, 'checkedIn' => true, 'status' => 'provisional']]],
            $today->json(),
        );

        // Half past midnight in Berlin.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 23:30:00 UTC'));

        self::assertSame(
            ['days' => [
                ['date' => '2026-11-17', 'count' => 4, 'limit' => 9, 'remaining' => 5, 'checkedIn' => true, 'status' => 'provisional'],
                ['date' => '2026-11-18', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => false, 'status' => 'provisional'],
            ]],
            $this->call('GET', '/days?from=2026-11-10&to=2026-11-30', headers: $this->authorization($anna))->json(),
        );
    }

    public function test_the_range_of_days_needs_two_dates_in_order_and_at_most_92_days(): void
    {
        $anna = $this->onboarded();
        $cases = [
            '/days' => [['from', 'required'], ['to', 'required']],
            '/days?from=2026-11-17' => [['to', 'required']],
            '/days?from=17.11.2026&to=2026-02-30' => [['from', 'invalid_format'], ['to', 'invalid_format']],
            '/days?from=2026-11-17&to=2026-11-16' => [['to', 'out_of_range']],
            '/days?from=2026-11-01&to=2027-02-01' => [['to', 'out_of_range']],
        ];

        foreach ($cases as $path => $expected) {
            // The order of two dates and the length of the range are nothing the contract can say.
            $response = $expected === [['to', 'out_of_range']]
                ? $this->call('GET', $path, headers: $this->authorization($anna))
                : $this->callInvalid('GET', $path, headers: $this->authorization($anna));
            $problem = self::assertProblem(422, 'validation_failed', $response);
            self::assertSame(
                $expected,
                array_map(static fn (array $error): array => [$error['field'], $error['code']], self::fieldCodes($problem)),
                $path,
            );
        }
        self::assertSame(200, $this->call('GET', '/days?from=2026-11-01&to=2027-01-31', headers: $this->authorization($anna))->status());
    }

    public function test_FR_PLAN_08_the_baseline_history_lists_every_entry_and_is_empty_before_the_onboarding(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $anna = $this->onboarded();

        self::assertSame(
            ['entries' => [[
                'validFrom' => '2026-11-17', 'value' => 12.0, 'source' => 'onboarding', 'weekMean' => null, 'monthMean' => null,
                'weekDays' => null, 'monthDays' => null, 'rulesVersion' => 'v1',
            ]]],
            $this->call('GET', '/me/baseline-history', headers: $this->authorization($anna))->json(),
        );
        self::assertSame(['entries' => []], $this->call('GET', '/me/baseline-history', headers: $this->authorization($bob))->json());
    }

    public function test_FR_I18N_02_a_changed_plan_counts_from_the_next_local_day(): void
    {
        $anna = $this->onboarded();

        $changed = $this->call('PUT', '/me/plan', ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'defaultProduct' => 'vape'], $this->authorization($anna));

        self::assertSame(
            ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'defaultProduct' => 'vape', 'startDate' => '2026-11-17', 'baseline' => 12.0, 'dailyLimit' => 9, 'pendingFrom' => '2026-11-18'],
            self::member($changed->json(), 'plan'),
        );
        self::assertSame(
            [['2026-11-17', 'reduce', 5, 'normal'], ['2026-11-18', 'quit', 0, 'fast']],
            array_map(array_values(...), $this->sql()->rows('SELECT valid_from, goal_mode, target, taper_speed FROM plan_history ORDER BY valid_from')),
        );
        self::assertSame(9, self::member($this->call('GET', '/me/dashboard', headers: $this->authorization($anna))->json(), 'today')['limit'] ?? null);

        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 23:30:00 UTC'));

        // 70 % of 12 from the 18th on, and nothing is pending any more.
        self::assertSame(
            ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'defaultProduct' => 'vape', 'startDate' => '2026-11-17', 'baseline' => 12.0, 'dailyLimit' => 8],
            self::member($this->call('GET', '/me', headers: $this->authorization($anna))->json(), 'plan'),
        );
        self::assertSame(
            ['date' => '2026-11-18', 'count' => 0, 'limit' => 8, 'remaining' => 8, 'checkedIn' => false, 'status' => 'provisional'],
            self::member($this->call('GET', '/me/dashboard', headers: $this->authorization($anna))->json(), 'today'),
        );
    }

    public function test_FR_I18N_02_a_changed_time_zone_counts_from_the_next_local_day(): void
    {
        $anna = $this->onboarded();

        $this->call('PATCH', '/me', ['timeZone' => 'America/New_York'], $this->authorization($anna));

        self::assertSame(
            [['2026-11-17', 'Europe/Berlin'], ['2026-11-18', 'America/New_York']],
            array_map(array_values(...), $this->sql()->rows('SELECT valid_from, timezone FROM timezone_history ORDER BY valid_from')),
        );
        // The 17th now ends when the 18th begins in New York, at 05:00 UTC.
        self::assertSame(
            [['finalize_due_at' => '2026-11-20 05:00:00', 'baseline_due_at' => '2026-11-30 05:00:00']],
            $this->sql()->rows('SELECT finalize_due_at, baseline_due_at FROM user_progress'),
        );

        // 23:30 UTC would be the 18th in Berlin. Anna's 17th is still running, and a log of now counts for it.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 23:30:00 UTC'));
        $synced = $this->sync($anna, [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T23:29:00Z', 'productType' => 'cigarette', 'count' => 1]]);

        $day = self::member($synced->json(), 'days')[0] ?? null;
        self::assertIsArray($day);
        self::assertSame('2026-11-17', $day['date'] ?? null);
        self::assertSame('2026-11-17', self::member($this->call('GET', '/me/dashboard', headers: $this->authorization($anna))->json(), 'today')['date'] ?? null);
    }

    public function test_NFR_05_logs_days_and_progress_belong_to_the_user_of_the_token_alone(): void
    {
        $anna = $this->onboarded();
        $bob = $this->onboarded('bob@example.org');
        $this->sync($anna, [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 7]]);
        $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($anna));

        self::assertSame(
            ['today' => ['date' => '2026-11-17', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => false, 'status' => 'provisional'], 'progress' => self::NOTHING_YET],
            $this->call('GET', '/me/dashboard', headers: $this->authorization($bob))->json(),
        );
        self::assertSame(
            ['days' => [['date' => '2026-11-17', 'count' => 0, 'limit' => 9, 'remaining' => 9, 'checkedIn' => false, 'status' => 'provisional']]],
            $this->call('GET', '/days?from=2026-11-17&to=2026-11-17', headers: $this->authorization($bob))->json(),
        );
    }

    public function test_the_contract_states_the_same_values_and_limits_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);
        $values = static fn (BackedEnum ...$cases): array => array_map(static fn (BackedEnum $case): int|string => $case->value, array_values($cases));

        self::assertSame(SyncLogs::MAX_ITEMS, $schema('LogSyncRequest', 'properties', 'items', 'maxItems'));
        self::assertSame(SyncLogs::MAX_ITEMS, $schema('LogSyncResult', 'properties', 'results', 'maxItems'));
        self::assertSame([1, SmokeLog::MAX_COUNT], [$schema('LogItem', 'properties', 'count', 'minimum'), $schema('LogItem', 'properties', 'count', 'maximum')]);
        self::assertSame(SmokeLog::MAX_COUNT, $schema('ExportedSmokeLog', 'properties', 'count', 'maximum'));
        self::assertSame($values(...Rejection::cases()), $schema('LogItemResult', 'properties', 'reason', 'enum'));
        self::assertSame(ShowDays::MAX_DAYS, $schema('DayList', 'properties', 'days', 'maxItems'));
        self::assertSame($values(...DayEventType::cases()), $schema('DayEventType', 'enum'));
        self::assertSame($values(...XpSource::cases()), $schema('ExportedXpEvent', 'properties', 'source', 'enum'));
    }

    /**
     * A user who has done the onboarding with self::PLAN, on 2026-11-17.
     */
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

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private static function member(array $body, string $name): array
    {
        self::assertIsArray($body[$name] ?? null, "The answer has no member \"{$name}\": " . json_encode($body));

        return $body[$name];
    }

    /**
     * The value at a path of keys, or null.
     *
     * @param array<array-key, mixed> $node
     */
    private static function at(array $node, string ...$keys): mixed
    {
        $value = $node;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
