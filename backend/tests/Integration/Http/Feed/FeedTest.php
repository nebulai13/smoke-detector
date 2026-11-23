<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Feed;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Application\Feed\ShowFeed;
use SmokeDetector\Console\Console;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Yaml\Yaml;

/**
 * The activity feed through the real application and database (HLD §4.12). The rules themselves are
 * tested without I/O in tests/Unit/Application/Feed.
 *
 * It is noon UTC on Tuesday, 17 November 2026, in the week 2026-W47, and users live in Berlin. Three
 * friends do the onboarding today with a baseline of 12 and a limit of 9. From Tuesday to Sunday Anna
 * logs 6 sessions a day at 10:00 UTC, Bob none and Carol 12, and all check in. On Tuesday the 24th at
 * 23:00 UTC one run of the cron job makes the six days final and ranks the week:
 * - Anna: 30 XP a day, level 1 on the fourth day; rank 2 (100 XP).
 * - Bob: 70 XP a day, level 1 on the second day and level 2 on the fifth; rank 1 (150 XP) and champion.
 * - Carol: 10 XP a day; rank 3, and no reward without a reduction.
 */
final class FeedTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    private int $logs = 0;

    public function test_FR_SOC_01_the_feed_shows_what_the_user_and_the_friends_reached_once_it_is_final(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $this->aWeek($anna, $bob, $carol);
        // Checked in for six days, and nothing is final: the feed is empty.
        self::assertSame(['entries' => []], $this->call('GET', '/feed', headers: $this->authorization($anna))->json());

        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        $own = [
            ['Anna', true, 'badge_earned', null, 'first_check_in', null],
            ['Anna', true, 'level_up', 1, null, null],
            ['Anna', true, 'health_milestone', null, null, 'heart_rate'],
            ['Anna', true, 'health_milestone', null, null, 'carbon_monoxide'],
        ];
        $bobs = [
            ['Bob', false, 'badge_earned', null, 'first_check_in', null],
            ['Bob', false, 'badge_earned', null, 'first_smoke_free_day', null],
            ['Bob', false, 'level_up', 1, null, null],
            ['Bob', false, 'level_up', 2, null, null],
            ['Bob', false, 'badge_earned', null, 'league_champion_week', null],
        ];
        // Carol's two short health milestones are hers alone: she never chose to show them.
        $carols = [['Carol', false, 'badge_earned', null, 'first_check_in', null]];
        self::assertEqualsCanonicalizing([...$own, ...$bobs, ...$carols], $this->feedOf($anna));

        // It counts at once when Carol shows all her milestones.
        self::assertSame(200, $this->call('PUT', '/me/settings', ['milestoneSharing' => 'all'], $this->authorization($carol))->status());
        self::assertEqualsCanonicalizing(
            [...$own, ...$bobs, ...$carols, ['Carol', false, 'health_milestone', null, null, 'heart_rate'], ['Carol', false, 'health_milestone', null, null, 'carbon_monoxide']],
            $this->feedOf($anna),
        );
    }

    public function test_FR_FRND_03_no_entry_has_a_time_and_no_id_tells_one(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $this->aWeek($anna, $bob, $carol);
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        $response = $this->call('GET', '/feed', headers: $this->authorization($bob));

        self::assertSame(200, $response->status());
        foreach (['createdAt', 'awardedAt', 'reachedAt', '-11-', 'T23:', 'T12:', 'since'] as $private) {
            self::assertStringNotContainsString($private, $response->body());
        }
        $entries = (array) ($response->json()['entries'] ?? null);
        self::assertCount(8, $entries);
        $ids = [];
        foreach ($entries as $entry) {
            // A random id (version 4). The ids of users and logs begin with their time.
            $id = self::at((array) $entry, 'id');
            self::assertIsString($id);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
            $ids[$id] = true;
        }
        self::assertCount(8, $ids);
        foreach ($entries as $entry) {
            self::assertSame(['id', 'type', 'user', 'mine', 'level', 'badge', 'milestone', 'opponent'], array_keys((array) $entry));
        }
    }

    public function test_FR_SOC_01_somebody_who_is_no_friend_sees_nothing_of_it(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $dave = $this->onboarded('Dave');
        $this->aWeek($anna, $bob, $carol);
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        self::assertSame(['entries' => []], $this->call('GET', '/feed', headers: $this->authorization($dave))->json());
        // No longer friends: Bob's entries are gone from Anna's feed, and hers from his.
        self::assertSame(204, $this->post($anna, '/friends/remove', ['userId' => $bob->id->value])->status());
        self::assertSame(['Anna', 'Carol'], $this->namesInTheFeedOf($anna));
        self::assertSame(['Bob', 'Carol'], $this->namesInTheFeedOf($bob));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('GET', '/feed'));
    }

    public function test_FR_ACC_07_the_export_holds_the_own_entries_with_their_time(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $this->aWeek($anna, $bob, $carol);
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        $export = $this->call('GET', '/me/export', headers: $this->authorization($anna))->json();

        $at = ['opponentId' => null, 'createdAt' => '2026-11-24T23:00:00Z'];
        self::assertEqualsCanonicalizing(
            [
                ['type' => 'badge_earned', 'detail' => 'first_check_in'] + $at,
                ['type' => 'level_up', 'detail' => '1'] + $at,
                ['type' => 'health_milestone', 'detail' => 'heart_rate'] + $at,
                ['type' => 'health_milestone', 'detail' => 'carbon_monoxide'] + $at,
            ],
            $export['feedEvents'] ?? null,
        );
    }

    public function test_FR_ACC_08_a_deleted_account_takes_its_entries_with_it(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $this->aWeek($anna, $bob, $carol);
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->cronRun();

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($bob))->status());

        self::assertSame([], $this->sql()->rows('SELECT * FROM feed_events WHERE user_id = ?', [$bob->id->value]));
        self::assertSame(['Anna', 'Carol'], $this->namesInTheFeedOf($anna));
    }

    public function test_the_contract_states_the_same_values_and_limits_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);

        self::assertSame(array_map(static fn (FeedEventType $type): string => $type->value, FeedEventType::cases()), $schema('FeedEntryType', 'enum'));
        self::assertSame(ShowFeed::LIMIT, $schema('Feed', 'properties', 'entries', 'maxItems'));
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed}> Name, whether it is the user's own, type, level, badge and
     *                                                                milestone of every entry the user sees, newest first.
     */
    private function feedOf(User $user): array
    {
        $response = $this->call('GET', '/feed', headers: $this->authorization($user));
        self::assertSame(200, $response->status());

        return array_map(
            static fn (mixed $entry): array => [
                self::at((array) $entry, 'user', 'displayName'), self::at((array) $entry, 'mine'), self::at((array) $entry, 'type'),
                self::at((array) $entry, 'level'), self::at((array) $entry, 'badge'), self::at((array) $entry, 'milestone'),
            ],
            array_values((array) ($response->json()['entries'] ?? null)),
        );
    }

    /**
     * @return list<string> Whose entries the user sees, each name once, by the alphabet.
     */
    private function namesInTheFeedOf(User $user): array
    {
        $names = [];
        foreach ($this->feedOf($user) as $entry) {
            self::assertIsString($entry[0]);
            $names[$entry[0]] = $entry[0];
        }
        sort($names);

        return $names;
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

    /**
     * The days from Tuesday the 17th to Sunday the 22nd, as the class comment says.
     */
    private function aWeek(User $anna, User $bob, User $carol): void
    {
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
            $this->clock()->travelTo(new DateTimeImmutable("{$date} 12:00:00 UTC"));
            foreach ([[$anna, 6], [$bob, 0], [$carol, 12]] as [$user, $count]) {
                if ($count > 0) {
                    $log = ['id' => TestData::uuid(++$this->logs), 'smokedAt' => "{$date}T10:00:00Z", 'productType' => 'rolled', 'count' => $count];
                    self::assertSame(200, $this->post($user, '/logs/sync', ['items' => [$log]])->status());
                }
                self::assertSame(200, $this->call('POST', "/days/{$date}/check-in", headers: $this->authorization($user))->status());
            }
        }
    }

    private function onboarded(string $name): User
    {
        $user = $this->givenUser(strtolower($name) . '@example.org');
        self::assertSame(200, $this->call('PATCH', '/me', ['displayName' => $name], $this->authorization($user))->status());
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
