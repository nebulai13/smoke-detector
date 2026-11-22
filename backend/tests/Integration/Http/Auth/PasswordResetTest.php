<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * POST /auth/password-reset/request and /confirm through the real application and database
 * (HLD §4.5 "Password reset", FR-ACC-05).
 */
final class PasswordResetTest extends HttpTestCase
{
    private const string NEW_PASSWORD = 'a brand new password';

    public function test_FR_ACC_05_the_request_always_answers_202_whether_or_not_the_email_has_an_account(): void
    {
        $this->givenUser('confirmed@example.org');
        $this->givenUser('unconfirmed@example.org', verified: false);

        $confirmed = $this->call('POST', '/auth/password-reset/request', ['email' => 'confirmed@example.org']);
        $unconfirmed = $this->call('POST', '/auth/password-reset/request', ['email' => 'unconfirmed@example.org']);
        $unknown = $this->call('POST', '/auth/password-reset/request', ['email' => 'nobody@example.org']);

        self::assertSame(202, $confirmed->status());
        self::assertSame(['status' => 'reset_requested'], $confirmed->json());
        // Status, every header and the body.
        self::assertSame(self::everythingAClientSees($confirmed), self::everythingAClientSees($unknown));
        self::assertSame(self::everythingAClientSees($confirmed), self::everythingAClientSees($unconfirmed));
        // Only the confirmed account gets a mail.
        self::assertCount(1, $this->mails()->sent());
        self::assertSame('reset_password', $this->mailTo('confirmed@example.org')->template);
    }

    public function test_FR_ACC_05_the_reset_link_is_mailed_in_the_users_language_and_stored_as_a_hash_for_1_hour(): void
    {
        $anna = $this->givenUser('anna@example.org', locale: Locale::En);

        $this->call('POST', '/auth/password-reset/request', ['email' => ' Anna@Example.org ']);

        $mail = $this->mailTo('anna@example.org');
        self::assertSame('New password for Smoke Detector', $mail->subject);
        $token = self::tokenIn($mail);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        self::assertStringContainsString("https://app.example.test/reset-password?token={$token}\n", $mail->text);
        self::assertSame(
            [[
                'user_id' => $anna->id->value,
                'purpose' => 'reset_password',
                'token_hash' => hash('sha256', $token),
                'expires_at' => '2026-11-17 13:00:00',
                'used_at' => null,
            ]],
            $this->sql()->rows('SELECT user_id, purpose, token_hash, expires_at, used_at FROM one_time_tokens'),
        );
        self::assertStringNotContainsString($token, $this->logs()->text() . json_encode($this->sql()->rows('SELECT * FROM one_time_tokens'), JSON_THROW_ON_ERROR));
        // Asking changes nothing: the old password still works.
        $this->logIn();
    }

    public function test_FR_ACC_05_the_link_sets_the_new_password_and_does_not_log_in(): void
    {
        $this->givenUser();
        $token = $this->requestReset();
        $this->clock()->advanceSeconds(600);

        $response = $this->call('POST', '/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD]);

        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'password_reset'], $response->json());
        self::assertFalse($response->response->hasHeader('Set-Cookie'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));
        $hash = $this->sql()->value('SELECT password_hash FROM users');
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $hash);
        self::assertSame('2026-11-17 12:10:00', $this->sql()->value('SELECT used_at FROM one_time_tokens'));

        $this->logIn(password: self::NEW_PASSWORD);
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body',
        ]));
        self::assertStringNotContainsString(self::NEW_PASSWORD, $this->logs()->text() . json_encode($this->sql()->rows('SELECT * FROM users'), JSON_THROW_ON_ERROR));
    }

    public function test_FR_ACC_05_a_successful_reset_signs_the_user_out_on_all_devices_and_nobody_else(): void
    {
        $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');
        $phoneSession = $this->logIn()->json();
        $laptopSession = $this->logIn(refreshIn: 'cookie');
        $bobSession = $this->logIn('bob@example.org')->json();
        $phone = $phoneSession['refreshToken'] ?? null;
        $laptop = $laptopSession->header('Set-Cookie');
        $bob = $bobSession['refreshToken'] ?? null;
        self::assertIsString($bob);
        $accessTokens = [$phoneSession['accessToken'] ?? null, $laptopSession->json()['accessToken'] ?? null, $bobSession['accessToken'] ?? null];
        self::assertContainsOnlyString($accessTokens);
        $token = $this->requestReset();
        // 14 minutes after the logins: every access token is still inside its 15 minutes.
        $this->clock()->advanceSeconds(840);
        foreach ($accessTokens as $accessToken) {
            self::assertSame(200, $this->call('GET', '/me', headers: ['Authorization' => "Bearer {$accessToken}"])->status());
        }

        $this->call('POST', '/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD]);

        // The refresh tokens of both devices are dead …
        self::assertSame(1, preg_match('/\Arefresh_token=([^;]+);/', $laptop, $cookie));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $phone]));
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', null, ['Cookie' => 'refresh_token=' . ($cookie[1] ?? '')]));
        self::assertSame(
            ['2026-11-17 12:14:00', '2026-11-17 12:14:00'],
            $this->sql()->column('SELECT revoked_at FROM refresh_tokens WHERE token_hash <> ?', [hash('sha256', $bob)]),
        );
        // … and so are the access tokens that were handed out before the reset, at once: on every
        // operation, reading and writing, and not only when their 15 minutes are over.
        foreach ([$accessTokens[0], $accessTokens[1]] as $accessToken) {
            $old = ['Authorization' => "Bearer {$accessToken}"];
            self::assertProblem(401, 'unauthorized', $this->call('GET', '/me', headers: $old));
            self::assertProblem(401, 'unauthorized', $this->call('GET', '/me/export', headers: $old));
            self::assertProblem(401, 'unauthorized', $this->call('PATCH', '/me', ['displayName' => 'Mallory'], $old));
            self::assertProblem(401, 'unauthorized', $this->call('DELETE', '/me', ['password' => self::NEW_PASSWORD], $old));
        }
        self::assertSame('Test User', $this->sql()->value('SELECT display_name FROM users WHERE email = ?', ['anna@example.org']));

        // Nobody else is signed out, and the user gets in again with the new password.
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refreshToken' => $bob])->status());
        self::assertSame(200, $this->call('GET', '/me', headers: ['Authorization' => "Bearer {$accessTokens[2]}"])->status());
        $again = $this->logIn(password: self::NEW_PASSWORD)->json()['accessToken'] ?? null;
        self::assertIsString($again);
        self::assertSame(200, $this->call('GET', '/me', headers: ['Authorization' => "Bearer {$again}"])->status());
    }

    public function test_FR_ACC_05_a_reset_link_works_once_and_only_the_newest_one(): void
    {
        $this->givenUser();
        $first = $this->requestReset();
        $second = $this->requestReset();

        // The older link was withdrawn by the newer request.
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/password-reset/confirm', ['token' => $first, 'password' => self::NEW_PASSWORD]));
        self::assertSame(200, $this->call('POST', '/auth/password-reset/confirm', ['token' => $second, 'password' => self::NEW_PASSWORD])->status());
        // And a link that was used is used up.
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/password-reset/confirm', ['token' => $second, 'password' => 'yet another password']));
        $this->logIn(password: self::NEW_PASSWORD);
    }

    public function test_FR_ACC_05_a_reset_link_expires_after_1_hour(): void
    {
        $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');
        $annas = $this->requestReset('anna@example.org');
        $bobs = $this->requestReset('bob@example.org');

        $this->clock()->advanceSeconds(3599);
        self::assertSame(200, $this->call('POST', '/auth/password-reset/confirm', ['token' => $annas, 'password' => self::NEW_PASSWORD])->status());

        $this->clock()->advanceSeconds(1);
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/password-reset/confirm', ['token' => $bobs, 'password' => self::NEW_PASSWORD]));
        $this->logIn('bob@example.org');
    }

    public function test_FR_ACC_05_a_wrong_an_expired_and_a_used_reset_token_give_the_same_error(): void
    {
        $this->givenUser('used@example.org');
        $this->givenUser('expired@example.org');
        $used = $this->requestReset('used@example.org');
        $this->call('POST', '/auth/password-reset/confirm', ['token' => $used, 'password' => self::NEW_PASSWORD]);
        $expired = $this->requestReset('expired@example.org');
        $this->clock()->advanceSeconds(3600);

        $responses = [];
        foreach (['q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng', $used, $expired] as $token) {
            $responses[] = $this->call('POST', '/auth/password-reset/confirm', ['token' => $token, 'password' => 'yet another password']);
        }

        foreach ($responses as $response) {
            self::assertProblem(400, 'invalid_token', $response);
            self::assertSame(self::everythingAClientSees($responses[0]), self::everythingAClientSees($response));
        }
    }

    public function test_NFR_05_a_token_of_one_kind_is_not_accepted_as_the_other(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'new@example.org']));
        $verifyToken = self::tokenIn($this->mailTo('new@example.org'));
        $this->givenUser('anna@example.org');
        $resetToken = $this->requestReset('anna@example.org');

        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/password-reset/confirm', ['token' => $verifyToken, 'password' => self::NEW_PASSWORD]));
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/verify-email', self::confirmation($resetToken)));

        // Neither was used up by the wrong endpoint.
        self::assertSame(200, $this->call('POST', '/auth/verify-email', self::confirmation($verifyToken))->status());
        self::assertSame(200, $this->call('POST', '/auth/password-reset/confirm', ['token' => $resetToken, 'password' => self::NEW_PASSWORD])->status());
    }

    public function test_FR_ACC_03_an_unconfirmed_account_cannot_be_activated_through_the_reset_flow(): void
    {
        $anna = $this->givenUser('anna@example.org', verified: false);

        // The request creates no token for it.
        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        self::assertSame([], $this->sql()->rows('SELECT * FROM one_time_tokens'));
        self::assertSame([], $this->mails()->sent());

        // And if a reset token for it existed anyway, it would not work.
        $this->sql()->execute(
            'INSERT INTO one_time_tokens (id, user_id, purpose, token_hash, expires_at, used_at) VALUES (?, ?, ?, ?, ?, NULL)',
            ['019a9279-0000-7000-8000-000000000001', $anna->id->value, 'reset_password', hash('sha256', 'planted-token'), '2026-11-17 13:00:00'],
        );
        $response = $this->call('POST', '/auth/password-reset/confirm', ['token' => 'planted-token', 'password' => self::NEW_PASSWORD]);

        self::assertProblem(400, 'invalid_token', $response);
        self::assertSame(
            [['password_hash' => TestAccounts::PASSWORD_HASH, 'email_verified_at' => null]],
            $this->sql()->rows('SELECT password_hash, email_verified_at FROM users'),
        );
        // Rolled back as a whole: the token is not even used up.
        self::assertNull($this->sql()->value('SELECT used_at FROM one_time_tokens'));
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body',
        ]));
    }

    /**
     * @return iterable<string, array{string, string|null}> The password, and the code it is rejected with (null when it is accepted).
     */
    public static function passwordLengths(): iterable
    {
        yield '9 characters' => [str_repeat('a', 9), 'too_short'];
        yield '10 characters' => [str_repeat('a', 10), null];
        yield '200 characters' => [str_repeat('ä', 200), null];
        yield '201 characters' => [str_repeat('a', 201), 'too_long'];
    }

    #[DataProvider('passwordLengths')]
    public function test_FR_ACC_05_the_new_password_has_10_to_200_characters(string $password, ?string $rejectedAs): void
    {
        $accepted = $rejectedAs === null;
        $this->givenUser();
        $token = $this->requestReset();
        $body = ['token' => $token, 'password' => $password];

        if ($accepted) {
            self::assertSame(200, $this->call('POST', '/auth/password-reset/confirm', $body)->status());
            $this->logIn(password: $password);

            return;
        }

        self::assertSame(
            [['field' => 'password', 'code' => $rejectedAs]],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/password-reset/confirm', $body))),
        );
        // The link is still good for a second try, and the old password still works.
        self::assertNull($this->sql()->value('SELECT used_at FROM one_time_tokens'));
        $this->logIn();
    }

    public function test_NFR_05_the_new_password_is_never_trimmed(): void
    {
        $this->givenUser();
        $password = '  spaces are part of it  ';

        $this->call('POST', '/auth/password-reset/confirm', ['token' => $this->requestReset(), 'password' => $password]);

        $this->logIn(password: $password);
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => trim($password), 'refreshIn' => 'body',
        ]));
    }

    public function test_requests_that_do_not_fit_the_contract_are_rejected(): void
    {
        foreach ([null, [], ['email' => 'anna'], ['email' => 42], ['email' => 'anna@example.org', 'locale' => 'de']] as $body) {
            self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/password-reset/request', $body));
            self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/resend-verification', $body));
        }
        foreach ([null, ['token' => 'abc'], ['password' => self::NEW_PASSWORD], ['token' => '', 'password' => self::NEW_PASSWORD], ['token' => 'abc', 'password' => self::NEW_PASSWORD, 'email' => 'anna@example.org']] as $body) {
            self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/password-reset/confirm', $body));
        }
        self::assertSame([], $this->sql()->rows('SELECT * FROM rate_limits'));
    }

    public function test_NFR_05_the_sixth_reset_request_from_one_client_address_within_an_hour_is_429(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(202, $this->call('POST', '/auth/password-reset/request', ['email' => "user{$i}@example.org"])->status());
        }

        $response = $this->call('POST', '/auth/password-reset/request', ['email' => 'user6@example.org']);

        self::assertProblem(429, 'rate_limited', $response);
        self::assertSame('3600', $response->header('Retry-After'));
    }

    public function test_NFR_05_the_owner_can_ask_for_a_reset_and_log_in_while_a_stranger_hammers_the_address_from_one_client(): void
    {
        // SRS OQ-11: what one client does to an address is held against that client only.
        $this->givenUser();
        $this->fromClientAddress('198.51.100.66');
        for ($i = 1; $i <= 5; $i++) {
            self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => "guess number {$i}", 'refreshIn' => 'body']));
        }
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(202, $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org'])->status());
        }
        // The stranger's client has had its attempts and its mails for this address.
        self::assertProblem(429, 'rate_limited', $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']));
        self::assertProblem(429, 'rate_limited', $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']));

        // The owner, somewhere else, is not affected by any of it.
        $this->fromClientAddress(self::CLIENT_ADDRESS);
        $this->logIn();
        $token = $this->requestReset();
        self::assertSame(200, $this->call('POST', '/auth/password-reset/confirm', ['token' => $token, 'password' => self::NEW_PASSWORD])->status());
        $this->logIn(password: self::NEW_PASSWORD);
    }

    private function requestReset(string $email = 'anna@example.org'): string
    {
        $this->mails()->clear();
        self::assertSame(202, $this->call('POST', '/auth/password-reset/request', ['email' => $email])->status());

        return self::tokenIn($this->mailTo($email));
    }

    /**
     * @return array{int, string, array<array-key, mixed>}
     */
    private static function everythingAClientSees(ApiResponse $response): array
    {
        return [$response->status(), $response->body(), $response->response->getHeaders()];
    }
}
