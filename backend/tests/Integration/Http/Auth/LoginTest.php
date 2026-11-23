<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Auth;

use DateTimeImmutable;
use SmokeDetector\Application\Auth\PasswordHasher;
use SmokeDetector\Infrastructure\Auth\Argon2idPasswordHasher;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * POST /auth/login through the real application and database (HLD §4.5 "Login and tokens",
 * FR-ACC-04, NFR-05, NFR-06).
 */
final class LoginTest extends HttpTestCase
{
    public function test_FR_ACC_04_email_and_password_give_an_access_token_and_a_refresh_token(): void
    {
        $anna = $this->givenUser();

        $response = $this->call('POST', '/auth/login', [
            'email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'deviceLabel' => 'Annas iPhone', 'refreshIn' => 'body',
        ]);

        self::assertSame(200, $response->status());
        $session = $response->json();
        self::assertSame(['accessToken', 'tokenType', 'expiresIn', 'refreshToken'], array_keys($session));
        self::assertSame('Bearer', $session['tokenType']);
        self::assertSame(900, $session['expiresIn']);
        self::assertIsString($session['refreshToken']);
        // The key of the session and the secret of this token: 32 random bytes each, base64url.
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}\z/', $session['refreshToken']);

        self::assertSame(
            [[
                'user_id' => $anna->id->value,
                // Only hashes are stored: of the key (the part before the dot) and of the whole token.
                'key_hash' => hash('sha256', explode('.', $session['refreshToken'])[0]),
                'token_hash' => hash('sha256', $session['refreshToken']),
                'device_label' => 'Annas iPhone',
                'created_at' => '2026-11-17 12:00:00',
                'rotated_at' => null,
                // 60 days.
                'expires_at' => '2027-01-16 12:00:00',
                'revoked_at' => null,
            ]],
            $this->sql()->rows('SELECT user_id, key_hash, token_hash, device_label, created_at, rotated_at, expires_at, revoked_at FROM refresh_tokens'),
        );
    }

    public function test_NFR_05_the_access_token_is_an_hs256_jwt_for_15_minutes_with_sub_iat_exp_jti_and_ver(): void
    {
        $anna = $this->givenUser();

        $token = $this->logIn()->json()['accessToken'] ?? null;

        self::assertIsString($token);
        [$header, $payload, $signature] = explode('.', $token);
        self::assertSame(['typ' => 'JWT', 'alg' => 'HS256'], self::decode($header));
        $claims = self::decode($payload);
        self::assertSame(['sub', 'iat', 'exp', 'jti', 'ver'], array_keys($claims));
        self::assertSame(0, $claims['ver']);
        self::assertSame($anna->id->value, $claims['sub']);
        // The time of the application's clock, not of the machine.
        self::assertSame($this->clock()->now()->getTimestamp(), $claims['iat']);
        self::assertSame($this->clock()->now()->getTimestamp() + 900, $claims['exp']);
        self::assertIsString($claims['jti']);
        self::assertNotSame('', $claims['jti']);
        // Signed with the secret of the settings.
        self::assertSame(
            rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $this->settings()->jwtSecret->reveal(), true)), '+/', '-_'), '='),
            $signature,
        );
    }

    public function test_NFR_05_the_refresh_token_is_stored_as_a_sha256_hash_only(): void
    {
        $this->givenUser();

        $session = $this->logIn()->json();

        self::assertIsString($session['refreshToken']);
        self::assertIsString($session['accessToken']);
        $stored = $this->everythingStoredAndLogged();
        self::assertStringNotContainsString($session['refreshToken'], $stored);
        self::assertStringNotContainsString($session['accessToken'], $stored);
        self::assertStringNotContainsString(TestAccounts::PASSWORD, $stored);
        self::assertStringContainsString(hash('sha256', $session['refreshToken']), $stored);
    }

    public function test_NFR_06_in_body_mode_no_cookie_is_set(): void
    {
        $this->givenUser();

        $response = $this->logIn(refreshIn: 'body');

        self::assertFalse($response->response->hasHeader('Set-Cookie'));
        self::assertArrayHasKey('refreshToken', $response->json());
    }

    public function test_NFR_06_in_cookie_mode_the_refresh_token_is_in_the_httponly_cookie_and_never_in_the_body(): void
    {
        $this->givenUser();

        $response = $this->logIn(refreshIn: 'cookie');

        self::assertSame(['accessToken', 'tokenType', 'expiresIn'], array_keys($response->json()));
        $cookies = $response->response->getHeader('Set-Cookie');
        self::assertCount(1, $cookies);
        self::assertSame(
            1,
            preg_match('~\Arefresh_token=([A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}); Max-Age=5184000; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict\z~', $cookies[0], $match),
            $cookies[0],
        );
        // The token of the cookie is the one that is stored (as a hash), and it is nowhere in the body.
        self::assertSame(hash('sha256', $match[1] ?? ''), $this->sql()->value('SELECT token_hash FROM refresh_tokens'));
        self::assertStringNotContainsString($match[1] ?? '', $response->body());
    }

    public function test_FR_ACC_04_an_unknown_email_and_a_wrong_password_give_the_same_answer(): void
    {
        $this->givenUser('anna@example.org');

        $wrongPassword = $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => 'not the password', 'refreshIn' => 'body']);
        $unknownEmail = $this->call('POST', '/auth/login', ['email' => 'nobody@example.org', 'password' => 'not the password', 'refreshIn' => 'body']);

        self::assertProblem(401, 'invalid_credentials', $wrongPassword);
        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401, 'detail' => 'The email address or the password is wrong.'],
            $wrongPassword->json(),
        );
        // Status, every header and the body.
        self::assertSame(self::everythingAClientSees($wrongPassword), self::everythingAClientSees($unknownEmail));
        self::assertFalse($wrongPassword->response->hasHeader('Set-Cookie'));
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));
    }

    public function test_NFR_05_a_password_verification_runs_for_an_unknown_email_too(): void
    {
        $this->givenUser('anna@example.org');
        $hasher = $this->service(PasswordHasher::class);
        self::assertInstanceOf(Argon2idPasswordHasher::class, $hasher);

        // Measured, because this is about time: the unknown address must not be answered without
        // the work of a password check. Argon2id takes tens of milliseconds, the rest of a request
        // well under one. A busy machine can spoil one measurement (the bare check runs slow, a
        // login right after it fast), so the best of three counts.
        $failure = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->fromClientAddress("198.51.100.{$attempt}");
            $known = $this->secondsFor(fn () => $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => 'not the password', 'refreshIn' => 'body']));
            $unknown = $this->secondsFor(fn () => $this->call('POST', '/auth/login', ['email' => "nobody{$attempt}@example.org", 'password' => 'not the password', 'refreshIn' => 'body']));
            $check = $this->secondsFor(static fn () => $hasher->verify('not the password', $hasher->dummyHash()));
            if ($unknown > $check * 0.5 && $known > $check * 0.5) {
                return;
            }
            $failure = sprintf('known %.1f ms, unknown %.1f ms, one password check %.1f ms', $known * 1000, $unknown * 1000, $check * 1000);
        }

        self::fail('A login was answered without the work of a password check in three tries. Last: ' . $failure);
    }

    public function test_FR_ACC_01_an_unconfirmed_registration_answers_a_login_exactly_like_no_account_and_like_a_wrong_password(): void
    {
        $this->givenUser('pending@example.org', verified: false);
        $this->givenUser('confirmed@example.org');
        $attempt = fn (string $email, string $password, string $client): ApiResponse => $this->loginFrom($client, $email, $password, 'cookie');

        $pending = $attempt('pending@example.org', TestAccounts::PASSWORD, '198.51.100.1');
        $pendingWrongPassword = $attempt('pending@example.org', 'not the password', '198.51.100.2');
        $wrongPassword = $attempt('confirmed@example.org', 'not the password', '198.51.100.3');
        $unknown = $attempt('nobody@example.org', TestAccounts::PASSWORD, '198.51.100.4');

        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401, 'detail' => 'The email address or the password is wrong.'],
            self::assertProblem(401, 'invalid_credentials', $pending),
        );
        // Status, every header and the body: nothing tells the four apart.
        foreach ([$pendingWrongPassword, $wrongPassword, $unknown] as $other) {
            self::assertSame(self::everythingAClientSees($pending), self::everythingAClientSees($other));
        }
        self::assertFalse($pending->response->hasHeader('Set-Cookie'));
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));
        // And each of them counts as one failed login: for its address, for its client and for the
        // pair of the two. (Three addresses, one of them tried twice, and four clients.)
        self::assertSame(
            ['login.address' => [2, 1, 1], 'login.client' => [1, 1, 1, 1], 'login.pair' => [1, 1, 1, 1]],
            array_intersect_key($this->hitsByRule(), ['login.address' => 0, 'login.client' => 0, 'login.pair' => 0]),
        );
    }

    public function test_FR_ACC_01_registering_an_address_and_logging_in_does_not_tell_whether_it_had_an_account(): void
    {
        // What a stranger can do to find out who is registered: register the address with a password
        // of their own and log in with it. A confirmed account is left alone by the registration, so
        // the password is wrong for it. For any other address the password is right, for a
        // registration that nobody confirmed. The two must be answered alike, down to the limit.
        $this->givenUser('confirmed@example.org');
        $answers = [];
        foreach (['confirmed@example.org' => '198.51.100.1', 'nobody@example.org' => '198.51.100.2'] as $address => $client) {
            $this->fromClientAddress($client);
            $registered = $this->call('POST', '/auth/register', TestAccounts::registration(['email' => $address, 'password' => 'the strangers own password']));
            $answers[$address][] = self::everythingAClientSees($registered);
            for ($i = 1; $i <= 7; $i++) {
                $answers[$address][] = self::everythingAClientSees($this->loginFrom($client, $address, 'the strangers own password'));
            }
        }

        self::assertSame($answers['confirmed@example.org'], $answers['nobody@example.org']);
        self::assertSame(
            [202, 401, 401, 401, 401, 401, 429, 429],
            array_column($answers['nobody@example.org'], 0),
        );
        // What other requests answer by is the same for both as well: the failed logins, and the
        // mails counted for registration, "send again" and the reset request (one each, the
        // registration). The mails the logins caused are counted elsewhere, where no answer depends on them.
        self::assertSame(
            [
                // The sixth and seventh login stopped at the limit of the pair: not held against the address.
                'login.address' => [5, 5], 'login.client' => [7, 7], 'login.pair' => [7, 7],
                'mail.address' => [1, 1], 'mail.client' => [1, 1], 'mail.pair' => [1, 1],
            ],
            array_diff_key($this->hitsByRule(), ['login.mail.client' => 0, 'login.mail.email' => 0]),
        );
        // So the next request that could be refused for too many mails is answered alike too.
        foreach (['confirmed@example.org' => '198.51.100.1', 'nobody@example.org' => '198.51.100.2'] as $address => $client) {
            $this->fromClientAddress($client);
            self::assertSame(202, $this->call('POST', '/auth/resend-verification', ['email' => $address])->status());
            self::assertSame(202, $this->call('POST', '/auth/password-reset/request', ['email' => $address])->status());
            self::assertSame(429, $this->call('POST', '/auth/register', TestAccounts::registration(['email' => $address]))->status());
        }
    }

    public function test_FR_ACC_03_the_right_password_for_an_unconfirmed_registration_mails_a_fresh_confirmation_link(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['locale' => 'en']));
        $first = self::tokenIn($this->mailTo('anna@example.org'));
        $this->mails()->clear();
        $this->clock()->advanceSeconds(600);

        $refused = $this->loginFrom(self::CLIENT_ADDRESS, 'anna@example.org', TestAccounts::PASSWORD);

        self::assertProblem(401, 'invalid_credentials', $refused);
        $mail = $this->mailTo('anna@example.org');
        self::assertSame('confirm_email', $mail->template);
        // The new link works, the one before it does not any more.
        $second = self::tokenIn($mail);
        self::assertNotSame($first, $second);
        self::assertProblem(400, 'invalid_token', $this->call('POST', '/auth/verify-email', self::confirmation($first)));
        self::assertSame(200, $this->call('POST', '/auth/verify-email', self::confirmation($second))->status());
        $this->logIn();
    }

    public function test_FR_ACC_03_a_wrong_password_for_an_unconfirmed_registration_mails_nothing(): void
    {
        $this->givenUser(verified: false);

        self::assertProblem(401, 'invalid_credentials', $this->loginFrom(self::CLIENT_ADDRESS, 'anna@example.org', 'not the password'));

        self::assertSame([], $this->mails()->sent());
        self::assertSame([], $this->sql()->rows('SELECT * FROM one_time_tokens'));
    }

    public function test_NFR_05_a_second_login_within_the_hour_is_answered_the_same_and_mails_no_second_link(): void
    {
        $this->givenUser(verified: false);
        $this->givenUser('confirmed@example.org');
        $this->loginFrom('198.51.100.7', 'anna@example.org', TestAccounts::PASSWORD);
        self::assertCount(1, $this->mails()->sent());
        $this->mails()->clear();
        $link = $this->sql()->rows('SELECT * FROM one_time_tokens');

        $again = $this->loginFrom('198.51.100.8', 'anna@example.org', TestAccounts::PASSWORD);
        $wrongPassword = $this->loginFrom('198.51.100.9', 'confirmed@example.org', 'not the password');

        // No 429 for the mail that is not sent: it would be an answer only a registration gets.
        self::assertProblem(401, 'invalid_credentials', $again);
        self::assertSame(self::everythingAClientSees($wrongPassword), self::everythingAClientSees($again));
        self::assertSame([], $this->mails()->sent());
        self::assertSame($link, $this->sql()->rows('SELECT * FROM one_time_tokens'));
    }

    public function test_the_email_is_compared_without_case_and_surrounding_space(): void
    {
        $this->givenUser('anna@example.org');

        self::assertSame(200, $this->call('POST', '/auth/login', ['email' => 'ANNA@Example.Org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body'])->status());
        self::assertSame(200, $this->call('POST', '/auth/login', ['email' => "  anna@example.org\t", 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body'])->status());
    }

    public function test_NFR_05_the_password_is_compared_exactly_as_sent(): void
    {
        $this->givenUser('anna@example.org');

        foreach ([' ' . TestAccounts::PASSWORD, TestAccounts::PASSWORD . ' ', TestAccounts::PASSWORD . "\n", ucfirst(TestAccounts::PASSWORD), TestAccounts::PASSWORD . "\u{0000}"] as $password) {
            self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => $password, 'refreshIn' => 'body']));
        }
    }

    public function test_NFR_05_a_hash_with_outdated_parameters_is_upgraded_on_a_successful_login(): void
    {
        $anna = $this->givenUser();
        $weak = password_hash(TestAccounts::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        $this->sql()->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$weak, $anna->id->value]);

        // A failed login changes nothing.
        $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => 'not the password', 'refreshIn' => 'body']);
        self::assertSame($weak, $this->sql()->value('SELECT password_hash FROM users'));

        $this->logIn();

        $upgraded = $this->sql()->column('SELECT password_hash FROM users')[0] ?? null;
        self::assertIsString($upgraded);
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $upgraded);
        self::assertTrue(password_verify(TestAccounts::PASSWORD, $upgraded));

        // The same password still works, and a current hash is left alone.
        $this->logIn();
        self::assertSame($upgraded, $this->sql()->value('SELECT password_hash FROM users'));
    }

    public function test_NFR_05_a_bcrypt_hash_is_upgraded_to_argon2id_on_a_successful_login(): void
    {
        $anna = $this->givenUser();
        $this->sql()->execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash(TestAccounts::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), $anna->id->value]);

        $this->logIn();

        $upgraded = $this->sql()->value('SELECT password_hash FROM users');
        self::assertIsString($upgraded);
        self::assertStringStartsWith('$argon2id$', $upgraded);
    }

    public function test_the_hash_of_the_test_accounts_is_a_current_hash_of_their_password(): void
    {
        $hasher = $this->service(PasswordHasher::class);

        self::assertTrue($hasher->verify(TestAccounts::PASSWORD, TestAccounts::PASSWORD_HASH));
        self::assertFalse($hasher->needsRehash(TestAccounts::PASSWORD_HASH));
    }

    public function test_NFR_05_five_wrong_logins_lock_the_strangers_client_for_that_address_and_the_owner_still_logs_in(): void
    {
        // SRS OQ-11: lock the one who guesses, not the account.
        $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 12:05:00 UTC'));
        $stranger = '198.51.100.66';
        for ($i = 1; $i <= 5; $i++) {
            self::assertProblem(401, 'invalid_credentials', $this->loginFrom($stranger, 'anna@example.org', "guess number {$i}"));
        }

        $sixth = $this->loginFrom($stranger, 'anna@example.org', 'guess number 6');
        $withTheRightPassword = $this->loginFrom($stranger, 'Anna@Example.org', TestAccounts::PASSWORD);
        // The owner, at another client address, with the right password.
        $owner = $this->loginFrom(self::CLIENT_ADDRESS, 'anna@example.org', TestAccounts::PASSWORD);

        self::assertSame(200, $owner->status());
        self::assertIsString($owner->json()['accessToken'] ?? null);
        // The stranger's client is locked for that address, also for the right password.
        $problem = self::assertProblem(429, 'rate_limited', $sixth);
        // The window is the quarter of the clock hour: 10 minutes are left.
        self::assertSame('600', $sixth->header('Retry-After'));
        self::assertSame(['type' => 'rate_limited', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Try again in 600 seconds.'], $problem);
        self::assertProblem(429, 'rate_limited', $withTheRightPassword);
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));

        // Only for that address (the same client logs in to another account), and only until the
        // window is over.
        self::assertSame(200, $this->loginFrom($stranger, 'bob@example.org', TestAccounts::PASSWORD)->status());
        $this->clock()->advanceSeconds(600);
        self::assertSame(200, $this->loginFrom($stranger, 'anna@example.org', TestAccounts::PASSWORD)->status());
    }

    public function test_NFR_05_the_31st_failed_login_from_a_client_address_within_15_minutes_is_429_whatever_the_account(): void
    {
        $this->givenUser('anna@example.org');
        // 29 failures of this client, at whatever addresses, are on the counter already.
        $this->givenCounted('login.client', self::CLIENT_ADDRESS, 29, '2026-11-17 12:00:00');
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', ['email' => 'user30@example.org', 'password' => 'not the password', 'refreshIn' => 'body']));

        $response = $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']);

        self::assertProblem(429, 'rate_limited', $response);
        self::assertSame('900', $response->header('Retry-After'));
        // A client over its own limit uses up nobody else's budget: nothing was counted for the address.
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM rate_limits WHERE `key` LIKE ?', ['%' . RateLimitKey::subjectHash('anna@example.org')]));

        // The account itself is not locked: its owner logs in from another address.
        $this->fromClientAddress('198.51.100.20');
        $this->logIn();
    }

    public function test_NFR_05_the_51st_failed_login_for_an_address_within_an_hour_locks_it_for_everybody(): void
    {
        $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 12:50:00 UTC'));
        // 49 failures for the address this hour, from all over the net.
        $this->givenCounted('login.address', 'anna@example.org', 49, '2026-11-17 12:00:00');
        self::assertProblem(401, 'invalid_credentials', $this->loginFrom('198.51.100.50', 'anna@example.org', 'the fiftieth guess'));

        // The 51st attempt, from a client that never tried before, with the right password.
        $refused = $this->loginFrom(self::CLIENT_ADDRESS, 'anna@example.org', TestAccounts::PASSWORD);
        $alsoRefused = $this->loginFrom('198.51.100.52', 'anna@example.org', 'another guess');

        self::assertProblem(429, 'rate_limited', $refused);
        // The window is the clock hour: 10 minutes are left.
        self::assertSame('600', $refused->header('Retry-After'));
        self::assertProblem(429, 'rate_limited', $alsoRefused);
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));

        // Another account is not affected, and after the hour the address works again.
        self::assertSame(200, $this->loginFrom(self::CLIENT_ADDRESS, 'bob@example.org', TestAccounts::PASSWORD)->status());
        $this->clock()->advanceSeconds(600);
        self::assertSame(200, $this->loginFrom(self::CLIENT_ADDRESS, 'anna@example.org', TestAccounts::PASSWORD)->status());
    }

    public function test_NFR_05_an_unknown_address_behaves_identically_to_a_known_one_at_every_limit(): void
    {
        // Otherwise "limited or not" would tell whether the address has an account.
        $this->givenUser('known@example.org');
        $this->givenUser('known-too@example.org');
        /** @var array<string, list<ApiResponse>> $responses */
        $responses = [];
        $counters = [];
        foreach (['known' => ['known@example.org', 'known-too@example.org'], 'unknown' => ['nobody@example.org', 'nobody-too@example.org']] as $kind => [$address, $other]) {
            $net = $kind === 'known' ? 1 : 2;
            // The limit of the pair: six wrong passwords from one client.
            for ($i = 1; $i <= 6; $i++) {
                $responses[$kind][] = $this->loginFrom("198.51.{$net}.1", $address, 'not the password');
            }
            // The limit of the address: with those five and 44 more from elsewhere, the next is the
            // 50th, and the one after it is refused, from a client that never tried.
            $this->givenCounted('login.address', $address, 5 + 44, '2026-11-17 12:00:00');
            $responses[$kind][] = $this->loginFrom("198.51.{$net}.2", $address, 'not the password');
            $responses[$kind][] = $this->loginFrom("198.51.{$net}.3", $address, 'not the password');
            // The limit of the client: its 30th failure, and then one more, at another address.
            $this->givenCounted('login.client', "198.51.{$net}.4", 29, '2026-11-17 12:00:00');
            $responses[$kind][] = $this->loginFrom("198.51.{$net}.4", $other, 'not the password');
            $responses[$kind][] = $this->loginFrom("198.51.{$net}.4", $other, 'not the password');
            $counters[$kind] = $this->hitsByRule();
            $this->sql()->execute('DELETE FROM rate_limits');
        }

        // Status, every header and the body of every answer, and how the counters stand afterwards.
        self::assertSame(
            array_map(self::everythingAClientSees(...), $responses['known'] ?? []),
            array_map(self::everythingAClientSees(...), $responses['unknown'] ?? []),
        );
        self::assertSame($counters['known'] ?? null, $counters['unknown'] ?? null);
        self::assertSame(
            [
                [401, ''], [401, ''], [401, ''], [401, ''], [401, ''], [429, '900'],
                [401, ''], [429, '3600'],
                [401, ''], [429, '900'],
            ],
            array_map(static fn (ApiResponse $response): array => [$response->status(), $response->header('Retry-After')], $responses['known'] ?? []),
        );
    }

    public function test_NFR_05_a_successful_login_does_not_reset_the_failure_counters(): void
    {
        $this->givenUser('victim@example.org');
        $this->givenUser('attacker@example.org');
        $guess = static fn (int $i): array => ['email' => 'victim@example.org', 'password' => "guess number {$i}", 'refreshIn' => 'body'];

        // Four guesses, a login to the attacker's own account, one more guess, another own login.
        for ($i = 1; $i <= 4; $i++) {
            self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', $guess($i)));
        }
        $this->logIn('attacker@example.org');
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', $guess(5)));

        // The sixth guess is refused: the successful login in between bought no new attempts.
        self::assertProblem(429, 'rate_limited', $this->call('POST', '/auth/login', $guess(6)));

        // The victim is not locked out by it, and the victim's own login forgives nothing either:
        // the five failures stay counted for the address, the client and the pair. (A refused
        // attempt is counted on top for the client and the pair, which only makes it stricter.)
        $this->fromClientAddress('198.51.100.30');
        $this->logIn('victim@example.org');
        self::assertSame(
            [5, 6, 6],
            array_map(
                fn (string $key): mixed => $this->sql()->value('SELECT hits FROM rate_limits WHERE `key` = ?', [$key]),
                [
                    RateLimitKey::of('login.address', 'victim@example.org'),
                    RateLimitKey::of('login.pair', 'victim@example.org', self::CLIENT_ADDRESS),
                    RateLimitKey::of('login.client', self::CLIENT_ADDRESS),
                ],
            ),
        );
        // The attacker's own account has no failure against it.
        self::assertSame(0, $this->sql()->value('SELECT hits FROM rate_limits WHERE `key` = ?', [RateLimitKey::of('login.address', 'attacker@example.org')]));
    }

    public function test_NFR_05_successful_logins_are_not_counted(): void
    {
        $this->givenUser();

        for ($i = 1; $i <= 7; $i++) {
            $this->logIn();
        }

        self::assertSame([0, 0, 0], $this->sql()->column('SELECT hits FROM rate_limits'));
        self::assertSame(7, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));
    }

    public function test_NFR_05_a_failed_login_is_counted_although_nothing_else_is_stored(): void
    {
        $this->givenUser();

        $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => 'not the password', 'refreshIn' => 'body']);

        self::assertSame(
            [
                // For the address, for the client, and for the address as tried from this client.
                // Hashes only: neither the address nor the client is in the table.
                ['key' => RateLimitKey::of('login.address', 'anna@example.org'), 'window_start' => '2026-11-17 12:00:00', 'hits' => 1],
                ['key' => RateLimitKey::of('login.client', self::CLIENT_ADDRESS), 'window_start' => '2026-11-17 12:00:00', 'hits' => 1],
                ['key' => RateLimitKey::of('login.pair', 'anna@example.org', self::CLIENT_ADDRESS), 'window_start' => '2026-11-17 12:00:00', 'hits' => 1],
            ],
            $this->sql()->rows('SELECT * FROM rate_limits ORDER BY `key`'),
        );
    }

    public function test_a_login_request_that_does_not_fit_the_contract_is_rejected_before_any_password_is_checked(): void
    {
        $this->givenUser();
        $valid = ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body'];

        $cases = [
            'no body' => [null, ['email' => 'required', 'password' => 'required', 'refreshIn' => 'required']],
            'no refreshIn' => [array_diff_key($valid, ['refreshIn' => 0]), ['refreshIn' => 'required']],
            'unknown refreshIn' => [['refreshIn' => 'header'] + $valid, ['refreshIn' => 'invalid_value']],
            'email that is no address' => [['email' => 'anna'] + $valid, ['email' => 'invalid_format']],
            'empty password' => [['password' => ''] + $valid, ['password' => 'too_short']],
            'password of 201 characters' => [['password' => str_repeat('a', 201)] + $valid, ['password' => 'too_long']],
            'password as number' => [['password' => 1234567890] + $valid, ['password' => 'wrong_type']],
            'empty device label' => [['deviceLabel' => ''] + $valid, ['deviceLabel' => 'too_short']],
            'device label of 101 characters' => [['deviceLabel' => str_repeat('a', 101)] + $valid, ['deviceLabel' => 'too_long']],
            'device label null' => [['deviceLabel' => null] + $valid, ['deviceLabel' => 'wrong_type']],
            'unknown member' => [['rememberMe' => true] + $valid, ['rememberMe' => 'unknown_field']],
        ];
        foreach ($cases as $name => [$body, $fields]) {
            $problem = self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/auth/login', $body));
            self::assertSame($fields, array_column(self::fieldCodes($problem), 'code', 'field'), $name);
        }

        self::assertSame([], $this->sql()->rows('SELECT * FROM rate_limits'));
        self::assertSame([], $this->sql()->rows('SELECT * FROM refresh_tokens'));
    }

    public function test_NFR_05_login_cannot_be_called_with_get_so_that_credentials_never_travel_in_a_url(): void
    {
        $response = $this->callUndocumented('GET', '/auth/login?email=anna@example.org&password=x');

        self::assertProblem(405, 'method_not_allowed', $response);
        self::assertSame('POST', $response->header('Allow'));
    }

    /**
     * @param callable(): mixed $work
     */
    private function secondsFor(callable $work): float
    {
        $start = hrtime(true);
        $work();

        return (hrtime(true) - $start) / 1e9;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $part): array
    {
        $json = json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true);
        self::assertIsArray($json);

        return $json;
    }

    private function loginFrom(string $client, string $email, string $password, string $refreshIn = 'body'): ApiResponse
    {
        $this->fromClientAddress($client);

        return $this->call('POST', '/auth/login', ['email' => $email, 'password' => $password, 'refreshIn' => $refreshIn]);
    }

    /**
     * Puts hits on a counter, as if that many requests had been counted before: for the limits that
     * are too high to reach with real password checks in a test (each costs a tenth of a second).
     */
    private function givenCounted(string $rule, string $subject, int $hits, string $windowStart): void
    {
        $this->sql()->execute(
            'INSERT INTO rate_limits (`key`, window_start, hits) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hits = VALUE(hits)',
            [RateLimitKey::of($rule, $subject), $windowStart, $hits],
        );
    }

    /**
     * @return array<string, list<int>> The hits of every counter, by rule ("login.address", …), sorted.
     */
    private function hitsByRule(): array
    {
        $hits = [];
        foreach ($this->sql()->rows('SELECT `key`, hits FROM rate_limits ORDER BY `key`') as $row) {
            self::assertIsString($row['key']);
            self::assertIsInt($row['hits']);
            $hits[explode(':', $row['key'])[0]][] = $row['hits'];
        }
        foreach ($hits as &$ofRule) {
            rsort($ofRule);
        }
        ksort($hits);

        return $hits;
    }

    /**
     * @return array{int, string, array<array-key, mixed>}
     */
    private static function everythingAClientSees(ApiResponse $response): array
    {
        return [$response->status(), $response->body(), $response->response->getHeaders()];
    }

    private function everythingStoredAndLogged(): string
    {
        $text = $this->logs()->text();
        foreach (['users', 'consents', 'one_time_tokens', 'refresh_tokens', 'rate_limits'] as $table) {
            $text .= json_encode($this->sql()->rows("SELECT * FROM {$table}"), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $text;
    }
}
