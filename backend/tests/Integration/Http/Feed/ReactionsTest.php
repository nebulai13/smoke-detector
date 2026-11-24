<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Feed;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Feed\React;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Console\Console;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Yaml\Yaml;

/**
 * Reactions to feed entries through the real application and database (HLD §4.13). The rules
 * themselves are tested without I/O in tests/Unit/Application/Feed.
 *
 * The week of FeedTest: Anna, Bob and Carol are friends, and on Tuesday the 24th at 23:00 UTC (the
 * 25th in Berlin) one run of the cron job has made their six days final. Bob is the champion of the
 * week. Carol has two health milestones that she does not show.
 */
final class ReactionsTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    private int $logs = 0;

    public function test_FR_SOC_02_a_reaction_is_stored_earns_xp_and_is_shown_to_its_sender_and_to_the_owner_of_the_entry(): void
    {
        [$anna, $bob, $carol] = $this->afterTheWeek();
        $champion = $this->entryId($anna, 'Bob', 'league_champion_week');
        $xpBefore = $this->sql()->value('SELECT xp_total FROM user_progress WHERE user_id = ?', [$anna->id->value]);
        self::assertIsInt($xpBefore);

        $response = $this->post($anna, '/feed/reactions', ['entryId' => $champion, 'reaction' => 'cheer']);

        self::assertSame(200, $response->status());
        self::assertSame(['reaction' => 'cheer', 'xp' => 2], $response->json());
        self::assertSame(
            [['event_id' => $champion, 'user_id' => $anna->id->value, 'reaction' => 'cheer', 'created_at' => '2026-11-24 23:00:00']],
            $this->sql()->rows('SELECT * FROM feed_reactions'),
        );
        // Booked on Anna's own day, which is the 25th already, and in her total at once.
        self::assertSame(
            [['local_date' => '2026-11-25', 'amount' => 2]],
            $this->sql()->rows("SELECT local_date, amount FROM xp_events WHERE user_id = ? AND source = 'reaction'", [$anna->id->value]),
        );
        self::assertSame($xpBefore + 2, $this->sql()->value('SELECT xp_total FROM user_progress WHERE user_id = ?', [$anna->id->value]));
        // Anna sees her reaction. Bob, whose entry it is, sees who reacted. Carol sees neither.
        self::assertSame(['cheer', []], $this->reactionsOn($anna, $champion));
        self::assertSame([null, [['user' => self::publicUser($anna, 'Anna'), 'reaction' => 'cheer']]], $this->reactionsOn($bob, $champion));
        self::assertSame([null, []], $this->reactionsOn($carol, $champion));
    }

    public function test_FR_SOC_02_a_reaction_can_change_and_earns_nothing_again(): void
    {
        [$anna, $bob] = $this->afterTheWeek();
        $champion = $this->entryId($anna, 'Bob', 'league_champion_week');
        $this->post($anna, '/feed/reactions', ['entryId' => $champion, 'reaction' => 'cheer']);

        $changed = $this->post($anna, '/feed/reactions', ['entryId' => $champion, 'reaction' => 'keep_going']);

        self::assertSame(['reaction' => 'keep_going', 'xp' => 0], $changed->json());
        self::assertSame([null, [['user' => self::publicUser($anna, 'Anna'), 'reaction' => 'keep_going']]], $this->reactionsOn($bob, $champion));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM feed_reactions'));
        self::assertSame(1, $this->sql()->value("SELECT COUNT(*) FROM xp_events WHERE source = 'reaction'"));
    }

    public function test_FR_SOC_02_what_cannot_be_reacted_to_is_refused_with_its_reason(): void
    {
        [$anna, , $carol] = $this->afterTheWeek();
        $dave = $this->onboarded('Dave');
        $own = $this->entryId($anna, 'Anna', 'first_check_in');
        $hidden = $this->sql()->value("SELECT id FROM feed_events WHERE user_id = ? AND type = 'health_milestone' LIMIT 1", [$carol->id->value]);
        self::assertIsString($hidden);

        self::assertProblem(409, 'own_feed_entry', $this->post($anna, '/feed/reactions', ['entryId' => $own, 'reaction' => 'cheer']));
        // An entry that exists and is not for Anna to see, one that does not exist, and one of somebody who is no friend: one answer.
        self::assertProblem(404, 'feed_entry_not_found', $this->post($anna, '/feed/reactions', ['entryId' => $hidden, 'reaction' => 'cheer']));
        self::assertProblem(404, 'feed_entry_not_found', $this->post($anna, '/feed/reactions', ['entryId' => 'f0000000-0000-4000-8000-000000000001', 'reaction' => 'cheer']));
        self::assertProblem(404, 'feed_entry_not_found', $this->post($dave, '/feed/reactions', ['entryId' => $own, 'reaction' => 'cheer']));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('POST', '/feed/reactions', ['entryId' => $own, 'reaction' => 'cheer']));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM feed_reactions'));
    }

    public function test_a_malformed_request_is_refused_with_every_error(): void
    {
        [$anna] = $this->afterTheWeek();
        $entry = $this->entryId($anna, 'Bob', 'league_champion_week');
        $cases = [
            'nothing' => [[], [['field' => 'entryId', 'code' => 'required'], ['field' => 'reaction', 'code' => 'required']]],
            'a reaction there is not' => [['entryId' => $entry, 'reaction' => 'love'], [['field' => 'reaction', 'code' => 'invalid_value']]],
            'a text as the reaction' => [['entryId' => $entry, 'reaction' => 'Well done!'], [['field' => 'reaction', 'code' => 'invalid_value']]],
            'an id that is none' => [['entryId' => 'the-champion', 'reaction' => 'cheer'], [['field' => 'entryId', 'code' => 'invalid_format']]],
            'wrong types' => [['entryId' => 7, 'reaction' => true], [['field' => 'entryId', 'code' => 'wrong_type'], ['field' => 'reaction', 'code' => 'wrong_type']]],
            'a field too many' => [['entryId' => $entry, 'reaction' => 'cheer', 'text' => 'Well done!'], [['field' => 'text', 'code' => 'unknown_field']]],
        ];

        foreach ($cases as $case => [$body, $errors]) {
            $response = $this->callInvalid('POST', '/feed/reactions', $body, $this->authorization($anna));
            self::assertSame($errors, self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)), $case);
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM feed_reactions'));
    }

    public function test_FR_SOC_04_the_thirty_first_reaction_of_a_day_is_refused(): void
    {
        [$anna] = $this->afterTheWeek();
        $entry = $this->entryId($anna, 'Bob', 'league_champion_week');
        foreach (range(1, React::DAILY_ACTIONS) as $number) {
            self::assertSame(200, $this->post($anna, '/feed/reactions', ['entryId' => $entry, 'reaction' => $number % 2 === 0 ? 'cheer' : 'strong'])->status(), "Reaction {$number}");
        }

        $refused = $this->post($anna, '/feed/reactions', ['entryId' => $entry, 'reaction' => 'keep_going']);

        self::assertProblem(429, 'rate_limited', $refused);
        // Until midnight UTC, one hour from now.
        self::assertSame('3600', $refused->header('Retry-After'));
        self::assertSame(['cheer', []], $this->reactionsOn($anna, $entry));
    }

    public function test_FR_ACC_07_the_export_holds_the_reactions_a_user_sent_and_those_to_the_users_entries(): void
    {
        [$anna, $bob] = $this->afterTheWeek();
        $champion = $this->entryId($anna, 'Bob', 'league_champion_week');
        $this->post($anna, '/feed/reactions', ['entryId' => $champion, 'reaction' => 'strong']);

        $annas = $this->call('GET', '/me/export', headers: $this->authorization($anna))->json();
        $bobs = $this->call('GET', '/me/export', headers: $this->authorization($bob))->json();

        $reaction = ['reaction' => 'strong', 'createdAt' => '2026-11-24T23:00:00Z'];
        self::assertSame([['userId' => $bob->id->value] + $reaction], $annas['reactionsSent'] ?? null);
        self::assertSame([], $bobs['reactionsSent'] ?? null);
        $received = [];
        foreach ((array) ($bobs['feedEvents'] ?? null) as $entry) {
            $detail = self::at((array) $entry, 'detail');
            self::assertIsString($detail);
            $received[$detail] = self::at((array) $entry, 'reactions');
        }
        self::assertSame([['userId' => $anna->id->value] + $reaction], $received['league_champion_week'] ?? null);
        self::assertSame([], $received['first_check_in'] ?? null);
    }

    public function test_FR_ACC_08_reactions_go_with_the_account_of_their_sender_and_with_the_entry(): void
    {
        [$anna, $bob, $carol] = $this->afterTheWeek();
        $champion = $this->entryId($anna, 'Bob', 'league_champion_week');
        $carols = $this->entryId($anna, 'Carol', 'first_check_in');
        $this->post($anna, '/feed/reactions', ['entryId' => $champion, 'reaction' => 'cheer']);
        $this->post($carol, '/feed/reactions', ['entryId' => $champion, 'reaction' => 'strong']);
        $this->post($anna, '/feed/reactions', ['entryId' => $carols, 'reaction' => 'cheer']);
        $this->post($bob, '/feed/reactions', ['entryId' => $carols, 'reaction' => 'cheer']);

        // Anna goes: her reactions go with her.
        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna))->status());
        self::assertSame([null, [['user' => self::publicUser($carol, 'Carol'), 'reaction' => 'strong']]], $this->reactionsOn($bob, $champion));
        // Bob goes: his entries go, and the reactions to them, and the reactions he sent.
        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($bob))->status());
        self::assertSame([], $this->sql()->rows('SELECT * FROM feed_reactions'));
    }

    public function test_the_contract_states_the_same_values_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);

        self::assertSame(
            array_map(static fn (ReactionKey $reaction): string => $reaction->value, ReactionKey::cases()),
            self::at($document, 'components', 'schemas', 'ReactionKey', 'enum'),
        );
        self::assertSame(30, React::DAILY_ACTIONS);
    }

    /**
     * @return array{mixed, mixed} The user's own reaction to the entry and the reactions the user sees on it.
     */
    private function reactionsOn(User $viewer, string $entryId): array
    {
        foreach ($this->entriesOf($viewer) as $entry) {
            if (self::at($entry, 'id') === $entryId) {
                return [self::at($entry, 'myReaction'), self::at($entry, 'reactions')];
            }
        }
        self::fail('The user does not see that entry.');
    }

    /**
     * The id of the badge entry of the user with that name, as the viewer's feed has it.
     */
    private function entryId(User $viewer, string $name, string $badge): string
    {
        foreach ($this->entriesOf($viewer) as $entry) {
            if (self::at($entry, 'user', 'displayName') === $name && self::at($entry, 'badge') === $badge) {
                $id = self::at($entry, 'id');
                self::assertIsString($id);

                return $id;
            }
        }
        self::fail("The feed has no entry of {$name} for the badge {$badge}.");
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function entriesOf(User $viewer): array
    {
        $response = $this->call('GET', '/feed', headers: $this->authorization($viewer));
        self::assertSame(200, $response->status());

        return array_values(array_map(static fn (mixed $entry): array => (array) $entry, (array) ($response->json()['entries'] ?? null)));
    }

    /**
     * Anna, Bob and Carol after their week, on the 24th at 23:00 UTC with every day final.
     *
     * @return array{User, User, User}
     */
    private function afterTheWeek(): array
    {
        $anna = $this->onboarded('Anna');
        $bob = $this->onboarded('Bob');
        $carol = $this->onboarded('Carol');
        $this->befriend($anna, $bob);
        $this->befriend($anna, $carol);
        $this->befriend($bob, $carol);
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
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-24 23:00:00 UTC'));
        $this->service(Console::class)->run(['cron:run'], new BufferedOutput());

        return [$anna, $bob, $carol];
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
