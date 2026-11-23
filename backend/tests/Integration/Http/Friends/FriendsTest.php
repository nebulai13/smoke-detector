<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Friends;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Friends\FriendRequests;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\Privacy\DatabaseContents;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Support\TestDatabase;
use Symfony\Component\Yaml\Yaml;

/**
 * Invite code, friend requests, friends and blocks through the real application and database
 * (HLD §4.7). The rules themselves are tested without I/O in tests/Unit/Application/Friends.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Every user is called as their email address begins.
 */
final class FriendsTest extends HttpTestCase
{
    private const array NO_STREAK = ['current' => 0, 'longest' => 0];

    public function test_FR_FRND_01_every_user_has_an_invite_code_and_a_link_that_carries_it_behind_a_hash(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');

        $response = $this->call('GET', '/me/invite', headers: $this->authorization($anna));

        self::assertSame(200, $response->status());
        $invite = $response->json();
        self::assertSame(['code', 'url'], array_keys($invite));
        self::assertIsString($invite['code']);
        self::assertMatchesRegularExpression('/\A[2-9A-HJ-NP-Z]{10}\z/', $invite['code']);
        // Behind a "#": a browser that opens the link never sends the code to a server, so it is in no log.
        self::assertSame('https://app.example.test/invite#' . $invite['code'], $invite['url']);
        self::assertSame(
            [['user_id' => $anna->id->value, 'code' => $invite['code'], 'created_at' => '2026-11-17 12:00:00']],
            $this->sql()->rows('SELECT * FROM invite_codes'),
        );

        // It stays the same, and everybody has their own.
        $this->clock()->advanceSeconds(60);
        self::assertSame($invite, $this->call('GET', '/me/invite', headers: $this->authorization($anna))->json());
        self::assertNotSame($invite['code'], $this->codeOf($bob));
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM invite_codes'));
    }

    public function test_FR_FRND_01_a_renewed_code_replaces_the_old_one_at_once(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $old = $this->codeOf($anna);

        $response = $this->call('POST', '/me/invite', headers: $this->authorization($anna));

        self::assertSame(200, $response->status());
        $new = $response->json()['code'] ?? null;
        self::assertIsString($new);
        self::assertNotSame($old, $new);
        self::assertSame('https://app.example.test/invite#' . $new, $response->json()['url'] ?? null);
        self::assertSame($new, $this->codeOf($anna));
        self::assertProblem(404, 'invite_not_found', $this->post($bob, '/friends/requests', ['code' => $old]));
        self::assertSame(200, $this->post($bob, '/friends/requests', ['code' => $new])->status());
    }

    public function test_FR_FRND_01_a_request_sent_with_the_code_waits_for_the_owner_and_both_see_it(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');

        // Typed in small letters.
        $response = $this->post($anna, '/friends/requests', ['code' => strtolower($this->codeOf($bob))]);

        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'pending', 'user' => self::publicUser($bob, 'Bob')], $response->json());
        self::assertSame(
            ['friends' => [], 'incoming' => [], 'outgoing' => [['user' => self::publicUser($bob, 'Bob'), 'requestedAt' => '2026-11-17T12:00:00Z']]],
            $this->friendsOf($anna),
        );
        self::assertSame(
            ['friends' => [], 'incoming' => [['user' => self::publicUser($anna, 'Anna'), 'requestedAt' => '2026-11-17T12:00:00Z']], 'outgoing' => []],
            $this->friendsOf($bob),
        );
        self::assertSame(
            [['requested_by' => $anna->id->value, 'requested_at' => '2026-11-17 12:00:00', 'accepted_at' => null]],
            $this->sql()->rows('SELECT requested_by, requested_at, accepted_at FROM friendships'),
        );

        // Sent again, later: the same answer and the same request.
        $this->clock()->advanceSeconds(60);
        self::assertSame('pending', $this->post($anna, '/friends/requests', ['code' => $this->codeOf($bob)])->json()['status'] ?? null);
        self::assertSame(['2026-11-17 12:00:00'], $this->sql()->column('SELECT requested_at FROM friendships'));
    }

    public function test_FR_FRND_01_an_accepted_request_makes_the_two_friends(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $this->ask($anna, $bob);
        $this->clock()->advanceSeconds(300);

        $response = $this->post($bob, '/friends/requests/accept', ['userId' => $anna->id->value]);

        self::assertSame(200, $response->status());
        $annaAsFriend = [
            'user' => self::publicUser($anna, 'Anna'), 'since' => '2026-11-17T12:05:00Z', 'level' => 0, 'xp' => 0,
            'checkInStreak' => self::NO_STREAK, 'underLimitStreak' => self::NO_STREAK,
        ];
        self::assertSame($annaAsFriend, $response->json());
        self::assertSame(['friends' => [$annaAsFriend], 'incoming' => [], 'outgoing' => []], $this->friendsOf($bob));
        self::assertSame(
            ['friends' => [['user' => self::publicUser($bob, 'Bob')] + $annaAsFriend], 'incoming' => [], 'outgoing' => []],
            $this->friendsOf($anna),
        );
    }

    public function test_FR_FRND_01_when_both_have_asked_each_other_they_are_friends_at_once(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $this->ask($bob, $anna);

        $response = $this->post($anna, '/friends/requests', ['code' => $this->codeOf($bob)]);

        self::assertSame(['status' => 'accepted', 'user' => self::publicUser($bob, 'Bob')], $response->json());
        self::assertCount(1, $this->friendsOf($anna)['friends']);
        self::assertCount(1, $this->friendsOf($bob)['friends']);
    }

    public function test_FR_FRND_03_friends_see_level_xp_and_streaks_as_stored_and_nothing_about_the_smoking(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $this->befriend($anna, $bob);
        // Bob has a plan, progress from final days, and logs of today.
        $this->call('PUT', '/me/plan', ['startingBaseline' => 17, 'goalMode' => 'reduce', 'target' => 3, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'], $this->authorization($bob));
        $this->sql()->execute(
            'UPDATE user_progress SET check_in_current = 3, check_in_longest = 12, under_limit_current = 2, under_limit_longest = 8, freezes_held = 2, xp_total = 640, level = 3 WHERE user_id = ?',
            [$bob->id->value],
        );
        $this->post($bob, '/logs/sync', ['items' => [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'rolled', 'count' => 4]]]);
        $this->call('POST', '/days/2026-11-17/check-in', headers: $this->authorization($bob));

        $response = $this->call('GET', '/friends', headers: $this->authorization($anna));

        self::assertSame(
            [[
                'user' => self::publicUser($bob, 'Bob'), 'since' => '2026-11-17T12:00:00Z', 'level' => 3, 'xp' => 640,
                'checkInStreak' => ['current' => 3, 'longest' => 12], 'underLimitStreak' => ['current' => 2, 'longest' => 8],
            ]],
            $response->json()['friends'] ?? null,
        );
        // Nothing else: no count, no limit, no baseline, no freezes, no time of a log, no address.
        foreach (['count', 'dailyLimit', '"limit"', 'baseline', 'freeze', 'remaining', 'smokedAt', '08:15', 'email', 'bob@example.org', 'timeZone', 'Berlin'] as $private) {
            self::assertStringNotContainsStringIgnoringCase($private, $response->body());
        }
    }

    public function test_FR_FRND_01_what_cannot_be_asked_is_refused_with_its_reason(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $this->befriend($anna, $bob);

        self::assertProblem(404, 'invite_not_found', $this->post($anna, '/friends/requests', ['code' => $this->codeNobodyHas()]));
        self::assertProblem(409, 'own_invite', $this->post($anna, '/friends/requests', ['code' => $this->codeOf($anna)]));
        self::assertProblem(409, 'already_friends', $this->post($anna, '/friends/requests', ['code' => $this->codeOf($bob)]));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM friendships'));
    }

    public function test_FR_FRND_04_nobody_has_more_than_fifty_friends(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $carol = $this->named('Carol');
        $this->ask($carol, $anna);
        $this->givenFriendsMore($anna, 50);

        // She cannot ask anybody, and cannot accept anybody.
        self::assertProblem(409, 'friend_limit_reached', $this->post($anna, '/friends/requests', ['code' => $this->codeOf($bob)]));
        self::assertProblem(409, 'friend_limit_reached', $this->post($anna, '/friends/requests/accept', ['userId' => $carol->id->value]));
        // Bob can ask her (the answer does not tell how many friends she has), but his own acceptance would be refused, too.
        self::assertSame('pending', $this->ask($bob, $anna)->json()['status'] ?? null);
        self::assertCount(50, $this->friendsOf($anna)['friends']);

        // One friend less, and there is room again.
        $this->sql()->execute('DELETE FROM friendships WHERE accepted_at IS NOT NULL LIMIT 1');
        self::assertSame(200, $this->post($anna, '/friends/requests/accept', ['userId' => $carol->id->value])->status());
    }

    public function test_FR_FRND_01_a_request_is_declined_by_the_one_who_was_asked_or_taken_back_by_the_sender(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $carol = $this->named('Carol');
        $this->ask($anna, $bob);
        $this->ask($anna, $carol);

        // The wrong one of the two cannot do it.
        self::assertProblem(404, 'friend_request_not_found', $this->post($anna, '/friends/requests/decline', ['userId' => $bob->id->value]));
        self::assertProblem(404, 'friend_request_not_found', $this->post($anna, '/friends/requests/accept', ['userId' => $bob->id->value]));
        self::assertProblem(404, 'friend_request_not_found', $this->post($bob, '/friends/requests/withdraw', ['userId' => $anna->id->value]));
        // And nobody else can.
        self::assertProblem(404, 'friend_request_not_found', $this->post($carol, '/friends/requests/accept', ['userId' => $bob->id->value]));
        self::assertProblem(404, 'friend_request_not_found', $this->post($carol, '/friends/requests/decline', ['userId' => $bob->id->value]));

        $declined = $this->post($bob, '/friends/requests/decline', ['userId' => $anna->id->value]);
        $withdrawn = $this->post($anna, '/friends/requests/withdraw', ['userId' => $carol->id->value]);

        self::assertSame([204, ''], [$declined->status(), $declined->body()]);
        self::assertSame([204, ''], [$withdrawn->status(), $withdrawn->body()]);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM friendships'));
        // Gone is gone.
        self::assertProblem(404, 'friend_request_not_found', $this->post($bob, '/friends/requests/decline', ['userId' => $anna->id->value]));
        self::assertProblem(404, 'friend_request_not_found', $this->post($anna, '/friends/requests/withdraw', ['userId' => $carol->id->value]));
        // A declined user can ask again.
        self::assertSame('pending', $this->ask($anna, $bob)->json()['status'] ?? null);
    }

    public function test_FR_FRND_02_a_removed_friend_is_no_friend_of_either_any_more(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $carol = $this->named('Carol');
        $this->befriend($anna, $bob);
        $this->befriend($anna, $carol);
        $this->ask($bob, $carol);

        $response = $this->post($bob, '/friends/remove', ['userId' => $anna->id->value]);

        self::assertSame([204, ''], [$response->status(), $response->body()]);
        self::assertSame(['Carol'], array_column(array_column($this->friendsOf($anna)['friends'], 'user'), 'displayName'));
        self::assertSame([], $this->friendsOf($bob)['friends']);
        // Not a second time, not somebody with an open request, and not a stranger.
        self::assertProblem(404, 'friend_not_found', $this->post($bob, '/friends/remove', ['userId' => $anna->id->value]));
        self::assertProblem(404, 'friend_not_found', $this->post($bob, '/friends/remove', ['userId' => $carol->id->value]));
        self::assertProblem(404, 'friend_not_found', $this->post($bob, '/friends/remove', ['userId' => TestData::uuid(999)]));
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM friendships'));
    }

    public function test_FR_FRND_02_a_block_ends_the_friendship_and_hides_the_two_from_each_other(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $this->befriend($anna, $bob);
        $this->clock()->advanceSeconds(60);

        $response = $this->post($anna, '/friends/block', ['userId' => $bob->id->value]);

        self::assertSame([204, ''], [$response->status(), $response->body()]);
        self::assertSame(['friends' => [], 'incoming' => [], 'outgoing' => []], $this->friendsOf($anna));
        self::assertSame(['friends' => [], 'incoming' => [], 'outgoing' => []], $this->friendsOf($bob));
        self::assertSame(
            [['blocker_id' => $anna->id->value, 'blocked_id' => $bob->id->value, 'created_at' => '2026-11-17 12:01:00']],
            $this->sql()->rows('SELECT * FROM blocks'),
        );
        // For both, the code of the other one seems not to exist: the answer is the very same as for a code nobody has.
        $blocked = $this->post($bob, '/friends/requests', ['code' => $this->codeOf($anna)]);
        self::assertProblem(404, 'invite_not_found', $blocked);
        self::assertSame($this->post($bob, '/friends/requests', ['code' => $this->codeNobodyHas()])->body(), $blocked->body());
        self::assertProblem(404, 'invite_not_found', $this->post($anna, '/friends/requests', ['code' => $this->codeOf($bob)]));
        // Anna sees whom she has blocked. Bob does not see that he is blocked.
        self::assertSame(
            ['blocked' => [['user' => self::publicUser($bob, 'Bob'), 'blockedAt' => '2026-11-17T12:01:00Z']]],
            $this->call('GET', '/friends/blocked', headers: $this->authorization($anna))->json(),
        );
        self::assertSame(['blocked' => []], $this->call('GET', '/friends/blocked', headers: $this->authorization($bob))->json());
        // Blocking again changes nothing. And Bob cannot block her back: he does not see her.
        $this->clock()->advanceSeconds(60);
        self::assertSame(204, $this->post($anna, '/friends/block', ['userId' => $bob->id->value])->status());
        self::assertProblem(404, 'friend_not_found', $this->post($bob, '/friends/block', ['userId' => $anna->id->value]));
        self::assertSame(['2026-11-17 12:01:00'], $this->sql()->column('SELECT created_at FROM blocks'));
    }

    public function test_FR_FRND_02_somebody_with_an_open_request_can_be_blocked_and_a_stranger_cannot(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $carol = $this->named('Carol');
        $dave = $this->named('Dave');
        $this->ask($bob, $anna);
        $this->ask($anna, $carol);

        self::assertSame(204, $this->post($anna, '/friends/block', ['userId' => $bob->id->value])->status());
        self::assertSame(204, $this->post($anna, '/friends/block', ['userId' => $carol->id->value])->status());
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/friends/block', ['userId' => $dave->id->value]));
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/friends/block', ['userId' => TestData::uuid(999)]));

        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM friendships'));
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM blocks'));
    }

    public function test_FR_FRND_02_a_lifted_block_lets_the_two_ask_each_other_again(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $this->befriend($anna, $bob);
        $this->post($anna, '/friends/block', ['userId' => $bob->id->value]);

        // Only the one who blocked can lift it.
        self::assertProblem(404, 'block_not_found', $this->post($bob, '/friends/unblock', ['userId' => $anna->id->value]));
        $response = $this->post($anna, '/friends/unblock', ['userId' => $bob->id->value]);

        self::assertSame([204, ''], [$response->status(), $response->body()]);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM blocks'));
        self::assertProblem(404, 'block_not_found', $this->post($anna, '/friends/unblock', ['userId' => $bob->id->value]));
        // They are not friends again by that, but can ask.
        self::assertSame([], $this->friendsOf($anna)['friends']);
        self::assertSame('pending', $this->ask($bob, $anna)->json()['status'] ?? null);
    }

    public function test_FR_FRND_01_a_user_id_in_capitals_is_the_same_user(): void
    {
        $anna = $this->named('Anna');
        $bob = $this->named('Bob');
        $this->ask($anna, $bob);

        $response = $this->post($bob, '/friends/requests/accept', ['userId' => strtoupper($anna->id->value)]);

        self::assertSame(200, $response->status());
        self::assertSame($anna->id->value, self::at($response->json(), 'user', 'userId'));
        self::assertSame(204, $this->post($bob, '/friends/remove', ['userId' => strtoupper($anna->id->value)])->status());
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM friendships'));
    }

    public function test_NFR_01_every_change_waits_for_the_account_of_the_other_user_too(): void
    {
        // Two acceptances at the same moment must not pass the limit together, and a block must not
        // cross a request. So a change takes both accounts. Here somebody else holds the account of
        // the OTHER user, and the application gives up after a second instead of waiting 50.
        $anna = $this->named('Anna');
        [$bob, $carol, $dave, $erin] = [$this->named('Bob'), $this->named('Carol'), $this->named('Dave'), $this->named('Erin')];
        $this->ask($bob, $anna);
        $this->ask($anna, $carol);
        $this->befriend($anna, $dave);
        $erinsCode = $this->codeOf($erin);
        $this->service(Database::class)->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $changes = [
            [$erin, '/friends/requests', ['code' => $erinsCode]],
            [$bob, '/friends/requests/accept', ['userId' => $bob->id->value]],
            [$bob, '/friends/requests/decline', ['userId' => $bob->id->value]],
            [$carol, '/friends/requests/withdraw', ['userId' => $carol->id->value]],
            [$dave, '/friends/remove', ['userId' => $dave->id->value]],
            [$dave, '/friends/block', ['userId' => $dave->id->value]],
        ];
        $before = [$this->sql()->rows('SELECT * FROM friendships ORDER BY user_a, user_b'), $this->sql()->rows('SELECT * FROM blocks')];

        foreach ($changes as [$other, $path, $body]) {
            $held = TestDatabase::database()->pdo();
            $held->beginTransaction();
            try {
                $this->sql()->rows('SELECT id FROM users WHERE id = ? FOR UPDATE', [$other->id->value]);

                $response = $this->post($anna, $path, $body);
            } finally {
                $held->rollBack();
            }

            self::assertProblem(503, 'service_unavailable', $response);
        }
        self::assertSame($before, [$this->sql()->rows('SELECT * FROM friendships ORDER BY user_a, user_b'), $this->sql()->rows('SELECT * FROM blocks')]);
    }

    public function test_FR_FRND_02_nobody_is_their_own_friend_request_or_block(): void
    {
        $anna = $this->named('Anna');
        $own = ['userId' => $anna->id->value];

        self::assertProblem(404, 'friend_request_not_found', $this->post($anna, '/friends/requests/accept', $own));
        self::assertProblem(404, 'friend_request_not_found', $this->post($anna, '/friends/requests/decline', $own));
        self::assertProblem(404, 'friend_request_not_found', $this->post($anna, '/friends/requests/withdraw', $own));
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/friends/remove', $own));
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/friends/block', $own));
        self::assertProblem(404, 'block_not_found', $this->post($anna, '/friends/unblock', $own));
        self::assertSame([0, 0], [$this->sql()->value('SELECT COUNT(*) FROM friendships'), $this->sql()->value('SELECT COUNT(*) FROM blocks')]);
    }

    public function test_a_malformed_request_is_refused_with_every_error(): void
    {
        $anna = $this->named('Anna');
        $codeCases = [
            'no code' => [[], [['field' => 'code', 'code' => 'required']]],
            'a code that is a number' => [['code' => 2345678923], [['field' => 'code', 'code' => 'wrong_type']]],
            'a code with a zero' => [['code' => 'K7M2QX9RT0'], [['field' => 'code', 'code' => 'invalid_format']]],
            'a code that is too short' => [['code' => 'K7M2QX9RT'], [['field' => 'code', 'code' => 'invalid_format']]],
            'a code with a space' => [['code' => ' K7M2QX9RTB'], [['field' => 'code', 'code' => 'invalid_format']]],
            'an unknown member' => [['code' => 'K7M2QX9RTB', 'message' => 'Hi'], [['field' => 'message', 'code' => 'unknown_field']]],
        ];
        foreach ($codeCases as $case => [$body, $errors]) {
            $problem = self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/friends/requests', $body, $this->authorization($anna)));
            self::assertSame($errors, self::fieldCodes($problem), $case);
        }

        $userCases = [
            'no user' => [[], [['field' => 'userId', 'code' => 'required']]],
            'a user id that is a number' => [['userId' => 7], [['field' => 'userId', 'code' => 'wrong_type']]],
            'a user id that is no UUID' => [['userId' => 'bob'], [['field' => 'userId', 'code' => 'invalid_format']]],
            'an email address' => [['userId' => 'bob@example.org'], [['field' => 'userId', 'code' => 'invalid_format']]],
            'an unknown member' => [['userId' => TestData::uuid(2), 'reason' => 'x'], [['field' => 'reason', 'code' => 'unknown_field']]],
        ];
        $paths = ['/friends/requests/accept', '/friends/requests/decline', '/friends/requests/withdraw', '/friends/remove', '/friends/block', '/friends/unblock'];
        foreach ($paths as $path) {
            foreach ($userCases as $case => [$body, $errors]) {
                $problem = self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', $path, $body, $this->authorization($anna)));
                self::assertSame($errors, self::fieldCodes($problem), "{$path}: {$case}");
            }
        }
        self::assertSame([0, 0], [$this->sql()->value('SELECT COUNT(*) FROM friendships'), $this->sql()->value('SELECT COUNT(*) FROM blocks')]);
    }

    public function test_FR_ACC_07_the_export_lists_friends_requests_and_blocks_by_id_and_nobody_by_name(): void
    {
        $anna = $this->named('Anna');
        [$bob, $carol, $dave, $erin] = [$this->named('Bob'), $this->named('Carol'), $this->named('Dave'), $this->named('Erin')];
        $code = $this->codeOf($anna);
        $this->befriend($anna, $bob);
        $this->clock()->advanceSeconds(60);
        $this->ask($carol, $anna);
        $this->befriend($anna, $dave);
        $this->befriend($anna, $erin);
        $this->clock()->advanceSeconds(60);
        $this->post($anna, '/friends/block', ['userId' => $dave->id->value]);
        $this->post($erin, '/friends/block', ['userId' => $anna->id->value]);

        $response = $this->call('GET', '/me/export', headers: $this->authorization($anna));

        $export = $response->json();
        self::assertSame(['code' => $code, 'createdAt' => '2026-11-17T12:00:00Z'], $export['inviteCode'] ?? null);
        self::assertSame(
            [
                ['userId' => $bob->id->value, 'status' => 'accepted', 'requestedByMe' => true, 'requestedAt' => '2026-11-17T12:00:00Z', 'acceptedAt' => '2026-11-17T12:00:00Z'],
                ['userId' => $carol->id->value, 'status' => 'pending', 'requestedByMe' => false, 'requestedAt' => '2026-11-17T12:01:00Z', 'acceptedAt' => null],
            ],
            $export['friends'] ?? null,
        );
        self::assertSame([['userId' => $dave->id->value, 'blockedAt' => '2026-11-17T12:02:00Z']], $export['blockedUsers'] ?? null);
        // Of other people: ids. No name, no address, and not who has blocked her.
        foreach (['Bob', 'Carol', 'Dave', 'Erin', 'bob@example.org', $erin->id->value] as $notHers) {
            self::assertStringNotContainsString($notHers, $response->body());
        }
    }

    public function test_FR_ACC_08_a_deleted_account_leaves_no_code_friendship_request_or_block_and_the_others_stay(): void
    {
        $anna = $this->named('Anna');
        [$bob, $carol, $dave, $erin] = [$this->named('Bob'), $this->named('Carol'), $this->named('Dave'), $this->named('Erin')];
        $code = $this->codeOf($anna);
        $this->befriend($anna, $bob);
        $this->befriend($bob, $carol);
        $this->ask($carol, $anna);
        $this->befriend($anna, $dave);
        $this->befriend($anna, $erin);
        $this->post($anna, '/friends/block', ['userId' => $dave->id->value]);
        $this->post($erin, '/friends/block', ['userId' => $anna->id->value]);
        $this->post($bob, '/friends/block', ['userId' => $carol->id->value]);

        $response = $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        self::assertSame(204, $response->status());
        self::assertSame([], DatabaseContents::rowsMentioning($this->sql(), [$anna->id->value, 'anna@example.org', $code]));
        // What the others have among themselves is untouched, and so are their accounts.
        self::assertSame(4, $this->sql()->value('SELECT COUNT(*) FROM users'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM friendships'));
        self::assertSame(
            [['blocker_id' => $bob->id->value, 'blocked_id' => $carol->id->value]],
            $this->sql()->rows('SELECT blocker_id, blocked_id FROM blocks'),
        );
        self::assertSame(['friends' => [], 'incoming' => [], 'outgoing' => []], $this->friendsOf($bob));
        self::assertSame(['friends' => [], 'incoming' => [], 'outgoing' => []], $this->friendsOf($carol));
        self::assertSame(['blocked' => []], $this->call('GET', '/friends/blocked', headers: $this->authorization($erin))->json());
        self::assertProblem(404, 'invite_not_found', $this->post($carol, '/friends/requests', ['code' => $code]));
    }

    public function test_the_contract_states_the_same_values_and_limits_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);

        self::assertSame(50, FriendRequests::MAX_FRIENDS);
        self::assertSame(FriendRequests::MAX_FRIENDS, $schema('FriendOverview', 'properties', 'friends', 'maxItems'));
        self::assertSame(FriendRequests::MAX_FRIENDS, $schema('FriendOverview', 'properties', 'outgoing', 'maxItems'));
        // The alphabet of the codes, as the pattern of the contract spells it: digits 2 to 9, and the letters without I and O.
        self::assertSame('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', InviteCode::ALPHABET);
        self::assertSame('^[2-9A-HJ-NP-Z]{' . InviteCode::LENGTH . '}$', $schema('Invite', 'properties', 'code', 'pattern'));
        self::assertSame('^[2-9A-HJ-NP-Za-hj-np-z]{' . InviteCode::LENGTH . '}$', $schema('InviteCode', 'pattern'));
        foreach (str_split(InviteCode::ALPHABET . strtolower(InviteCode::ALPHABET)) as $character) {
            self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Za-hj-np-z]$/', $character);
        }
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
     * A confirmed account whose display name is $name and whose address is <name>@example.org.
     */
    private function named(string $name): User
    {
        $user = $this->givenUser(strtolower($name) . '@example.org');
        self::assertSame(200, $this->call('PATCH', '/me', ['displayName' => $name], $this->authorization($user))->status());

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, string $path, array $body): ApiResponse
    {
        return $this->call('POST', $path, $body, $this->authorization($user));
    }

    private function codeOf(User $user): string
    {
        $code = $this->call('GET', '/me/invite', headers: $this->authorization($user))->json()['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * A well-formed code that belongs to nobody.
     */
    private function codeNobodyHas(): string
    {
        $taken = $this->sql()->column('SELECT code FROM invite_codes');
        foreach (['ZZZZZZZZZZ', 'YYYYYYYYYY'] as $code) {
            if (!in_array($code, $taken, true)) {
                return $code;
            }
        }
        self::fail('Both codes are taken, which does not happen by chance.');
    }

    /**
     * $from sends $to a friend request.
     */
    private function ask(User $from, User $to): ApiResponse
    {
        $response = $this->post($from, '/friends/requests', ['code' => $this->codeOf($to)]);
        self::assertSame(200, $response->status(), $response->body());

        return $response;
    }

    /**
     * $from asks and $to accepts.
     */
    private function befriend(User $from, User $to): void
    {
        $this->ask($from, $to);
        self::assertSame(200, $this->post($to, '/friends/requests/accept', ['userId' => $from->id->value])->status());
    }

    /**
     * Gives $user this many friends more: accounts made for it, written as rows.
     */
    private function givenFriendsMore(User $user, int $howMany): void
    {
        for ($i = 1; $i <= $howMany; $i++) {
            $other = TestData::uuid(5000 + $i);
            $this->sql()->execute(
                'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
                . "VALUES (?, ?, 'x', ?, 'avatar-02', 'de', 'Europe/Berlin', '2026-11-02 08:00:00', '2026-11-01 07:00:00')",
                [$other, "friend{$i}@example.org", sprintf('Friend %02d', $i)],
            );
            [$a, $b] = $other < $user->id->value ? [$other, $user->id->value] : [$user->id->value, $other];
            $this->sql()->execute("INSERT INTO friendships VALUES (?, ?, ?, '2026-11-10 10:00:00', '2026-11-11 09:00:00')", [$a, $b, $other]);
        }
    }

    /**
     * @return array{friends: list<array<string, mixed>>, incoming: list<mixed>, outgoing: list<mixed>}
     */
    private function friendsOf(User $user): array
    {
        $response = $this->call('GET', '/friends', headers: $this->authorization($user));
        self::assertSame(200, $response->status(), $response->body());
        $overview = $response->json();
        self::assertSame(['friends', 'incoming', 'outgoing'], array_keys($overview));

        /** @var array{friends: list<array<string, mixed>>, incoming: list<mixed>, outgoing: list<mixed>} $overview */
        return $overview;
    }

    /**
     * @return array{userId: string, displayName: string, avatar: string} What one user sees of another, at most.
     */
    private static function publicUser(User $user, string $name): array
    {
        return ['userId' => $user->id->value, 'displayName' => $name, 'avatar' => 'avatar-01'];
    }
}
