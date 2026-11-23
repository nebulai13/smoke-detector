<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Progress;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Console\Console;
use SmokeDetector\Domain\Recovery\MilestoneSharing;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Yaml\Yaml;

/**
 * The health milestones friends may see, and the setting that decides it, through the real
 * application and database (HLD §4.11). The rules themselves are tested without I/O in
 * tests/Unit/Domain/Recovery, tests/Unit/Application/Recovery, tests/Unit/Application/Settings and
 * tests/Unit/Application/Friends.
 *
 * It is noon UTC on Tuesday, 17 November 2026, and users live in Berlin: their days end at 23:00 UTC.
 * Bob is a friend of Anna, Dora and Erik, and all four do the onboarding today.
 * - Anna logs a session at 10:00 UTC every day up to Sunday the 22nd and checks those days in.
 * - Dora logs one session today at 10:00 UTC, none after it, and checks in every day.
 * - Erik logs one session today at 10:00 UTC and never opens the app again.
 * - Bob logs nothing.
 * On 3 December at 23:00 UTC the days up to 1 December are final: for Dora that is two weeks and 13
 * hours without a session, every day of them confirmed.
 */
final class MilestoneSharingTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    private int $logs = 0;

    public function test_FR_FRND_03_friends_see_the_milestones_from_two_weeks_on_unless_the_friend_chose_otherwise(): void
    {
        [$anna, $bob, $dora, $erik] = $this->threeFriendsInDecember();

        // Nobody chose anything. Anna has reached the two short ones only. Erik has logged no session
        // for two weeks either, but he confirmed no day: nobody said that they were smoke-free.
        self::assertSame(['Anna' => [], 'Dora' => ['circulation'], 'Erik' => []], $this->milestonesSeenBy($bob));
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'all'], $this->authorization($erik))->status());
        self::assertSame(['Anna' => [], 'Dora' => ['circulation'], 'Erik' => []], $this->milestonesSeenBy($bob));
        self::assertSame([], $this->sql()->rows('SELECT * FROM user_milestones WHERE user_id = ?', [$erik->id->value]));

        // Anna shows everything, Dora nothing. It counts at once.
        self::assertSame(['milestoneSharing' => 'all'], $this->call('PUT', '/me/settings', ['milestoneSharing' => 'all'], $this->authorization($anna))->json());
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'private'], $this->authorization($dora))->status());
        self::assertSame(['Anna' => ['heart_rate', 'carbon_monoxide'], 'Dora' => [], 'Erik' => []], $this->milestonesSeenBy($bob));

        // Bob's own setting changes nothing about what he sees of the others.
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'private'], $this->authorization($bob))->status());
        self::assertSame(['Anna' => ['heart_rate', 'carbon_monoxide'], 'Dora' => [], 'Erik' => []], $this->milestonesSeenBy($bob));
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'from_two_weeks'], $this->authorization($dora))->status());
        self::assertSame(['Anna' => ['heart_rate', 'carbon_monoxide'], 'Dora' => ['circulation'], 'Erik' => []], $this->milestonesSeenBy($bob));
    }

    public function test_FR_FRND_03_no_time_of_a_milestone_reaches_a_friend(): void
    {
        [$anna, $bob] = $this->threeFriendsInDecember();
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'all'], $this->authorization($anna))->status());

        $response = $this->call('GET', '/friends', headers: $this->authorization($bob));

        // The only time in the answer is when the two became friends, on 17 November at noon.
        foreach (['reachedAt', '2026-11-18', '2026-11-19', '2026-12-0', 'T10:', 'T22:'] as $private) {
            self::assertStringNotContainsString($private, $response->body());
        }
    }

    public function test_FR_FRND_03_a_session_after_two_weeks_takes_nothing_back_and_shows_nowhere(): void
    {
        [, $bob, $dora] = $this->threeFriendsInDecember();
        $before = $this->call('GET', '/friends', headers: $this->authorization($bob))->body();

        // Dora smokes again on 4 December, and checks the day in. Three days later that day is final.
        $this->clock()->travelTo(new DateTimeImmutable('2026-12-04 12:00:00 UTC'));
        self::assertSame(200, $this->post($dora, '/logs/sync', ['items' => [['id' => TestData::uuid(900), 'smokedAt' => '2026-12-04T11:00:00Z', 'productType' => 'rolled', 'count' => 1]]])->status());
        self::assertSame(200, $this->call('POST', '/days/2026-12-04/check-in', headers: $this->authorization($dora))->status());
        self::assertSame($before, $this->call('GET', '/friends', headers: $this->authorization($bob))->body());
        $this->clock()->travelTo(new DateTimeImmutable('2026-12-07 23:00:00 UTC'));
        $this->cronRun();

        // What Bob sees of Dora is what it was: nothing in it moved.
        self::assertSame(['Anna' => [], 'Dora' => ['circulation'], 'Erik' => []], $this->milestonesSeenBy($bob));
        // Dora's own view counts from the new session.
        $own = $this->call('GET', '/me/health-milestones', headers: $this->authorization($dora))->json();
        self::assertSame('2026-12-04T11:00:00Z', $own['since'] ?? null);
    }

    public function test_FR_GAME_09_the_setting_is_the_default_until_the_user_chooses(): void
    {
        $anna = $this->named('Anna');

        self::assertSame(['milestoneSharing' => 'from_two_weeks'], $this->call('GET', '/me/settings', headers: $this->authorization($anna))->json());
        self::assertSame(['milestoneSharing' => 'private'], $this->call('PUT', '/me/settings', ['milestoneSharing' => 'private'], $this->authorization($anna))->json());
        self::assertSame(['milestoneSharing' => 'private'], $this->call('GET', '/me/settings', headers: $this->authorization($anna))->json());
        self::assertSame([['user_id' => $anna->id->value, 'milestone_sharing' => 'private']], $this->sql()->rows('SELECT * FROM user_settings'));
    }

    public function test_a_malformed_setting_is_refused_and_nothing_is_stored(): void
    {
        $anna = $this->named('Anna');
        $cases = [
            'an unknown value' => [['milestoneSharing' => 'everybody'], [['field' => 'milestoneSharing', 'code' => 'invalid_value']]],
            'no value' => [[], [['field' => 'milestoneSharing', 'code' => 'required']]],
            'not a text' => [['milestoneSharing' => 2], [['field' => 'milestoneSharing', 'code' => 'wrong_type']]],
            'an unknown field' => [['milestoneSharing' => 'all', 'theme' => 'dark'], [['field' => 'theme', 'code' => 'unknown_field']]],
        ];

        foreach ($cases as $case => [$body, $errors]) {
            $response = $this->callInvalid('PUT', '/me/settings', $body, $this->authorization($anna));
            self::assertSame($errors, self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)), $case);
        }
        self::assertSame([], $this->sql()->rows('SELECT * FROM user_settings'));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('GET', '/me/settings'));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('PUT', '/me/settings', ['milestoneSharing' => 'all']));
    }

    public function test_FR_ACC_07_the_export_holds_the_reached_milestones_with_their_time_and_the_settings(): void
    {
        [$anna, $bob] = $this->threeFriendsInDecember();
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'private'], $this->authorization($anna))->status());

        $export = $this->call('GET', '/me/export', headers: $this->authorization($anna))->json();
        $bobs = $this->call('GET', '/me/export', headers: $this->authorization($bob))->json();

        // Reached for the first time on her first day: 20 minutes and 12 hours after 10:00 UTC.
        self::assertSame(
            [['key' => 'heart_rate', 'reachedAt' => '2026-11-17T10:20:00Z'], ['key' => 'carbon_monoxide', 'reachedAt' => '2026-11-17T22:00:00Z']],
            $export['healthMilestones'] ?? null,
        );
        self::assertSame(['milestoneSharing' => 'private'], $export['settings'] ?? null);
        // Bob never logged a session and never chose.
        self::assertSame([[], ['milestoneSharing' => 'from_two_weeks']], [$bobs['healthMilestones'] ?? null, $bobs['settings'] ?? null]);
    }

    public function test_FR_ACC_08_a_deleted_account_leaves_no_milestone_and_no_setting(): void
    {
        [$anna, , $dora] = $this->threeFriendsInDecember();
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'all'], $this->authorization($anna))->status());
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'all'], $this->authorization($dora))->status());

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna))->status());

        self::assertSame([], $this->sql()->rows('SELECT * FROM user_milestones WHERE user_id = ?', [$anna->id->value]));
        self::assertSame([$dora->id->value], $this->sql()->column('SELECT user_id FROM user_settings'));
        self::assertSame(3, $this->sql()->value('SELECT COUNT(*) FROM user_milestones WHERE user_id = ?', [$dora->id->value]));
    }

    public function test_the_contract_states_the_same_values_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);

        self::assertSame(array_map(static fn (MilestoneSharing $sharing): string => $sharing->value, MilestoneSharing::cases()), $schema('MilestoneSharing', 'enum'));
        self::assertSame(8, $schema('Friend', 'properties', 'healthMilestones', 'maxItems'));
    }

    /**
     * Anna, Bob, Dora and Erik as the class comment says, on 3 December at 23:00 UTC after a run of the cron job.
     *
     * @return array{User, User, User, User}
     */
    private function threeFriendsInDecember(): array
    {
        $anna = $this->onboarded('Anna');
        $bob = $this->onboarded('Bob');
        $dora = $this->onboarded('Dora');
        $erik = $this->onboarded('Erik');
        $this->befriend($anna, $bob);
        $this->befriend($bob, $dora);
        $this->befriend($erik, $bob);
        for ($date = new DateTimeImmutable('2026-11-17 12:00:00 UTC'); $date->format('Y-m-d') <= '2026-12-01'; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            $this->clock()->travelTo($date);
            $logging = match (true) {
                $day === '2026-11-17' => [$anna, $dora, $erik],
                $day <= '2026-11-22' => [$anna],
                default => [],
            };
            foreach ($logging as $user) {
                $log = ['id' => TestData::uuid(++$this->logs), 'smokedAt' => "{$day}T10:00:00Z", 'productType' => 'rolled', 'count' => 1];
                self::assertSame(200, $this->post($user, '/logs/sync', ['items' => [$log]])->status());
            }
            foreach ($day <= '2026-11-22' ? [$anna, $dora] : [$dora] as $user) {
                self::assertSame(200, $this->call('POST', "/days/{$day}/check-in", headers: $this->authorization($user))->status());
            }
        }
        $this->clock()->travelTo(new DateTimeImmutable('2026-12-03 23:00:00 UTC'));
        $this->cronRun();

        return [$anna, $bob, $dora, $erik];
    }

    /**
     * @return array<mixed> What the user sees of each friend's health milestones, by the friend's name.
     */
    private function milestonesSeenBy(User $user): array
    {
        $friends = (array) ($this->call('GET', '/friends', headers: $this->authorization($user))->json()['friends'] ?? null);

        return array_column(
            array_map(static fn (mixed $friend): array => ['name' => self::at((array) $friend, 'user', 'displayName'), 'milestones' => self::at((array) $friend, 'healthMilestones')], $friends),
            'milestones',
            'name',
        );
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
