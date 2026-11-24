<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Progress;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Console\Console;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Yaml\Yaml;

/**
 * Badges and health milestones through the real application and database (HLD §4.10). The rules
 * themselves are tested without I/O in tests/Unit/Domain/Badge, tests/Unit/Domain/Recovery,
 * tests/Unit/Application/Badge and tests/Unit/Application/Recovery.
 *
 * It is noon UTC on Tuesday, 17 November 2026, in the week 2026-W47 (Monday the 16th to Sunday the
 * 22nd), and users live in Berlin. Three friends do the onboarding today with a baseline of 12 and a
 * limit of 9. From Tuesday to Sunday Anna logs 6 sessions a day, Bob none and Carol 12, and all
 * check in. The week is final on Tuesday the 24th at 23:00 UTC: one run of the cron job then makes
 * the six days final and ranks the week.
 */
final class ProgressTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];
    private const string FINAL = '2026-11-24T23:00:00Z';

    private int $logs = 0;

    public function test_FR_GAME_08_badges_come_with_final_days_and_final_ranks_and_not_before(): void
    {
        [$anna, $bob, $carol] = $this->aWeekOfThreeFriends();

        // A second before Sunday is final: the days up to Saturday are, and the week is not ranked.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 22:59:59 UTC'));
        $this->cronRun();
        $before = $this->call('GET', '/me/badges', headers: $this->authorization($bob));
        self::assertSame(200, $before->status());
        self::assertSame(['first_check_in', 'first_smoke_free_day'], array_column((array) ($before->json()['badges'] ?? null), 'key'));

        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        // Bob: every day smoke-free, and rank 1 of three in the week.
        self::assertSame(
            ['badges' => [
                ['key' => 'first_check_in', 'awardedAt' => '2026-11-24T22:59:59Z'],
                ['key' => 'first_smoke_free_day', 'awardedAt' => '2026-11-24T22:59:59Z'],
                ['key' => 'league_champion_week', 'awardedAt' => self::FINAL],
            ]],
            $this->call('GET', '/me/badges', headers: $this->authorization($bob))->json(),
        );
        // Anna kept her limit on six days (a streak of 7 is the first streak badge) and is second.
        // Carol checked in on days over the limit.
        foreach ([$anna, $carol] as $user) {
            self::assertSame(
                ['badges' => [['key' => 'first_check_in', 'awardedAt' => '2026-11-24T22:59:59Z']]],
                $this->call('GET', '/me/badges', headers: $this->authorization($user))->json(),
            );
        }
    }

    public function test_FR_GAME_08_somebody_without_a_plan_or_a_final_day_has_no_badge_yet(): void
    {
        $new = $this->named('New');
        $anna = $this->onboarded('Anna');
        $this->day('2026-11-17', [[$anna, 0, true]]);

        self::assertSame(['badges' => []], $this->call('GET', '/me/badges', headers: $this->authorization($new))->json());
        // Checked in and smoke-free, but the day can still be changed.
        self::assertSame(['badges' => []], $this->call('GET', '/me/badges', headers: $this->authorization($anna))->json());
    }

    public function test_FR_FRND_03_a_friend_sees_which_badges_a_friend_holds_and_never_when_they_were_earned(): void
    {
        [$anna] = $this->aWeekOfThreeFriends();
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        $response = $this->call('GET', '/friends', headers: $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame(
            ['Bob' => ['first_check_in', 'first_smoke_free_day', 'league_champion_week'], 'Carol' => ['first_check_in']],
            array_column(
                array_map(static fn (mixed $friend): array => ['name' => self::at((array) $friend, 'user', 'displayName'), 'badges' => self::at((array) $friend, 'badges')], (array) ($response->json()['friends'] ?? null)),
                'badges',
                'name',
            ),
        );
        // No time of an award: the friends became friends on the 17th, and nothing else has a date.
        foreach (['awardedAt', '2026-11-2', 'T23:00'] as $private) {
            self::assertStringNotContainsString($private, $response->body());
        }
    }

    public function test_FR_GAME_09_the_health_milestones_count_from_the_last_logged_session(): void
    {
        [$anna, $bob] = $this->aWeekOfThreeFriends();
        // Anna's last session was logged for Sunday the 22nd at 10:00 UTC. Now it is Tuesday evening.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));

        self::assertSame(
            [
                'since' => '2026-11-22T10:00:00Z',
                'vapingOnly' => false,
                'milestones' => [
                    ['key' => 'heart_rate', 'reachedAt' => '2026-11-22T10:20:00Z', 'reached' => true],
                    ['key' => 'carbon_monoxide', 'reachedAt' => '2026-11-22T22:00:00Z', 'reached' => true],
                    ['key' => 'circulation', 'reachedAt' => '2026-12-06T10:00:00Z', 'reached' => false],
                    ['key' => 'breathing', 'reachedAt' => '2026-12-22T10:00:00Z', 'reached' => false],
                    ['key' => 'heart_disease_halved', 'reachedAt' => '2027-11-22T10:00:00Z', 'reached' => false],
                    ['key' => 'stroke', 'reachedAt' => '2031-11-21T10:00:00Z', 'reached' => false],
                    ['key' => 'lung_cancer_halved', 'reachedAt' => '2036-11-19T10:00:00Z', 'reached' => false],
                    ['key' => 'heart_disease_gone', 'reachedAt' => '2041-11-18T10:00:00Z', 'reached' => false],
                ],
            ],
            $this->call('GET', '/me/health-milestones', headers: $this->authorization($anna))->json(),
        );
        // Bob never logged a session: there is nothing to count from.
        self::assertSame(
            ['since' => null, 'vapingOnly' => false, 'milestones' => []],
            $this->call('GET', '/me/health-milestones', headers: $this->authorization($bob))->json(),
        );
    }

    public function test_FR_GAME_09_a_deleted_log_is_no_session_and_vaping_alone_is_said(): void
    {
        $vera = $this->named('Vera');
        self::assertSame(200, $this->call('PUT', '/me/plan', ['defaultProduct' => 'vape'] + self::PLAN, $this->authorization($vera))->status());
        self::assertSame(
            ['since' => null, 'vapingOnly' => true, 'milestones' => []],
            $this->call('GET', '/me/health-milestones', headers: $this->authorization($vera))->json(),
        );

        $vape = ['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T09:00:00Z', 'productType' => 'vape', 'count' => 1];
        $cigarette = ['id' => TestData::uuid(2), 'smokedAt' => '2026-11-17T11:00:00Z', 'productType' => 'cigarette', 'count' => 1];
        self::assertSame(200, $this->post($vera, '/logs/sync', ['items' => [$vape, $cigarette]])->status());
        $both = $this->call('GET', '/me/health-milestones', headers: $this->authorization($vera))->json();
        self::assertSame(['2026-11-17T11:00:00Z', false], [$both['since'] ?? null, $both['vapingOnly'] ?? null]);

        // The cigarette was logged by mistake.
        self::assertSame(200, $this->post($vera, '/logs/sync', ['items' => [['id' => TestData::uuid(2), 'deleted' => true]]])->status());
        $vapingOnly = $this->call('GET', '/me/health-milestones', headers: $this->authorization($vera))->json();
        self::assertSame(['2026-11-17T09:00:00Z', true], [$vapingOnly['since'] ?? null, $vapingOnly['vapingOnly'] ?? null]);
        self::assertSame(
            ['heart_rate'],
            array_column(array_filter((array) ($vapingOnly['milestones'] ?? null), static fn (mixed $milestone): bool => self::at((array) $milestone, 'reached') === true), 'key'),
        );
    }

    public function test_FR_GAME_09_somebody_who_quit_before_joining_sees_the_milestones_from_that_day(): void
    {
        $ina = $this->givenUser('ina@example.org');
        // Ina quit on 1 November and joins on the 17th.
        self::assertSame(200, $this->call('PUT', '/me/plan', ['goalMode' => 'quit', 'target' => 0, 'lastSessionBefore' => '2026-11-01'] + self::PLAN, $this->authorization($ina))->status());

        $view = $this->call('GET', '/me/health-milestones', headers: $this->authorization($ina))->json();

        // From the end of the 1st in Berlin: 23:00 UTC.
        self::assertSame('2026-11-01T23:00:00Z', $view['since'] ?? null);
        self::assertSame(
            ['heart_rate', 'carbon_monoxide', 'circulation'],
            array_column(array_filter((array) ($view['milestones'] ?? null), static fn (mixed $milestone): bool => self::at((array) $milestone, 'reached') === true), 'key'),
        );
    }

    public function test_FR_ACC_06_before_the_onboarding_there_are_no_health_milestones(): void
    {
        $new = $this->named('New');

        self::assertProblem(409, 'plan_required', $this->call('GET', '/me/health-milestones', headers: $this->authorization($new)));
    }

    public function test_NFR_05_both_lists_are_for_the_logged_in_user_only(): void
    {
        self::assertProblem(401, 'unauthorized', $this->callInvalid('GET', '/me/badges'));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('GET', '/me/health-milestones'));
    }

    public function test_FR_ACC_07_the_export_holds_the_badges_with_their_time(): void
    {
        [, $bob] = $this->aWeekOfThreeFriends();
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        $export = $this->call('GET', '/me/export', headers: $this->authorization($bob))->json();

        self::assertSame(
            [
                ['key' => 'first_check_in', 'awardedAt' => self::FINAL],
                ['key' => 'first_smoke_free_day', 'awardedAt' => self::FINAL],
                ['key' => 'league_champion_week', 'awardedAt' => self::FINAL],
            ],
            $export['badges'] ?? null,
        );
    }

    public function test_FR_ACC_08_a_deleted_account_leaves_no_badge_and_the_others_keep_theirs(): void
    {
        [$anna, $bob] = $this->aWeekOfThreeFriends();
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($bob))->status());

        self::assertSame([], $this->sql()->rows('SELECT * FROM user_badges WHERE user_id = ?', [$bob->id->value]));
        self::assertSame(['first_check_in'], $this->sql()->column('SELECT badge_key FROM user_badges WHERE user_id = ?', [$anna->id->value]));
    }

    public function test_the_contract_states_the_same_keys_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);

        self::assertSame(array_map(static fn (Badge $badge): string => $badge->value, Badge::cases()), $schema('BadgeKey', 'enum'));
        self::assertSame(count(Badge::cases()), $schema('Badges', 'properties', 'badges', 'maxItems'));
        self::assertSame(count(Badge::cases()), $schema('Friend', 'properties', 'badges', 'maxItems'));
        self::assertSame(array_map(static fn (HealthMilestone $milestone): string => $milestone->value, HealthMilestone::cases()), $schema('HealthMilestoneKey', 'enum'));
        self::assertSame(count(HealthMilestone::cases()), $schema('HealthMilestones', 'properties', 'milestones', 'maxItems'));
    }

    /**
     * Anna, Bob and Carol: friends of each other, all with a plan since today, and their days from
     * Tuesday the 17th to Sunday the 22nd. Afterwards it is noon UTC on the 22nd.
     *
     * @return array{User, User, User}
     */
    private function aWeekOfThreeFriends(): array
    {
        $anna = $this->onboarded('Anna');
        $bob = $this->onboarded('Bob');
        $carol = $this->onboarded('Carol');
        $this->befriend($anna, $bob);
        $this->befriend($anna, $carol);
        $this->befriend($bob, $carol);
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 0, true], [$carol, 12, true]]);
        }

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
     */
    private function cronRun(): void
    {
        $this->service(Console::class)->run(['cron:run'], new BufferedOutput());
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
}
