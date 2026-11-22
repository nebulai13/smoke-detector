<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\InvalidRefreshToken;
use SmokeDetector\Application\Auth\RefreshToken;
use SmokeDetector\Application\Auth\Session;
use SmokeDetector\Tests\Support\Fake\FakeRefreshSecrets;

/**
 * POST /auth/refresh and POST /auth/logout: rotation, reuse detection and revocation
 * (HLD §4.5 "Login and tokens", FR-ACC-04, NFR-05).
 */
final class RefreshSessionTest extends AuthUseCaseTestCase
{
    // A refresh token is "<session key>.<secret>". The test generator numbers the keys it makes
    // ("token-1" for the first login), and the secret follows from the key and the generation of
    // the token: generation 0 is the token of the login, each refresh makes the next one.
    private const string LOGIN = 'token-1.secret-0-of-token-1';
    private const string FIRST_REFRESH = 'token-1.secret-1-of-token-1';
    private const string SECOND_REFRESH = 'token-1.secret-2-of-token-1';
    private const string THIRD_REFRESH = 'token-1.secret-3-of-token-1';

    public function test_FR_ACC_04_a_refresh_token_gives_a_new_token_pair(): void
    {
        $user = $this->givenUser();
        self::assertSame(self::LOGIN, $this->logInAs()->refreshToken);
        $this->clock->advanceSeconds(3600);

        $session = $this->refreshSession()->execute(self::LOGIN);

        self::assertSame(self::FIRST_REFRESH, $session->refreshToken);
        self::assertSame('access-2-v0-for-' . $user->id->value, $session->accessToken->token);
        self::assertSame(900, $session->accessToken->expiresInSeconds);
        // 60 days from now, not from the login: a session that is used stays alive.
        self::assertEquals(new DateTimeImmutable('2027-01-16 13:00:00 UTC'), $session->refreshTokenExpiresAt);
    }

    public function test_NFR_05_a_refresh_replaces_the_token_of_its_session_and_stores_nothing_new(): void
    {
        $this->givenUser();
        $this->logInAs(DeviceLabel::fromInput('Annas iPhone'));
        [$before] = $this->refreshTokens->all();
        self::assertSame(0, $before->generation);
        $this->clock->advanceSeconds(3600);

        $this->refreshSession()->execute(self::LOGIN);

        // One row per login, however often it is refreshed: the table cannot grow by refreshing.
        self::assertCount(1, $this->refreshTokens->all());
        [$after] = $this->refreshTokens->all();
        self::assertSame($before->id, $after->id);
        self::assertTrue($after->userId->equals($before->userId));
        self::assertSame(hash('sha256', 'token-1'), $after->keyHash);
        // Only the hash of the new token and the number of its generation: no secret is stored.
        self::assertSame(hash('sha256', self::FIRST_REFRESH), $after->tokenHash);
        self::assertSame(1, $after->generation);
        self::assertSame('Annas iPhone', $after->deviceLabel?->value);
        self::assertEquals($before->createdAt, $after->createdAt);
        self::assertEquals($this->now(), $after->rotatedAt);
        self::assertEquals($this->now()->modify('+60 days'), $after->expiresAt);
        self::assertNull($after->revokedAt);
    }

    public function test_NFR_05_a_thousand_refreshes_leave_one_row(): void
    {
        $this->givenUser();
        $token = $this->logInAs()->refreshToken;
        $seen = [$token];

        for ($i = 0; $i < 1000; $i++) {
            $this->clock->advanceSeconds(60);
            $token = $this->refreshSession()->execute($token)->refreshToken;
            $seen[] = $token;
        }

        self::assertCount(1, $this->refreshTokens->all());
        self::assertSame(1000, $this->refreshTokens->all()[0]->generation);
        self::assertSame(FakeRefreshSecrets::token('token-1', 1000), $token);
        // Every refresh gave a token that was never handed out before.
        self::assertCount(1001, array_unique($seen));
    }

    public function test_NFR_05_two_sessions_never_share_a_token_although_the_generations_are_the_same(): void
    {
        $this->givenUser();
        $phone = $this->logInAs()->refreshToken;
        $laptop = $this->logInAs()->refreshToken;

        self::assertSame(FakeRefreshSecrets::token('token-2', 0), $laptop);
        self::assertNotSame(explode('.', $phone)[1], explode('.', $laptop)[1]);
        // The secret is asked for with the key of the session and the generation, nothing else.
        self::assertSame([['token-1', 0], ['token-2', 0]], $this->refreshSecrets->asked);
    }

    public function test_FR_ACC_04_the_token_before_the_current_one_is_answered_with_the_same_new_token_for_ten_seconds(): void
    {
        // SRS OQ-12: two browser tabs that refresh at the same moment, or a client that repeats a
        // refresh whose answer got lost, present the token that was just replaced.
        $user = $this->givenUser();
        $this->logInAs();
        $this->clock->advanceSeconds(3600);
        $first = $this->refreshSession()->execute(self::LOGIN);
        [$rotated] = $this->refreshTokens->all();
        $this->clock->advanceSeconds(10);

        $second = $this->refreshSession()->execute(self::LOGIN);

        // The SAME new refresh token as the first answer, so both end up with the current one
        // whichever answer arrives last, and a fresh access token.
        self::assertSame(self::FIRST_REFRESH, $first->refreshToken);
        self::assertSame($first->refreshToken, $second->refreshToken);
        self::assertSame('access-3-v0-for-' . $user->id->value, $second->accessToken->token);
        self::assertNotSame($first->accessToken->token, $second->accessToken->token);
        // It lives as long as the first answer said: the repetition extends nothing.
        self::assertEquals($first->refreshTokenExpiresAt, $second->refreshTokenExpiresAt);
        // The session rotated once. The second request changed nothing at all.
        self::assertEquals([$rotated], $this->refreshTokens->all());
        self::assertSame(1, $rotated->generation);
        self::assertNull($rotated->revokedAt);
        // And the token both now hold works as usual.
        $this->clock->advanceSeconds(60);
        self::assertSame(self::SECOND_REFRESH, $this->refreshSession()->execute($second->refreshToken)->refreshToken);
    }

    public function test_FR_ACC_04_the_token_before_the_current_one_can_be_presented_more_than_once_within_the_ten_seconds(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->refreshSession()->execute(self::LOGIN);

        $answers = [];
        foreach ([0, 4, 9, 10] as $second) {
            $this->clock->travelTo(new DateTimeImmutable('2026-11-17 12:00:00 UTC')->modify("+{$second} seconds"));
            $answers[] = $this->refreshSession()->execute(self::LOGIN)->refreshToken;
        }

        self::assertSame(array_fill(0, 4, self::FIRST_REFRESH), $answers);
        self::assertSame(1, $this->refreshTokens->all()[0]->generation);
    }

    public function test_NFR_05_eleven_seconds_after_the_refresh_the_token_before_it_ends_the_session(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->refreshSession()->execute(self::LOGIN);
        $this->clock->advanceSeconds(11);

        // After the ten seconds it is an old token shown again: theft detection as before.
        $this->expectRefused(self::LOGIN);

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
        $this->expectRefused(self::FIRST_REFRESH);
        self::assertSame(1, $this->refreshTokens->all()[0]->generation);
    }

    public function test_NFR_05_the_ten_seconds_count_from_the_last_refresh_and_not_from_the_login(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->clock->advanceSeconds(5);
        $this->refreshSession()->execute(self::LOGIN);
        $this->clock->advanceSeconds(8);

        // 13 seconds after the login, 8 after the refresh.
        self::assertSame(self::FIRST_REFRESH, $this->refreshSession()->execute(self::LOGIN)->refreshToken);
    }

    public function test_NFR_05_a_token_two_generations_old_ends_the_session_even_within_ten_seconds(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->refreshSession()->execute(self::LOGIN);
        $this->clock->advanceSeconds(2);
        $this->refreshSession()->execute(self::FIRST_REFRESH);
        $this->clock->advanceSeconds(2);

        // Only the one token before the current one is tolerated. This one was replaced 4 seconds
        // ago, and replaced again since: somebody is holding on to an old token.
        $this->expectRefused(self::LOGIN);

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
        self::assertSame(2, $this->refreshTokens->all()[0]->generation);
        $this->expectRefused(self::SECOND_REFRESH);
        $this->expectRefused(self::FIRST_REFRESH);
    }

    public function test_NFR_05_a_token_of_a_generation_that_does_not_exist_yet_ends_the_session(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->refreshSession()->execute(self::LOGIN);

        $this->expectRefused(self::SECOND_REFRESH);

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_NFR_05_the_tolerance_does_not_revive_a_session_that_is_over(): void
    {
        $user = $this->givenUser();
        $this->logInAs();
        $this->refreshSession()->execute(self::LOGIN);
        $rotated = $this->refreshTokens->all()[0];

        // Logged out a moment after the refresh.
        $this->logOut()->execute(self::FIRST_REFRESH);
        $this->expectRefused(self::LOGIN);

        // Expired (cannot be within ten seconds of a refresh by the rules, but must not work if it is).
        $this->refreshTokens->restore([$rotated->id => new RefreshToken(
            $rotated->id, $rotated->userId, $rotated->keyHash, $rotated->tokenHash, null, $rotated->createdAt, $this->now(), $this->now(), null, 1,
        )]);
        $this->expectRefused(self::LOGIN);
        self::assertNull($this->refreshTokens->all()[0]->revokedAt);

        // The account is not confirmed (cannot come about through login either).
        $this->refreshTokens->restore([$rotated->id => $rotated]);
        $this->users->update(new User($user->id, $user->email, $user->passwordHash, $user->displayName, $user->avatar, $user->locale, $user->timeZone, null, $user->createdAt));
        $this->expectRefused(self::LOGIN);
        self::assertNull($this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_NFR_05_a_token_of_the_old_format_is_refused_cleanly(): void
    {
        // Before the tolerance was built in, the secret of a token was random. Such a session can
        // still be stored (a development database, a restored backup): its token is of generation
        // 0 by the row, but not the token this server makes for generation 0.
        $user = $this->givenUser();
        $old = new RefreshToken(
            $this->ids->next(),
            $user->id,
            hash('sha256', 'old-key'),
            hash('sha256', 'old-key.a-random-secret'),
            null,
            $this->now()->modify('-1 day'),
            null,
            $this->now()->modify('+59 days'),
            null,
        );
        $this->refreshTokens->add($old);

        // The refusal of every other token that is not accepted, and the session is over: the
        // user logs in again and gets a token of the new kind.
        $this->expectRefused('old-key.a-random-secret');

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
        self::assertSame(hash('sha256', 'old-key.a-random-secret'), $this->refreshTokens->all()[0]->tokenHash);
        self::assertSame(0, $this->transactions->rollbacks);
    }

    public function test_NFR_05_a_token_of_the_old_format_gets_no_tolerance_either(): void
    {
        $user = $this->givenUser();
        $this->refreshTokens->add(new RefreshToken(
            $this->ids->next(), $user->id, hash('sha256', 'old-key'), hash('sha256', 'old-key.second-random-secret'), null,
            $this->now()->modify('-1 day'), $this->now(), $this->now()->modify('+60 days'), null, 1,
        ));

        // By the row this is the token before the current one, replaced this very second. But the
        // current token of the row is not one this server can make again, so there is nothing to
        // answer with.
        $this->expectRefused(FakeRefreshSecrets::token('old-key', 0));

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_NFR_05_a_token_is_only_current_when_the_stored_hash_says_so_too(): void
    {
        // The other way round: the row says generation 3, and the token of generation 3 is
        // presented, but the hash that is stored is of another token. Both must agree.
        $user = $this->givenUser();
        $this->refreshTokens->add(new RefreshToken(
            $this->ids->next(), $user->id, hash('sha256', 'key'), hash('sha256', FakeRefreshSecrets::token('key', 2)), null,
            $this->now()->modify('-1 day'), $this->now()->modify('-1 hour'), $this->now()->modify('+60 days'), null, 3,
        ));

        $this->expectRefused(FakeRefreshSecrets::token('key', 3));

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_NFR_05_presenting_a_rotated_token_again_ends_the_whole_session(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->refreshSession()->execute(self::LOGIN);
        $this->refreshSession()->execute(self::FIRST_REFRESH);
        $this->refreshSession()->execute(self::SECOND_REFRESH);
        $this->clock->advanceSeconds(30);

        // Somebody has a copy of an old token: the thief or the user, we cannot know which. It is
        // noticed however many refreshes lie in between.
        $this->expectRefused(self::LOGIN);

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
        // So the newest token of the session is dead as well, and stays dead.
        $this->expectRefused(self::THIRD_REFRESH);
        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
        // The revocation is stored although the request failed.
        self::assertSame(0, $this->transactions->rollbacks);
    }

    public function test_NFR_05_a_token_with_the_key_of_a_session_and_a_made_up_secret_ends_the_session(): void
    {
        // The key is in no place but the tokens of its session. Whoever has it has seen one of them.
        $this->givenUser();
        $this->logInAs();

        $this->expectRefused('token-1.guessed');

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
        $this->expectRefused(self::LOGIN);
    }

    public function test_NFR_05_a_reuse_ends_only_that_session(): void
    {
        $this->givenUser();
        $this->logInAs();                                       // key token-1: the phone
        $laptop = $this->logInAs()->refreshToken;               // key token-2: the laptop
        $this->refreshSession()->execute(self::LOGIN);
        $this->clock->advanceSeconds(11);
        $this->expectRefused(self::LOGIN);

        // The laptop is still signed in.
        self::assertInstanceOf(Session::class, $this->refreshSession()->execute($laptop));
    }

    public function test_NFR_05_a_refresh_token_works_until_just_before_60_days_are_over(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->clock->advanceSeconds(60 * 86_400 - 1);

        self::assertSame(self::FIRST_REFRESH, $this->refreshSession()->execute(self::LOGIN)->refreshToken);
    }

    public function test_NFR_05_a_refresh_token_expires_after_60_days(): void
    {
        $this->givenUser();
        $this->logInAs();
        $before = $this->refreshTokens->all();
        $this->clock->advanceSeconds(60 * 86_400);

        $this->expectRefused(self::LOGIN);

        // An expired token is not a sign of theft: nothing is revoked, and nothing is rotated.
        self::assertEquals($before, $this->refreshTokens->all());
    }

    public function test_NFR_05_an_unknown_token_is_refused(): void
    {
        $this->givenUser();
        $this->logInAs();
        $before = $this->refreshTokens->all();

        $this->expectRefused('token-9.secret-0-of-token-9');
        $this->expectRefused('secret-0-of-token-1');
        $this->expectRefused('token-1');
        $this->expectRefused('.secret-0-of-token-1');
        $this->expectRefused(self::LOGIN . '.more');
        $this->expectRefused('');
        // The stored hashes are not tokens.
        $this->expectRefused(hash('sha256', 'token-1') . '.' . hash('sha256', self::LOGIN));

        self::assertEquals($before, $this->refreshTokens->all());
    }

    public function test_FR_ACC_04_logout_ends_the_session_of_this_device_only(): void
    {
        $this->givenUser();
        $this->logInAs();                                       // key token-1: the phone
        $laptopToken = $this->logInAs()->refreshToken;          // key token-2: the laptop
        $this->refreshSession()->execute(self::LOGIN);          // the phone, rotated
        $this->clock->advanceSeconds(10);

        $this->logOut()->execute(self::FIRST_REFRESH);

        [$phone, $laptop] = $this->refreshTokens->all();
        self::assertEquals($this->now(), $phone->revokedAt);
        self::assertNull($laptop->revokedAt);
        $this->expectRefused(self::FIRST_REFRESH);
        // Not even the tolerance for the token before it is left: the session is over.
        $this->expectRefused(self::LOGIN);
        self::assertInstanceOf(Session::class, $this->refreshSession()->execute($laptopToken));
    }

    public function test_FR_ACC_04_logout_with_an_unknown_token_does_nothing_and_does_not_fail(): void
    {
        $this->givenUser();
        $this->logInAs();

        $this->logOut()->execute('no such token');
        $this->logOut()->execute('no.such token');
        $this->logOut()->execute('');

        self::assertNull($this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_FR_ACC_04_logout_with_an_old_token_of_the_session_ends_it_too(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->refreshSession()->execute(self::LOGIN);

        $this->logOut()->execute(self::LOGIN);

        $this->expectRefused(self::FIRST_REFRESH);
    }

    public function test_FR_ACC_04_logging_out_twice_keeps_the_first_revocation_time(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->logOut()->execute(self::LOGIN);
        $revokedAt = $this->refreshTokens->all()[0]->revokedAt;
        $this->clock->advanceSeconds(60);

        $this->logOut()->execute(self::LOGIN);
        $this->expectRefused(self::LOGIN);

        self::assertEquals($revokedAt, $this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_logout_for_an_account_that_is_gone_does_nothing_and_does_not_fail(): void
    {
        $user = $this->givenUser();
        $this->logInAs();
        $this->users->delete($user->id);

        $this->logOut()->execute(self::LOGIN);

        self::assertNull($this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_a_token_whose_account_is_gone_is_refused(): void
    {
        $user = $this->givenUser();
        $this->logInAs();
        $before = $this->refreshTokens->all();
        $this->users->delete($user->id);

        $this->expectRefused(self::LOGIN);

        self::assertEquals($before, $this->refreshTokens->all());
    }

    public function test_a_token_of_an_unconfirmed_account_is_refused(): void
    {
        // Cannot come about through login. If it ever does, it must not work.
        $user = $this->givenUser(verified: false);
        $planted = new RefreshToken(
            $this->ids->next(),
            $user->id,
            hash('sha256', 'planted'),
            hash('sha256', FakeRefreshSecrets::token('planted', 0)),
            null,
            $this->now(),
            null,
            $this->now()->modify('+60 days'),
            null,
        );
        $this->refreshTokens->add($planted);

        $this->expectRefused(FakeRefreshSecrets::token('planted', 0));

        self::assertEquals([$planted], $this->refreshTokens->all());
    }

    public function test_a_session_that_ended_while_the_request_waited_for_the_account_is_refused(): void
    {
        // The account is locked first (the one order every use case takes its locks in), and the
        // session is read again under that lock. What was true before the lock no longer counts.
        $user = $this->givenUser();
        $this->logInAs();
        $this->users->beforeLock = function () use ($user): void {
            $this->refreshTokens->revokeAllOfUser($user->id, $this->now());
        };

        $this->expectRefused(self::LOGIN);

        self::assertSame(hash('sha256', self::LOGIN), $this->refreshTokens->all()[0]->tokenHash);
        self::assertSame(0, $this->refreshTokens->all()[0]->generation);
    }

    public function test_a_session_that_was_deleted_while_the_request_waited_for_the_account_is_refused(): void
    {
        // A login on a 21st device removes the session that was unused longest, for example.
        $user = $this->givenUser();
        $this->logInAs();
        $this->users->beforeLock = function () use ($user): void {
            $this->refreshTokens->deleteAllButLastUsedOfUser($user->id, 0);
        };

        $this->expectRefused(self::LOGIN);

        self::assertSame([], $this->refreshTokens->all());
        self::assertSame(0, $this->transactions->rollbacks);
    }

    public function test_NFR_05_a_token_with_the_key_of_a_session_and_no_secret_ends_the_session_too(): void
    {
        $this->givenUser();
        $this->logInAs();

        $this->expectRefused('token-1.');

        self::assertEquals($this->now(), $this->refreshTokens->all()[0]->revokedAt);
    }

    public function test_the_account_is_locked_before_the_session_is(): void
    {
        $this->givenUser();
        $this->logInAs();
        $this->transactions->locks = [];

        $this->refreshSession()->execute(self::LOGIN);
        $this->logOut()->execute(self::FIRST_REFRESH);

        self::assertSame(['users', 'refresh_tokens', 'users', 'refresh_tokens'], $this->transactions->locks);
    }

    private function logInAs(?DeviceLabel $deviceLabel = null): Session
    {
        return $this->logIn()->execute(self::email(), self::PASSWORD, $deviceLabel, self::CLIENT);
    }

    private function expectRefused(string $token): void
    {
        try {
            $this->refreshSession()->execute($token);
            self::fail('Expected the refresh token to be refused.');
        } catch (InvalidRefreshToken $refused) {
            // The same for every reason.
            self::assertSame('The refresh token is invalid.', $refused->getMessage());
        }
    }
}
