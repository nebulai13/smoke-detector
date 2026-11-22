<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Auth;

use SmokeDetector\Http\Auth\RefreshCookie;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Auth\RefreshSecrets;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * POST /auth/refresh and POST /auth/logout through the real application and database: rotation,
 * reuse detection, the two ways a refresh token travels (HLD §4.5, FR-ACC-04, NFR-05, NFR-06).
 */
final class SessionTest extends HttpTestCase
{
    public function test_FR_ACC_04_a_refresh_token_is_exchanged_for_a_new_token_pair(): void
    {
        $anna = $this->givenUser();
        $login = $this->logIn()->json();
        $this->clock()->advanceSeconds(1200);

        $response = $this->call('POST', '/auth/refresh', ['refreshToken' => $login['refreshToken']]);

        self::assertSame(200, $response->status());
        $session = $response->json();
        self::assertSame(['accessToken', 'tokenType', 'expiresIn', 'refreshToken'], array_keys($session));
        self::assertSame(900, $session['expiresIn']);
        self::assertNotSame($login['refreshToken'], $session['refreshToken']);
        self::assertNotSame($login['accessToken'], $session['accessToken']);
        self::assertIsString($session['accessToken']);
        // The new access token is for the same user and counts from now.
        $claims = self::claims($session['accessToken']);
        self::assertSame($anna->id->value, $claims['sub'] ?? null);
        self::assertSame($this->clock()->now()->getTimestamp() + 900, $claims['exp'] ?? null);
    }

    public function test_NFR_05_a_refresh_replaces_the_token_in_the_row_of_its_login_and_stores_only_hashes(): void
    {
        $this->givenUser();
        $old = $this->refreshTokenOf($this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'deviceLabel' => 'Laptop', 'refreshIn' => 'body',
        ]));
        $session = $this->sql()->value('SELECT id FROM refresh_tokens');
        $this->clock()->advanceSeconds(3600);

        $new = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $old]));

        // The same session: the key (before the dot) stays, the secret is new.
        self::assertSame(explode('.', $old)[0], explode('.', $new)[0]);
        self::assertNotSame($old, $new);
        self::assertSame(
            [[
                'id' => $session,
                'key_hash' => hash('sha256', explode('.', $old)[0]),
                'token_hash' => hash('sha256', $new),
                'device_label' => 'Laptop',
                'created_at' => '2026-11-17 12:00:00',
                'rotated_at' => '2026-11-17 13:00:00',
                // 60 days from the refresh.
                'expires_at' => '2027-01-16 13:00:00',
                'revoked_at' => null,
                // The second token of the session: the login's was generation 0.
                'generation' => 1,
            ]],
            $this->sql()->rows('SELECT id, key_hash, token_hash, device_label, created_at, rotated_at, expires_at, revoked_at, generation FROM refresh_tokens'),
        );
        // Neither token is stored as it is, and neither is the key or a secret.
        $stored = json_encode($this->sql()->rows('SELECT * FROM refresh_tokens'), JSON_THROW_ON_ERROR) . $this->logs()->text();
        foreach ([$old, $new, explode('.', $old)[0], explode('.', $new)[1]] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
    }

    public function test_NFR_05_refreshing_again_and_again_does_not_add_rows(): void
    {
        // One login, 50 refreshes, one row: the table grows with logins only.
        $this->givenUser();
        $token = $this->refreshTokenOf($this->logIn());

        for ($i = 0; $i < 50; $i++) {
            $token = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $token]));
        }

        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));
    }

    public function test_NFR_05_a_user_keeps_at_most_20_logins(): void
    {
        $anna = $this->givenUser();
        $bob = $this->givenUser('bob@example.org');
        $bobs = $this->givenSession(1, $bob, 'bobs-key');
        $annas = [];
        for ($i = 1; $i <= 20; $i++) {
            $annas[$i] = $this->givenSession(100 + $i, $anna, "key-{$i}", sprintf('2026-11-16 %02d:00:00', $i));
        }

        $newest = $this->refreshTokenOf($this->logIn());

        // The login that was unused for the longest time made room. It is simply gone.
        self::assertSame(20, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ?', [$anna->id->value]));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $annas[1]]));
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $annas[2]])->status());
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $newest])->status());
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $bobs])->status());
    }

    public function test_NFR_05_a_rotated_refresh_token_cannot_be_used_again_once_the_ten_seconds_are_over(): void
    {
        $this->givenUser();
        $first = $this->refreshTokenOf($this->logIn());
        $second = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $first]));
        $this->clock()->advanceSeconds(11);

        $again = $this->call('POST', '/auth/refresh', ['refreshToken' => $first]);

        self::assertSame(
            ['type' => 'invalid_refresh_token', 'title' => 'Invalid refresh token', 'status' => 401, 'detail' => 'Log in again.'],
            self::assertProblem(401, 'invalid_refresh_token', $again),
        );
        self::assertArrayNotHasKey('accessToken', $again->json());
        // Theft detection as before: the session is over, also for its current token.
        self::assertSame([['revoked_at' => '2026-11-17 12:00:11', 'generation' => 1]], $this->sql()->rows('SELECT revoked_at, generation FROM refresh_tokens'));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $second]));
    }

    public function test_FR_ACC_04_a_refresh_that_is_repeated_within_ten_seconds_gets_the_same_new_refresh_token(): void
    {
        // SRS OQ-12: two browser tabs refresh at the same moment, or the answer to a refresh got
        // lost and the client tries again. Both present the token that was just replaced.
        $anna = $this->givenUser();
        $old = $this->refreshTokenOf($this->logIn());
        $this->clock()->advanceSeconds(1200);
        $first = $this->call('POST', '/auth/refresh', ['refreshToken' => $old]);
        $row = $this->sql()->rows('SELECT * FROM refresh_tokens');
        $this->clock()->advanceSeconds(10);

        $second = $this->call('POST', '/auth/refresh', ['refreshToken' => $old]);

        // Answered like a successful refresh, with the SAME new refresh token as the first answer:
        // whichever answer arrives last, the client holds the current token.
        self::assertSame(200, $second->status());
        self::assertSame(['accessToken', 'tokenType', 'expiresIn', 'refreshToken'], array_keys($second->json()));
        self::assertSame($this->refreshTokenOf($first), $this->refreshTokenOf($second));
        self::assertSame(array_keys($first->response->getHeaders()), array_keys($second->response->getHeaders()));
        // The access token is a fresh one, for the same user, counted from now.
        $accessToken = $second->json()['accessToken'] ?? null;
        self::assertIsString($accessToken);
        self::assertNotSame($first->json()['accessToken'] ?? null, $accessToken);
        self::assertSame([$anna->id->value, $this->clock()->now()->getTimestamp() + 900], [self::claims($accessToken)['sub'] ?? null, self::claims($accessToken)['exp'] ?? null]);
        // The session rotated once: the second request changed nothing in its row.
        self::assertSame($row, $this->sql()->rows('SELECT * FROM refresh_tokens'));
        self::assertSame(
            ['rotated_at' => '2026-11-17 12:20:00', 'expires_at' => '2027-01-16 12:20:00', 'revoked_at' => null, 'generation' => 1],
            array_intersect_key($row[0] ?? [], ['generation' => 0, 'rotated_at' => 0, 'expires_at' => 0, 'revoked_at' => 0]),
        );
        // The token both answers carried works like any current token, and only its hash is stored.
        self::assertStringNotContainsString($this->refreshTokenOf($second), json_encode($row, JSON_THROW_ON_ERROR) . $this->logs()->text());
        $this->clock()->advanceSeconds(60);
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $this->refreshTokenOf($second)])->status());
        self::assertSame(2, $this->sql()->value('SELECT generation FROM refresh_tokens'));
    }

    public function test_NFR_05_a_token_two_generations_old_ends_the_session_even_within_ten_seconds(): void
    {
        $this->givenUser();
        $first = $this->refreshTokenOf($this->logIn());
        $second = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $first]));
        $third = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $second]));
        $this->clock()->advanceSeconds(3);

        // Only the one token before the current one is tolerated.
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $first]));

        self::assertSame('2026-11-17 12:00:03', $this->sql()->value('SELECT revoked_at FROM refresh_tokens'));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $second]));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $third]));
    }

    public function test_NFR_06_in_cookie_mode_a_repeated_refresh_sets_the_same_cookie_again(): void
    {
        $this->givenUser();
        $cookie = self::cookieOf($this->logIn(refreshIn: 'cookie'));

        // Two tabs of the web app share the cookie and refresh at the same moment.
        $first = $this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$cookie}"]);
        $this->clock()->advanceSeconds(9);
        $second = $this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$cookie}"]);

        self::assertSame(200, $second->status());
        // Both answers set the same cookie, so it does not matter which one the browser stores last.
        self::assertSame($first->header('Set-Cookie'), $second->header('Set-Cookie'));
        self::assertSame(
            'refresh_token=' . self::cookieOf($first) . '; Max-Age=5184000; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict',
            $second->header('Set-Cookie'),
        );
        self::assertNotSame($cookie, self::cookieOf($second));
        self::assertSame(['accessToken', 'tokenType', 'expiresIn'], array_keys($second->json()));
        self::assertStringNotContainsString(self::cookieOf($second), $second->body());
        self::assertSame([['generation' => 1, 'revoked_at' => null]], $this->sql()->rows('SELECT generation, revoked_at FROM refresh_tokens'));

        // After the ten seconds the old cookie ends the session, and the browser is told to drop it.
        $this->clock()->advanceSeconds(2);
        $late = $this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$cookie}"]);
        self::assertProblem(401, 'invalid_refresh_token', $late);
        self::assertSame(RefreshCookie::CLEARED, $late->header('Set-Cookie'));
        self::assertNotNull($this->sql()->value('SELECT revoked_at FROM refresh_tokens'));
    }

    public function test_NFR_05_the_tolerance_does_not_let_body_and_cookie_be_mixed(): void
    {
        $this->givenUser();
        $old = self::cookieOf($this->logIn(refreshIn: 'cookie'));
        $new = self::cookieOf($this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$old}"]));
        $row = $this->sql()->rows('SELECT * FROM refresh_tokens');

        // The token that was just replaced in the body, the current one in the cookie, and the other way round.
        foreach ([[$old, $new], [$new, $old], [$old, $old]] as [$inBody, $inCookie]) {
            $response = $this->call('POST', '/auth/refresh', ['refreshToken' => $inBody], ['Cookie' => "refresh_token={$inCookie}"]);

            self::assertSame(
                [['field' => 'refreshToken', 'code' => 'ambiguous']],
                self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)),
            );
            self::assertFalse($response->response->hasHeader('Set-Cookie'));
        }
        // Nothing was rotated and nothing was ended.
        self::assertSame($row, $this->sql()->rows('SELECT * FROM refresh_tokens'));
    }

    public function test_FR_ACC_04_after_a_logout_the_token_that_was_just_replaced_is_refused_too(): void
    {
        $this->givenUser();
        $old = $this->refreshTokenOf($this->logIn());
        $new = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $old]));

        // Logged out a second after the refresh: the session is over, tolerance or not.
        $this->clock()->advanceSeconds(1);
        self::assertSame(204, $this->call('POST', '/auth/logout', ['refreshToken' => $new])->status());

        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $old]));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $new]));
        self::assertSame('2026-11-17 12:00:01', $this->sql()->value('SELECT revoked_at FROM refresh_tokens'));
    }

    public function test_FR_ACC_04_logout_with_the_token_that_was_just_replaced_ends_the_session_as_before(): void
    {
        $this->givenUser();
        $old = $this->refreshTokenOf($this->logIn());
        $new = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $old]));

        self::assertSame(204, $this->call('POST', '/auth/logout', ['refreshToken' => $old])->status());

        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $new]));
    }

    public function test_FR_ACC_05_after_a_password_reset_the_token_that_was_just_replaced_is_refused_too(): void
    {
        $this->givenUser();
        $old = $this->refreshTokenOf($this->logIn());
        $new = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $old]));
        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);

        // In the same second as the refresh.
        $this->call('POST', '/auth/password-reset/confirm', ['token' => self::tokenIn($this->mailTo('anna@example.org')), 'password' => 'a brand new password']);

        // Signed out on all devices means all tokens, at once.
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $old]));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $new]));
    }

    public function test_NFR_05_a_refresh_token_of_the_old_format_is_refused_cleanly(): void
    {
        // Sessions from before the secret of a token was derived from its generation carry a random
        // secret. The row is there, the token is not one this server makes.
        $anna = $this->givenUser();
        $this->service(RefreshTokenRepository::class)->add(TestData::session(1, $anna->id, 'q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng', 'a-random-secret-of-the-old-kind-0123456789a'));
        $this->service(RefreshTokenRepository::class)->add(TestData::session(2, $anna->id, 'r83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng', 'a-random-secret-of-the-old-kind-0123456789a'));
        $oldBody = 'q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng.a-random-secret-of-the-old-kind-0123456789a';
        $oldCookie = 'r83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng.a-random-secret-of-the-old-kind-0123456789a';

        $inBody = $this->call('POST', '/auth/refresh', ['refreshToken' => $oldBody]);
        $inCookie = $this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$oldCookie}"]);
        $unknown = $this->call('POST', '/auth/refresh', ['refreshToken' => 'x83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng.a-random-secret-of-the-old-kind-0123456789a']);

        // The one refusal every other worthless token gets, not an error of its own and not a 500.
        self::assertProblem(401, 'invalid_refresh_token', $inBody);
        self::assertSame(self::everythingAClientSees($unknown), self::everythingAClientSees($inBody));
        self::assertProblem(401, 'invalid_refresh_token', $inCookie);
        self::assertSame(RefreshCookie::CLEARED, $inCookie->header('Set-Cookie'));
        // The sessions are over. A new login gives a token of the new kind.
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens WHERE revoked_at IS NULL'));
        self::assertSame([], $this->logs()->records);
        $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $this->refreshTokenOf($this->logIn())]));
    }

    public function test_NFR_05_reusing_a_rotated_token_ends_the_whole_session_and_only_that_session(): void
    {
        $this->givenUser();
        $phone1 = $this->refreshTokenOf($this->logIn());
        $laptop = $this->refreshTokenOf($this->logIn());
        $phone2 = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $phone1]));
        $phone3 = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $phone2]));
        $this->clock()->advanceSeconds(60);

        // A copy of an old token shows up: a thief, or the user after a thief. Either way the
        // session of that device is over for everybody who holds one of its tokens. It is noticed
        // although two refreshes lie in between.
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $phone1]));

        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $phone3]));
        self::assertSame(
            ['2026-11-17 12:01:00'],
            $this->sql()->column('SELECT revoked_at FROM refresh_tokens WHERE token_hash = ?', [hash('sha256', $phone3)]),
        );
        // The revocation was stored although the request was refused, and the other device is untouched.
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $laptop])->status());
    }

    public function test_NFR_05_a_revoked_token_presented_again_stays_refused(): void
    {
        $this->givenUser();
        $token = $this->refreshTokenOf($this->logIn());
        $this->call('POST', '/auth/logout', ['refreshToken' => $token]);

        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $token]));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $token]));
    }

    public function test_NFR_05_a_refresh_token_expires_after_60_days(): void
    {
        $this->givenUser();
        $token = $this->refreshTokenOf($this->logIn());
        $other = $this->refreshTokenOf($this->logIn());

        $this->clock()->advanceSeconds(60 * 86_400 - 1);
        $rotated = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $token]));

        $this->clock()->advanceSeconds(1);
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $other]));
        // Expiry is not theft: nothing was revoked, and the token that was rotated in time lives on.
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens WHERE revoked_at IS NOT NULL'));
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $rotated])->status());
    }

    public function test_NFR_05_every_refusal_of_a_refresh_token_looks_the_same(): void
    {
        $this->givenUser();
        $used = $this->refreshTokenOf($this->logIn());
        $this->call('POST', '/auth/refresh', ['refreshToken' => $used]);
        // (More than the ten seconds in which a repeated refresh is still answered.)
        $this->clock()->advanceSeconds(11);
        $revoked = $this->refreshTokenOf($this->logIn());
        $this->call('POST', '/auth/logout', ['refreshToken' => $revoked]);
        $expired = $this->refreshTokenOf($this->logIn());
        $this->sql()->execute('UPDATE refresh_tokens SET expires_at = ? WHERE token_hash = ?', ['2026-11-17 12:00:00', hash('sha256', $expired)]);

        $responses = [
            $this->call('POST', '/auth/refresh', ['refreshToken' => 'q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng']),
            $this->call('POST', '/auth/refresh', ['refreshToken' => $used]),
            $this->call('POST', '/auth/refresh', ['refreshToken' => $revoked]),
            $this->call('POST', '/auth/refresh', ['refreshToken' => $expired]),
            // The hash of a valid token is not a token.
            $this->call('POST', '/auth/refresh', ['refreshToken' => hash('sha256', $this->refreshTokenOf($this->logIn()))]),
        ];

        foreach ($responses as $response) {
            self::assertProblem(401, 'invalid_refresh_token', $response);
            self::assertSame(self::everythingAClientSees($responses[0]), self::everythingAClientSees($response));
        }
    }

    public function test_NFR_06_the_web_app_refreshes_with_the_cookie_and_gets_the_new_token_in_the_cookie_only(): void
    {
        $this->givenUser();
        $cookie = self::cookieOf($this->logIn(refreshIn: 'cookie'));

        // No body at all: the browser sends the cookie by itself.
        $response = $this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$cookie}"]);

        self::assertSame(200, $response->status());
        self::assertSame(['accessToken', 'tokenType', 'expiresIn'], array_keys($response->json()));
        $new = self::cookieOf($response);
        self::assertNotSame($cookie, $new);
        self::assertSame(
            "refresh_token={$new}; Max-Age=5184000; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict",
            $response->header('Set-Cookie'),
        );
        self::assertStringNotContainsString($new, $response->body());
        // The new cookie value works, and is replaced in turn.
        $next = $this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$new}"]);
        self::assertNotSame($new, self::cookieOf($next));
    }

    public function test_NFR_06_a_refused_cookie_is_removed_from_the_browser(): void
    {
        $response = $this->call('POST', '/auth/refresh', null, ['Cookie' => 'refresh_token=q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng']);

        self::assertProblem(401, 'invalid_refresh_token', $response);
        self::assertSame(RefreshCookie::CLEARED, $response->header('Set-Cookie'));
    }

    public function test_NFR_06_a_refresh_in_body_mode_never_sets_or_clears_a_cookie(): void
    {
        $this->givenUser();
        $token = $this->refreshTokenOf($this->logIn());

        $accepted = $this->call('POST', '/auth/refresh', ['refreshToken' => $token]);
        $repeated = $this->call('POST', '/auth/refresh', ['refreshToken' => $token]);
        $this->clock()->advanceSeconds(11);
        $refused = $this->call('POST', '/auth/refresh', ['refreshToken' => $token]);

        self::assertSame(200, $accepted->status());
        self::assertFalse($accepted->response->hasHeader('Set-Cookie'));
        self::assertSame(200, $repeated->status());
        self::assertFalse($repeated->response->hasHeader('Set-Cookie'));
        self::assertProblem(401, 'invalid_refresh_token', $refused);
        self::assertFalse($refused->response->hasHeader('Set-Cookie'));
    }

    public function test_NFR_05_a_refresh_without_any_token_is_refused_like_a_wrong_one(): void
    {
        foreach ([null, []] as $body) {
            $response = $this->call('POST', '/auth/refresh', $body);

            self::assertProblem(401, 'invalid_refresh_token', $response);
        }
        // Other cookies do not count.
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', null, ['Cookie' => 'session=abc; other=refresh_token']));
    }

    public function test_NFR_05_a_token_in_the_body_and_in_the_cookie_at_once_is_rejected_and_uses_up_neither(): void
    {
        $this->givenUser();
        $body = $this->refreshTokenOf($this->logIn());
        $cookie = self::cookieOf($this->logIn(refreshIn: 'cookie'));

        foreach (['/auth/refresh', '/auth/logout'] as $path) {
            $response = $this->call('POST', $path, ['refreshToken' => $body], ['Cookie' => "refresh_token={$cookie}"]);

            self::assertSame(
                [['field' => 'refreshToken', 'code' => 'ambiguous']],
                self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)),
            );
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens WHERE rotated_at IS NOT NULL OR revoked_at IS NOT NULL'));
    }

    public function test_a_refresh_request_that_does_not_fit_the_contract_is_rejected(): void
    {
        foreach ([['refreshToken' => ''], ['refreshToken' => 42], ['refreshToken' => null], ['refreshToken' => str_repeat('a', 201)], ['token' => 'abc']] as $body) {
            self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/refresh', $body));
            self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/logout', $body));
        }
    }

    public function test_FR_ACC_04_logout_ends_the_session_of_this_device_and_leaves_other_devices_signed_in(): void
    {
        $this->givenUser();
        $phone = $this->refreshTokenOf($this->logIn());
        $laptop = $this->refreshTokenOf($this->logIn());
        $phone = $this->refreshTokenOf($this->call('POST', '/auth/refresh', ['refreshToken' => $phone]));
        $this->clock()->advanceSeconds(30);

        $response = $this->call('POST', '/auth/logout', ['refreshToken' => $phone]);

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        // The phone's session is revoked, the laptop's is not.
        self::assertSame(
            ['2026-11-17 12:00:30', null],
            $this->sql()->column('SELECT revoked_at FROM refresh_tokens ORDER BY (token_hash = ?)', [hash('sha256', $laptop)]),
        );
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $phone]));
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $laptop])->status());
    }

    public function test_FR_ACC_04_logout_does_not_touch_the_sessions_of_other_users(): void
    {
        $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');
        $anna = $this->refreshTokenOf($this->logIn('anna@example.org'));
        $bob = $this->refreshTokenOf($this->logIn('bob@example.org'));

        $this->call('POST', '/auth/logout', ['refreshToken' => $anna]);

        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $bob])->status());
    }

    public function test_NFR_06_logout_in_body_mode_never_touches_a_cookie(): void
    {
        $this->givenUser();

        $response = $this->call('POST', '/auth/logout', ['refreshToken' => $this->refreshTokenOf($this->logIn())]);

        self::assertSame(204, $response->status());
        self::assertFalse($response->response->hasHeader('Set-Cookie'));
    }

    public function test_NFR_06_logout_of_the_web_app_revokes_the_cookie_token_and_clears_the_cookie(): void
    {
        $this->givenUser();
        $cookie = self::cookieOf($this->logIn(refreshIn: 'cookie'));

        $response = $this->call('POST', '/auth/logout', null, ['Cookie' => "refresh_token={$cookie}"]);

        self::assertSame(204, $response->status());
        self::assertSame(
            'refresh_token=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict',
            $response->header('Set-Cookie'),
        );
        self::assertSame('2026-11-17 12:00:00', $this->sql()->value('SELECT revoked_at FROM refresh_tokens'));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', null, ['Cookie' => "refresh_token={$cookie}"]));
    }

    public function test_FR_ACC_04_logout_always_answers_204_and_tells_nothing_about_the_token(): void
    {
        $this->givenUser();
        $valid = $this->refreshTokenOf($this->logIn());

        $responses = [
            $this->call('POST', '/auth/logout', ['refreshToken' => $valid]),
            // Again, with the token that is revoked now.
            $this->call('POST', '/auth/logout', ['refreshToken' => $valid]),
            $this->call('POST', '/auth/logout', ['refreshToken' => 'q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng']),
        ];
        foreach ($responses as $response) {
            self::assertSame(self::everythingAClientSees($responses[0]), self::everythingAClientSees($response));
            self::assertSame(204, $response->status());
        }

        // Without any token there is nothing to revoke, and nothing to tell the browser.
        $withoutToken = $this->call('POST', '/auth/logout');
        self::assertSame(204, $withoutToken->status());
        self::assertFalse($withoutToken->response->hasHeader('Set-Cookie'));
    }

    public function test_NFR_06_a_request_that_brings_no_refresh_cookie_cannot_make_the_browser_drop_it(): void
    {
        // A page of another site can make the browser POST here, but SameSite=Strict keeps the cookie
        // out of that request. If the answer cleared the cookie anyway, any site could log our users out.
        foreach ([[], ['Cookie' => 'theme=dark; other=1']] as $headers) {
            $logout = $this->call('POST', '/auth/logout', null, $headers);
            $refresh = $this->call('POST', '/auth/refresh', null, $headers);

            self::assertSame(204, $logout->status());
            self::assertFalse($logout->response->hasHeader('Set-Cookie'));
            self::assertProblem(401, 'invalid_refresh_token', $refresh);
            self::assertFalse($refresh->response->hasHeader('Set-Cookie'));
        }
    }

    public function test_NFR_06_a_refresh_cookie_that_cannot_be_used_is_cleared(): void
    {
        $this->givenUser();
        $cookie = self::cookieOf($this->logIn(refreshIn: 'cookie'));

        foreach (['refresh_token=not%20a%20token', "refresh_token={$cookie}; refresh_token=planted"] as $header) {
            $logout = $this->call('POST', '/auth/logout', null, ['Cookie' => $header]);
            $refresh = $this->call('POST', '/auth/refresh', null, ['Cookie' => $header]);

            self::assertSame(204, $logout->status());
            self::assertSame(RefreshCookie::CLEARED, $logout->header('Set-Cookie'));
            self::assertProblem(401, 'invalid_refresh_token', $refresh);
            self::assertSame(RefreshCookie::CLEARED, $refresh->header('Set-Cookie'));
        }
        // An ambiguous cookie ends no session: nothing says which value is the user's.
        self::assertNull($this->sql()->value('SELECT revoked_at FROM refresh_tokens'));
    }

    public function test_FR_ACC_04_logout_and_refresh_need_no_access_token(): void
    {
        // They are called exactly when the access token has expired.
        $this->givenUser();
        $token = $this->refreshTokenOf($this->logIn());
        $this->clock()->advanceSeconds(3600);

        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $token], ['Authorization' => 'Bearer expired.or.garbage'])->status());
        self::assertSame(204, $this->call('POST', '/auth/logout', ['refreshToken' => $token])->status());
    }

    /**
     * Stores a session directly, as a login would, and returns its refresh token: the key and the
     * secret this application makes for the key's first token.
     */
    private function givenSession(int $number, User $user, string $key, string $createdAt = '2026-11-17 12:00:00'): string
    {
        $secret = $this->service(RefreshSecrets::class)->secret($key, 0);
        $this->service(RefreshTokenRepository::class)->add(TestData::session($number, $user->id, $key, $secret, createdAt: $createdAt));

        return "{$key}.{$secret}";
    }

    private function refreshTokenOf(ApiResponse $response): string
    {
        self::assertSame(200, $response->status(), $response->body());
        $token = $response->json()['refreshToken'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private static function cookieOf(ApiResponse $response): string
    {
        self::assertSame(200, $response->status(), $response->body());
        self::assertSame(1, preg_match('/\Arefresh_token=([A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43});/', $response->header('Set-Cookie'), $match), $response->header('Set-Cookie'));

        return $match[1] ?? '';
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function claims(string $jwt): array
    {
        $json = json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/'), true), true);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @return array{int, string, array<array-key, mixed>}
     */
    private static function everythingAClientSees(ApiResponse $response): array
    {
        return [$response->status(), $response->body(), $response->response->getHeaders()];
    }
}
