<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\DuelStanding;
use SmokeDetector\Application\Competition\ShowLeague;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Console\Console;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Yaml\Yaml;

/**
 * Leagues and the weekly XP ranking through the real application and database (HLD §4.9). The rules
 * themselves are tested without I/O in tests/Unit/Application/Competition.
 *
 * It is noon UTC on Tuesday, 17 November 2026, in the week 2026-W47 (Monday the 16th to Sunday the
 * 22nd), and users live in Berlin. Three friends do the onboarding today with a baseline of 12 and a
 * limit of 9. The week is final on Tuesday the 24th at 23:00 UTC.
 */
final class LeaguesTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    private int $logs = 0;

    public function test_FR_COMP_06_a_running_week_shows_the_own_figures_live_and_of_friends_the_final_days_and_who_is_ahead(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        // Tuesday: Anna 6, Bob nothing, both checked in. Carol logs 4 and never checks in.
        $this->day('2026-11-17', [[$anna, 6, true], [$bob, 0, true], [$carol, 4, false]]);
        $this->day('2026-11-18', [[$anna, 6, true], [$bob, 0, true], [$carol, 12, true]]);
        $this->day('2026-11-19', [[$anna, 6, true], [$bob, 0, true], [$carol, 12, true]]);

        // Before any day is final: her own figures, and nothing about the others.
        $early = $this->call('GET', '/leagues?type=week&period=current', headers: $this->authorization($anna))->json();
        self::assertSame(
            [
                ['rank' => null, 'standing' => null, 'user' => self::publicUser($anna, 'Anna'), 'you' => true, 'reductionPercent' => 50, 'smokeFreeDays' => 0, 'checkIns' => 3, 'countedDays' => 3],
                ['rank' => null, 'standing' => null, 'user' => self::publicUser($bob, 'Bob'), 'you' => false, 'reductionPercent' => null, 'smokeFreeDays' => null, 'checkIns' => null, 'countedDays' => null],
                ['rank' => null, 'standing' => null, 'user' => self::publicUser($carol, 'Carol'), 'you' => false, 'reductionPercent' => null, 'smokeFreeDays' => null, 'checkIns' => null, 'countedDays' => null],
            ],
            $early['entries'] ?? null,
        );

        // In the night to Friday the cron job makes Tuesday final.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-19 23:00:00 UTC'));
        $this->cronRun();
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-20 12:00:00 UTC'));
        $response = $this->call('GET', '/leagues?type=week&period=current', headers: $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame(
            [
                'type' => 'week', 'period' => '2026-W47', 'startsOn' => '2026-11-16', 'endsOn' => '2026-11-22', 'final' => false,
                'entries' => [
                    // Of a friend: the final days (here Tuesday), and ahead or behind over the final days
                    // of both. No rank and no percentage.
                    ['rank' => null, 'standing' => 'ahead', 'user' => self::publicUser($bob, 'Bob'), 'you' => false, 'reductionPercent' => null, 'smokeFreeDays' => 1, 'checkIns' => 1, 'countedDays' => 1],
                    // Her own three days, live.
                    ['rank' => 2, 'standing' => null, 'user' => self::publicUser($anna, 'Anna'), 'you' => true, 'reductionPercent' => 50, 'smokeFreeDays' => 0, 'checkIns' => 3, 'countedDays' => 3],
                    // The day Carol never checked in counts as her baseline.
                    ['rank' => null, 'standing' => 'behind', 'user' => self::publicUser($carol, 'Carol'), 'you' => false, 'reductionPercent' => null, 'smokeFreeDays' => 0, 'checkIns' => 0, 'countedDays' => 1],
                ],
                'award' => null,
            ],
            $response->json(),
        );
        // The key of the week names the same league.
        self::assertSame($response->json(), $this->call('GET', '/leagues?type=week&period=2026-W47', headers: $this->authorization($anna))->json());
        // Nothing about the smoking: no count, no limit, no baseline, no exact figure.
        foreach (['count"', 'limit', 'baseline', 'tenths', 'actual', 'expected', 'smokedAt', '10:00', 'email', 'example.org', 'timeZone'] as $private) {
            self::assertStringNotContainsStringIgnoringCase($private, $response->body());
        }
        self::assertDoesNotMatchRegularExpression('/[0-9]\.[0-9]/', $response->body());
    }

    public function test_FR_COMP_06_every_kind_of_period_has_its_league(): void
    {
        [$anna] = $this->threeFriends();

        $periods = [];
        foreach (['week', 'month', 'quarter', 'half_year'] as $type) {
            $league = $this->call('GET', "/leagues?type={$type}&period=current", headers: $this->authorization($anna))->json();
            $periods[$type] = [$league['period'] ?? null, $league['startsOn'] ?? null, $league['endsOn'] ?? null];
        }

        self::assertSame(
            [
                'week' => ['2026-W47', '2026-11-16', '2026-11-22'],
                'month' => ['2026-11', '2026-11-01', '2026-11-30'],
                'quarter' => ['2026-Q4', '2026-10-01', '2026-12-31'],
                'half_year' => ['2026-H2', '2026-07-01', '2026-12-31'],
            ],
            $periods,
        );
    }

    public function test_FR_COMP_08_the_cron_job_stores_the_results_of_the_week_ranks_them_and_books_the_xp(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        // From the onboarding on Tuesday to Sunday: six days. Anna 6 a day, Bob nothing, Carol 12.
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 0, true], [$carol, 12, true]]);
        }

        // The week is over and its last day is not final: who is ahead, and nothing is stored.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 22:59:59 UTC'));
        self::assertStringContainsString('ranked 0 league results.', $this->cronRun());
        $waiting = $this->call('GET', '/leagues?type=week&period=2026-W47', headers: $this->authorization($anna))->json();
        self::assertSame(
            [false, null, [[null, 'ahead', null], [2, null, 50], [null, 'behind', null]]],
            [$waiting['final'] ?? null, $waiting['award'] ?? null, array_map(static fn (mixed $entry): array => [self::at((array) $entry, 'rank'), self::at((array) $entry, 'standing'), self::at((array) $entry, 'reductionPercent')], (array) ($waiting['entries'] ?? null))],
        );
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM league_results'));

        // One second later Sunday is final: the same run stores the three results and ranks them.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        self::assertStringContainsString('ranked 3 league results.', $this->cronRun());

        self::assertEquals(
            [
                [
                    'user_id' => $bob->id->value, 'period_type' => 'week', 'period_key' => '2026-W47', 'b0_tenths' => 120, 'actual_tenths' => 0, 'expected_tenths' => 720,
                    'smoke_free_days' => 6, 'check_ins' => 6, 'counted_days' => 6, 'rules_version' => 'v1', 'scored_at' => '2026-11-24 23:00:00',
                    'rank' => 1, 'participants' => 3, 'xp' => 150, 'ranked_at' => '2026-11-24 23:00:00',
                ],
                [
                    'user_id' => $anna->id->value, 'period_type' => 'week', 'period_key' => '2026-W47', 'b0_tenths' => 120, 'actual_tenths' => 360, 'expected_tenths' => 720,
                    'smoke_free_days' => 0, 'check_ins' => 6, 'counted_days' => 6, 'rules_version' => 'v1', 'scored_at' => '2026-11-24 23:00:00',
                    'rank' => 2, 'participants' => 3, 'xp' => 100, 'ranked_at' => '2026-11-24 23:00:00',
                ],
                [
                    'user_id' => $carol->id->value, 'period_type' => 'week', 'period_key' => '2026-W47', 'b0_tenths' => 120, 'actual_tenths' => 720, 'expected_tenths' => 720,
                    'smoke_free_days' => 0, 'check_ins' => 6, 'counted_days' => 6, 'rules_version' => 'v1', 'scored_at' => '2026-11-24 23:00:00',
                    // Carol smoked exactly her baseline: a rank, and no reward without a reduction.
                    'rank' => 3, 'participants' => 3, 'xp' => 0, 'ranked_at' => '2026-11-24 23:00:00',
                ],
            ],
            $this->sql()->rows(
                'SELECT user_id, period_type, period_key, b0_tenths, actual_tenths, expected_tenths, smoke_free_days, check_ins, counted_days, rules_version, scored_at, '
                . '`rank`, participants, xp, ranked_at FROM league_results ORDER BY `rank`',
            ),
        );
        // The XP is in the ledger with the rank, earned on the user's own today (the 25th in Berlin).
        // Nothing for Carol: rank 3 pays 50 XP, but not without a reduction.
        self::assertEquals(
            [
                ['user_id' => $anna->id->value, 'local_date' => '2026-11-25', 'amount' => 100, 'detail' => 2],
                ['user_id' => $bob->id->value, 'local_date' => '2026-11-25', 'amount' => 150, 'detail' => 1],
            ],
            $this->sql()->rows("SELECT user_id, local_date, amount, detail FROM xp_events WHERE source = 'league' ORDER BY amount"),
        );
        self::assertSame(
            $this->sql()->rows('SELECT user_id, CAST(SUM(amount) AS UNSIGNED) AS xp FROM xp_events GROUP BY user_id ORDER BY user_id'),
            $this->sql()->rows('SELECT user_id, xp_total AS xp FROM user_progress ORDER BY user_id'),
        );

        // Now the league is final: everybody's rank, and the reward. A friend's percentage would be
        // shown for a result over seven days or more. This first week had six, from the onboarding on.
        self::assertSame(
            [
                'type' => 'week', 'period' => '2026-W47', 'startsOn' => '2026-11-16', 'endsOn' => '2026-11-22', 'final' => true,
                'entries' => [
                    ['rank' => 1, 'standing' => null, 'user' => self::publicUser($bob, 'Bob'), 'you' => false, 'reductionPercent' => null, 'smokeFreeDays' => 6, 'checkIns' => 6, 'countedDays' => 6],
                    ['rank' => 2, 'standing' => null, 'user' => self::publicUser($anna, 'Anna'), 'you' => true, 'reductionPercent' => 50, 'smokeFreeDays' => 0, 'checkIns' => 6, 'countedDays' => 6],
                    ['rank' => 3, 'standing' => null, 'user' => self::publicUser($carol, 'Carol'), 'you' => false, 'reductionPercent' => null, 'smokeFreeDays' => 0, 'checkIns' => 6, 'countedDays' => 6],
                ],
                'award' => ['rank' => 2, 'participants' => 3, 'xp' => 100],
            ],
            $this->call('GET', '/leagues?type=week&period=2026-W47', headers: $this->authorization($anna))->json(),
        );
        // A second run ranks nothing twice.
        self::assertStringContainsString('ranked 0 league results.', $this->cronRun());
        self::assertSame(2, $this->sql()->value("SELECT COUNT(*) FROM xp_events WHERE source = 'league'"));
    }

    public function test_FR_COMP_08_a_final_league_over_a_full_week_shows_the_percentage_of_every_friend(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        // The week after the onboarding, Monday the 23rd to Sunday the 29th: seven days each.
        foreach (['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 3, true], [$carol, 12, true]]);
        }
        $this->clock()->travelTo(new DateTimeImmutable('2026-12-01 23:00:00 UTC'));
        $this->cronRun();

        $league = $this->call('GET', '/leagues?type=week&period=2026-W48', headers: $this->authorization($carol))->json();

        self::assertSame(
            [true, [[1, 'Bob', 75], [2, 'Anna', 50], [3, 'Carol', 0]], ['rank' => 3, 'participants' => 3, 'xp' => 0]],
            [
                $league['final'] ?? null,
                array_map(static fn (mixed $entry): array => [self::at((array) $entry, 'rank'), self::at((array) $entry, 'user', 'displayName'), self::at((array) $entry, 'reductionPercent')], (array) ($league['entries'] ?? null)),
                $league['award'] ?? null,
            ],
        );
    }

    public function test_FR_COMP_07_the_weekly_xp_ranking_counts_what_became_final_in_the_users_week(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 0, true], [$carol, 12, true]]);
        }

        // Friday the 20th: the 17th became final last night. 30 XP for a day under the limit, 70 for
        // a smoke-free one, 10 for a check-in over the limit.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-19 23:00:00 UTC'));
        $this->cronRun();
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-20 12:00:00 UTC'));
        $first = $this->call('GET', '/leagues/xp', headers: $this->authorization($anna));

        self::assertSame(200, $first->status());
        self::assertSame(
            [
                'period' => '2026-W47', 'startsOn' => '2026-11-16', 'endsOn' => '2026-11-22',
                'entries' => [
                    ['rank' => 1, 'user' => self::publicUser($bob, 'Bob'), 'you' => false, 'xp' => 70],
                    ['rank' => 2, 'user' => self::publicUser($anna, 'Anna'), 'you' => true, 'xp' => 30],
                    ['rank' => 3, 'user' => self::publicUser($carol, 'Carol'), 'you' => false, 'xp' => 10],
                ],
            ],
            $first->json(),
        );

        // Wednesday the 25th, after the night in which the rest of the days and the league became
        // final: a new week, with the XP that was written in it. Five days each, and the league.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();
        $second = $this->call('GET', '/leagues/xp', headers: $this->authorization($anna))->json();

        self::assertSame(['2026-W48', '2026-11-23', '2026-11-29'], [$second['period'] ?? null, $second['startsOn'] ?? null, $second['endsOn'] ?? null]);
        self::assertSame(
            // Carol: five checked-in days over the limit, and no league XP without a reduction.
            [[1, 'Bob', 500], [2, 'Anna', 250], [3, 'Carol', 50]],
            array_map(static fn (mixed $entry): array => [self::at((array) $entry, 'rank'), self::at((array) $entry, 'user', 'displayName'), self::at((array) $entry, 'xp')], (array) ($second['entries'] ?? null)),
        );
    }

    public function test_FR_ACC_06_before_the_onboarding_and_for_a_period_that_has_not_begun_there_is_no_league(): void
    {
        [$anna] = $this->threeFriends();
        $dave = $this->named('Dave');

        self::assertProblem(409, 'plan_required', $this->call('GET', '/leagues?type=week&period=current', headers: $this->authorization($dave)));
        self::assertProblem(409, 'plan_required', $this->call('GET', '/leagues/xp', headers: $this->authorization($dave)));
        self::assertProblem(404, 'league_not_found', $this->call('GET', '/leagues?type=week&period=2026-W48', headers: $this->authorization($anna)));
        self::assertProblem(404, 'league_not_found', $this->call('GET', '/leagues?type=half_year&period=2027-H1', headers: $this->authorization($anna)));
        // A past period is a league, also one before the plan: nobody is rated in it.
        $past = $this->call('GET', '/leagues?type=month&period=2026-10', headers: $this->authorization($anna));
        self::assertSame(200, $past->status());
        self::assertSame([null, null, null], array_column((array) ($past->json()['entries'] ?? null), 'rank'));
    }

    public function test_a_malformed_request_is_refused_with_every_error(): void
    {
        [$anna] = $this->threeFriends();
        $cases = [
            'nothing' => ['/leagues', [['field' => 'type', 'code' => 'required'], ['field' => 'period', 'code' => 'required']]],
            'no period' => ['/leagues?type=week', [['field' => 'period', 'code' => 'required']]],
            'an unknown type' => ['/leagues?type=year&period=current', [['field' => 'type', 'code' => 'invalid_value']]],
            'a period that is no key' => ['/leagues?type=week&period=last', [['field' => 'period', 'code' => 'invalid_format']]],
            'a date as the period' => ['/leagues?type=month&period=2026-11-17', [['field' => 'period', 'code' => 'invalid_format']]],
            'a key of another type' => ['/leagues?type=week&period=2026-11', [['field' => 'period', 'code' => 'invalid_format']]],
            'a week that does not exist' => ['/leagues?type=week&period=2025-W53', [['field' => 'period', 'code' => 'invalid_value']]],
        ];
        // What the contract cannot say (which weeks a year has, which key fits which type) is refused all the same.
        $validByTheContract = ['a key of another type', 'a week that does not exist'];

        foreach ($cases as $case => [$path, $errors]) {
            $response = in_array($case, $validByTheContract, true)
                ? $this->call('GET', $path, headers: $this->authorization($anna))
                : $this->callInvalid('GET', $path, null, $this->authorization($anna));
            self::assertSame($errors, self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)), $case);
        }
    }

    public function test_FR_ACC_07_the_export_holds_the_own_final_results_as_they_were_scored(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 0, true], [$carol, 12, true]]);
        }
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        $export = $this->call('GET', '/me/export', headers: $this->authorization($anna))->json();

        self::assertEquals(
            [[
                'type' => 'week', 'period' => '2026-W47', 'baseline' => 12, 'actual' => 36, 'expected' => 72, 'smokeFreeDays' => 0, 'checkIns' => 6, 'countedDays' => 6,
                'rulesVersion' => 'v1', 'scoredAt' => '2026-11-24T23:00:00Z', 'rank' => 2, 'participants' => 3, 'xp' => 100, 'rankedAt' => '2026-11-24T23:00:00Z',
            ]],
            $export['leagueResults'] ?? null,
        );
    }

    public function test_FR_ACC_08_a_deleted_account_takes_its_results_and_leaves_the_league_of_its_friends(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 0, true], [$carol, 12, true]]);
        }
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($bob))->status());

        self::assertSame([], $this->sql()->rows('SELECT * FROM league_results WHERE user_id = ?', [$bob->id->value]));
        $league = $this->call('GET', '/leagues?type=week&period=2026-W47', headers: $this->authorization($anna))->json();
        // The list is the friends of today. Her reward stays as it was decided, among three.
        self::assertSame(
            [[1, 'Anna'], [2, 'Carol']],
            array_map(static fn (mixed $entry): array => [self::at((array) $entry, 'rank'), self::at((array) $entry, 'user', 'displayName')], (array) ($league['entries'] ?? null)),
        );
        self::assertSame(['rank' => 2, 'participants' => 3, 'xp' => 100], $league['award'] ?? null);
    }

    public function test_the_contract_states_the_same_values_and_limits_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);

        self::assertSame(array_map(static fn (LeaguePeriod $type): string => $type->value, LeaguePeriod::cases()), $schema('LeaguePeriodType', 'enum'));
        // The user and at most 50 friends.
        self::assertSame(FriendRequests::MAX_FRIENDS + 1, $schema('League', 'properties', 'entries', 'maxItems'));
        self::assertSame(FriendRequests::MAX_FRIENDS + 1, $schema('XpLeague', 'properties', 'entries', 'maxItems'));
        self::assertSame('integer', $schema('LeagueEntry', 'properties', 'reductionPercent', 'type'));
        self::assertSame(array_map(static fn (DuelStanding $standing): string => $standing->value, DuelStanding::cases()), $schema('Standing', 'enum'));
        self::assertSame(7, ShowLeague::MIN_DAYS_FOR_A_PERCENTAGE);
    }

    /**
     * Anna, Bob and Carol: friends of each other, all with a plan since today.
     *
     * @return array{User, User, User}
     */
    private function threeFriends(): array
    {
        $anna = $this->onboarded('Anna');
        $bob = $this->onboarded('Bob');
        $carol = $this->onboarded('Carol');
        $this->befriend($anna, $bob);
        $this->befriend($anna, $carol);
        $this->befriend($bob, $carol);

        return [$anna, $bob, $carol];
    }

    private function named(string $name): User
    {
        $user = $this->givenUser(strtolower($name) . '@example.org');
        self::assertSame(200, $this->call('PATCH', '/me', ['displayName' => $name], $this->authorization($user))->status());

        return $user;
    }

    private function onboarded(string $name): User
    {
        $user = $this->named($name);
        self::assertSame(200, $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($user))->status());

        return $user;
    }

    private function befriend(User $from, User $to): void
    {
        $code = $this->call('GET', '/me/invite', headers: $this->authorization($to))->json()['code'] ?? null;
        self::assertSame(200, $this->post($from, '/friends/requests', ['code' => $code])->status());
        self::assertSame(200, $this->post($to, '/friends/requests/accept', ['userId' => $from->id->value])->status());
    }

    /**
     * Lets a day happen: moves the clock to noon UTC of the date, and for each user logs the count
     * (at ten in the morning) and, if the last value says so, checks the day in.
     *
     * @param list<array{User, int, bool}> $users
     */
    private function day(string $date, array $users): void
    {
        $this->clock()->travelTo(new DateTimeImmutable("{$date} 12:00:00 UTC"));
        foreach ($users as [$user, $count, $checkIn]) {
            if ($count > 0) {
                $log = ['id' => TestData::uuid(++$this->logs), 'smokedAt' => "{$date}T10:00:00Z", 'productType' => 'rolled', 'count' => $count];
                self::assertSame(200, $this->post($user, '/logs/sync', ['items' => [$log]])->status());
            }
            if ($checkIn) {
                self::assertSame(200, $this->call('POST', "/days/{$date}/check-in", headers: $this->authorization($user))->status());
            }
        }
    }

    /**
     * Runs `cron:run` with the application under test.
     *
     * @return string The one line it prints.
     */
    private function cronRun(): string
    {
        $output = new BufferedOutput();
        $this->service(Console::class)->run(['cron:run'], $output);

        return implode("\n", $output->lines);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, string $path, array $body): ApiResponse
    {
        return $this->call('POST', $path, $body, $this->authorization($user));
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

    /**
     * @return array{userId: string, displayName: string, avatar: string}
     */
    private static function publicUser(User $user, string $name): array
    {
        return ['userId' => $user->id->value, 'displayName' => $name, 'avatar' => 'avatar-01'];
    }
}
