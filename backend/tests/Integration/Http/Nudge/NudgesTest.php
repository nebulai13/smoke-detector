<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Nudge;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use Symfony\Component\Yaml\Yaml;

/**
 * Nudges through the real application and database (HLD §4.14). The rules themselves are tested
 * without I/O in tests/Unit/Application/Nudge.
 *
 * It is noon UTC on Tuesday, 17 November 2026, and users live in Berlin. Anna, Bob and Carol are
 * friends of each other and did the onboarding today. Bob has checked in today, Carol has not.
 */
final class NudgesTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    public function test_FR_SOC_03_a_nudge_is_delivered_only_to_a_friend_who_has_not_checked_in_and_the_sender_cannot_tell(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();

        $toBob = $this->post($anna, '/friends/nudge', ['userId' => $bob->id->value]);
        $toCarol = $this->post($anna, '/friends/nudge', ['userId' => $carol->id->value]);

        // The same answer for both, to the last byte.
        self::assertSame([204, ''], [$toBob->status(), $toBob->body()]);
        self::assertSame([204, ''], [$toCarol->status(), $toCarol->body()]);
        self::assertSame(
            [
                ['to_user_id' => $bob->id->value, 'day' => '2026-11-17', 'created_at' => '2026-11-17 12:00:00', 'delivered' => 0],
                ['to_user_id' => $carol->id->value, 'day' => '2026-11-17', 'created_at' => '2026-11-17 12:00:00', 'delivered' => 1],
            ],
            $this->sql()->rows('SELECT to_user_id, `day`, created_at, delivered FROM nudges WHERE from_user_id = ? ORDER BY delivered', [$anna->id->value]),
        );
        self::assertSame(['nudges' => []], $this->call('GET', '/nudges', headers: $this->authorization($bob))->json());
        self::assertSame(['nudges' => [['user' => self::publicUser($anna, 'Anna')]]], $this->call('GET', '/nudges', headers: $this->authorization($carol))->json());
        self::assertSame(['nudges' => []], $this->call('GET', '/nudges', headers: $this->authorization($anna))->json());

        // Carol checks in: the nudge did its work.
        self::assertSame(200, $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($carol))->status());
        self::assertSame(['nudges' => []], $this->call('GET', '/nudges', headers: $this->authorization($carol))->json());
    }

    public function test_FR_SOC_03_what_cannot_be_nudged_is_refused_with_its_reason(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $dave = $this->onboarded('Dave');
        self::assertSame(204, $this->post($anna, '/friends/nudge', ['userId' => $bob->id->value])->status());
        self::assertSame(204, $this->post($anna, '/friends/nudge', ['userId' => $carol->id->value])->status());

        // Once per friend and day, whether the first one was delivered or not.
        self::assertProblem(409, 'already_nudged', $this->post($anna, '/friends/nudge', ['userId' => $bob->id->value]));
        self::assertProblem(409, 'already_nudged', $this->post($anna, '/friends/nudge', ['userId' => $carol->id->value]));
        // Nobody who is no friend, and the answer does not tell whether the id exists.
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/friends/nudge', ['userId' => $dave->id->value]));
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/friends/nudge', ['userId' => $anna->id->value]));
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/friends/nudge', ['userId' => '00000000-0000-7000-8000-00000000abcd']));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('POST', '/friends/nudge', ['userId' => $bob->id->value]));
        self::assertProblem(401, 'unauthorized', $this->callInvalid('GET', '/nudges'));
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM nudges'));
    }

    public function test_a_malformed_request_is_refused_with_every_error(): void
    {
        [$anna, $bob] = $this->threeFriends();
        $cases = [
            'nothing' => [[], [['field' => 'userId', 'code' => 'required']]],
            'an id that is none' => [['userId' => 'bob'], [['field' => 'userId', 'code' => 'invalid_format']]],
            'a text with it' => [['userId' => $bob->id->value, 'text' => 'Come on!'], [['field' => 'text', 'code' => 'unknown_field']]],
        ];

        foreach ($cases as $case => [$body, $errors]) {
            $response = $this->callInvalid('POST', '/friends/nudge', $body, $this->authorization($anna));
            self::assertSame($errors, self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)), $case);
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM nudges'));
    }

    public function test_FR_ACC_07_the_export_of_the_sender_does_not_tell_what_became_of_a_nudge(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $this->post($anna, '/friends/nudge', ['userId' => $bob->id->value]);
        $this->post($anna, '/friends/nudge', ['userId' => $carol->id->value]);

        $annas = $this->call('GET', '/me/export', headers: $this->authorization($anna));
        $bobs = $this->call('GET', '/me/export', headers: $this->authorization($bob))->json();
        $carols = $this->call('GET', '/me/export', headers: $this->authorization($carol))->json();

        self::assertEqualsCanonicalizing(
            [['userId' => $bob->id->value, 'createdAt' => '2026-11-17T12:00:00Z'], ['userId' => $carol->id->value, 'createdAt' => '2026-11-17T12:00:00Z']],
            $annas->json()['nudgesSent'] ?? null,
        );
        self::assertSame([], $annas->json()['nudgesReceived'] ?? null);
        // Whether Bob or Carol had checked in is theirs to know.
        self::assertStringNotContainsString('delivered', $annas->body());
        self::assertSame([['userId' => $anna->id->value, 'createdAt' => '2026-11-17T12:00:00Z', 'delivered' => false]], $bobs['nudgesReceived'] ?? null);
        self::assertSame([['userId' => $anna->id->value, 'createdAt' => '2026-11-17T12:00:00Z', 'delivered' => true]], $carols['nudgesReceived'] ?? null);
        self::assertSame([[], []], [$bobs['nudgesSent'] ?? null, $carols['nudgesSent'] ?? null]);
    }

    public function test_FR_ACC_08_a_deleted_account_leaves_no_nudge_it_sent_or_received(): void
    {
        [$anna, $bob, $carol] = $this->threeFriends();
        $this->post($anna, '/friends/nudge', ['userId' => $carol->id->value]);
        $this->post($carol, '/friends/nudge', ['userId' => $anna->id->value]);
        $this->post($bob, '/friends/nudge', ['userId' => $carol->id->value]);

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna))->status());

        self::assertSame(
            [['from_user_id' => $bob->id->value, 'to_user_id' => $carol->id->value]],
            $this->sql()->rows('SELECT from_user_id, to_user_id FROM nudges'),
        );
        self::assertSame(['nudges' => [['user' => self::publicUser($bob, 'Bob')]]], $this->call('GET', '/nudges', headers: $this->authorization($carol))->json());
    }

    public function test_the_contract_states_the_same_limits_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);

        self::assertSame(FriendRequests::MAX_FRIENDS, self::at($document, 'components', 'schemas', 'Nudges', 'properties', 'nudges', 'maxItems'));
    }

    /**
     * Anna, Bob and Carol as the class comment says.
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
        self::assertSame(200, $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($bob))->status());

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
