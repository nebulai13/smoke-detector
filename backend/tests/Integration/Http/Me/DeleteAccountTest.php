<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Me;

use RuntimeException;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataEraser;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\Privacy\UserDataTable;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Privacy\RowsOfUser;
use SmokeDetector\Infrastructure\Privacy\UserDataTables;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\Privacy\DatabaseContents;
use SmokeDetector\Tests\Support\Privacy\UserDataFixture;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Yaml\Yaml;

/**
 * DELETE /me through the real application and database (HLD §4.5 "Account", FR-ACC-08, LEG-03).
 *
 * The checks after a deletion look at every table the schema has, not at a list of tables: a table
 * that a later build step adds is covered without touching these tests.
 */
final class DeleteAccountTest extends HttpTestCase
{
    /** Set by a test to make account deletion fail half-way. */
    private bool $failingEraser = false;

    protected function services(): array
    {
        if (!$this->failingEraser) {
            return [];
        }

        // The real tables, and after them one whose eraser fails: by then the erasers before it
        // have already deleted their rows inside the transaction.
        return [
            UserDataRegistry::class => \DI\factory(static function (UserDataTables $tables, Sql $sql): UserDataRegistry {
                $broken = new class implements UserDataExporter, UserDataEraser {
                    public function export(UserId $userId): array
                    {
                        return [];
                    }

                    public function erase(UserId $userId): void
                    {
                        throw new RuntimeException('This eraser fails.');
                    }
                };
                $real = $tables->registry()->tables();

                return new UserDataRegistry([
                    ...array_map(
                        static fn (string $table): UserDataTable => new UserDataTable($table, 'x' . str_replace('_', '', $table), $broken, new RowsOfUser($sql, $table)),
                        $real,
                    ),
                    new UserDataTable('broken', 'broken', $broken, $broken),
                ]);
            }),
        ];
    }

    public function test_FR_ACC_08_with_the_password_the_account_and_everything_about_the_user_is_deleted_at_once(): void
    {
        $anna = $this->givenFullAccount('anna@example.org', 1);

        $response = $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        self::assertSame([], $this->sql()->rows('SELECT id FROM users'));
        // In no table at all, whatever tables there are: no row mentions the id, the email address,
        // or the hashes under which rate limits are counted for them.
        self::assertSame([], DatabaseContents::rowsMentioning($this->sql(), self::tracesOf($anna)));
        // (`rate_limits` keeps one row, at zero: the counter of the client address, which is not the user's.)
        foreach (DatabaseContents::all($this->sql(), ['rate_limits']) as $table => $rows) {
            self::assertSame([], $rows, "Table `{$table}` should be empty: the only user was deleted.");
        }
        self::assertSame(
            [['key' => RateLimitKey::of('login.client', self::CLIENT_ADDRESS), 'window_start' => '2026-11-17 12:00:00', 'hits' => 0]],
            $this->sql()->rows('SELECT * FROM rate_limits'),
        );
    }

    public function test_LEG_03_consent_is_withdrawn_by_deleting_the_account_and_no_consent_record_stays(): void
    {
        $anna = $this->givenFullAccount('anna@example.org', 1);
        $bob = $this->givenFullAccount('bob@example.org', 2);
        self::assertSame(3, $this->sql()->value('SELECT COUNT(*) FROM consents WHERE user_id = ?', [$anna->id->value]));

        $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        // Withdrawal is deletion (LEG-03): the records go with the account, at once, and only hers.
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM consents WHERE user_id = ?', [$anna->id->value]));
        self::assertSame(3, $this->sql()->value('SELECT COUNT(*) FROM consents WHERE user_id = ?', [$bob->id->value]));
    }

    public function test_FR_ACC_08_nothing_of_another_user_is_touched(): void
    {
        $anna = $this->givenFullAccount('anna@example.org', 1);
        $bob = $this->givenFullAccount('bob@example.org', 2);
        // Something counted for each of them, too.
        $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => 'wrong', 'refreshIn' => 'body']);
        $this->call('POST', '/auth/login', ['email' => 'bob@example.org', 'password' => 'wrong', 'refreshIn' => 'body']);
        $this->call('GET', '/me/export', headers: $this->authorization($anna));
        $this->call('GET', '/me/export', headers: $this->authorization($bob));
        $bobsRows = $this->rowsOf($bob);
        self::assertNotSame([], array_filter($bobsRows));

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna))->status());

        self::assertSame([], DatabaseContents::rowsMentioning($this->sql(), self::tracesOf($anna)));
        self::assertSame($bobsRows, $this->rowsOf($bob));
        self::assertSame(200, $this->call('GET', '/me', headers: $this->authorization($bob))->status());
    }

    public function test_FR_ACC_08_afterwards_the_access_token_opens_no_endpoint_any_more(): void
    {
        $anna = $this->givenUser();
        $session = $this->logIn()->json();
        self::assertIsString($session['accessToken'] ?? null);
        $headers = ['Authorization' => 'Bearer ' . $session['accessToken']];
        self::assertSame(200, $this->call('GET', '/me', headers: $headers)->status());

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $headers)->status());

        // Every operation of the contract that needs a token, with a body it would accept.
        $bodies = [
            'GET /me' => null,
            'DELETE /me' => ['password' => TestAccounts::PASSWORD],
            'PATCH /me' => ['locale' => 'en'],
            'PUT /me/plan' => ['startingBaseline' => 12, 'goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'normal', 'defaultProduct' => 'vape'],
            'GET /me/export' => null,
            'GET /me/dashboard' => null,
            'GET /me/baseline-history' => null,
            'POST /logs/sync' => ['items' => [['id' => TestData::uuid(1), 'smokedAt' => '2026-11-17T08:15:00Z', 'productType' => 'cigarette', 'count' => 1]]],
            'GET /days' => null,
            'POST /days/{date}/check-in' => null,
            'GET /me/invite' => null,
            'POST /me/invite' => null,
            'GET /friends' => null,
            'POST /friends/requests' => ['code' => 'K7M2QX9RTB'],
            'POST /friends/requests/accept' => ['userId' => TestData::uuid(2)],
            'POST /friends/requests/decline' => ['userId' => TestData::uuid(2)],
            'POST /friends/requests/withdraw' => ['userId' => TestData::uuid(2)],
            'POST /friends/remove' => ['userId' => TestData::uuid(2)],
            'POST /friends/block' => ['userId' => TestData::uuid(2)],
            'POST /friends/unblock' => ['userId' => TestData::uuid(2)],
            'GET /friends/blocked' => null,
            'GET /duels' => null,
            'POST /duels' => ['opponentId' => TestData::uuid(2), 'periodDays' => 7],
            'POST /duels/show' => ['duelId' => TestData::uuid(3)],
            'POST /duels/accept' => ['duelId' => TestData::uuid(3)],
            'POST /duels/decline' => ['duelId' => TestData::uuid(3)],
            'POST /duels/cancel' => ['duelId' => TestData::uuid(3)],
            'GET /leagues' => null,
            'GET /leagues/xp' => null,
        ];
        // Where the path of the contract is a pattern or needs a query string: what is really called.
        $paths = [
            'GET /days' => '/days?from=2026-11-17&to=2026-11-17', 'POST /days/{date}/check-in' => '/days/2026-11-17/check-in',
            'GET /leagues' => '/leagues?type=week&period=current',
        ];
        self::assertSame(self::operationsThatNeedAToken(), array_keys($bodies), 'A new operation needs a body here.');
        foreach ($bodies as $operation => $body) {
            [$method, $path] = explode(' ', $operation);
            $response = $this->call($method, $paths[$operation] ?? $path, $body, $headers);

            self::assertSame(['type' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401], $response->json(), $operation);
            self::assertSame('Bearer', $response->header('WWW-Authenticate'), $operation);
        }
        // And nothing was written for the user who is gone: no plan, no counter, no account.
        self::assertSame([], DatabaseContents::rowsMentioning($this->sql(), self::tracesOf($anna)));
    }

    public function test_FR_ACC_08_all_sessions_end_the_refresh_tokens_are_dead(): void
    {
        $this->givenUser();
        $phone = $this->logIn()->json();
        $laptop = $this->logIn()->json();
        self::assertIsString($phone['accessToken'] ?? null);

        $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], ['Authorization' => 'Bearer ' . $phone['accessToken']]);

        foreach ([$phone, $laptop] as $session) {
            self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $session['refreshToken'] ?? null]));
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens'));
        // Logging in is over as well, and answers like for an address that never had an account.
        self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']));
    }

    public function test_FR_ACC_08_the_email_address_can_register_again_as_a_new_account(): void
    {
        $anna = $this->givenFullAccount('anna@example.org', 1);
        $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));
        $this->mails()->clear();

        $response = $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'anna@example.org', 'displayName' => 'Anna again']));

        self::assertSame(202, $response->status());
        // The mail of a new registration, not "you already have an account".
        $this->call('POST', '/auth/verify-email', self::confirmation(self::tokenIn($this->mailTo('anna@example.org'))));
        $session = $this->logIn()->json();
        self::assertIsString($session['accessToken'] ?? null);
        $me = $this->call('GET', '/me', headers: ['Authorization' => 'Bearer ' . $session['accessToken']])->json();
        // A new account: another id, the new name, and nothing of the old plan.
        self::assertNotSame($anna->id->value, $me['id'] ?? null);
        self::assertSame(['Anna again', false], [$me['displayName'] ?? null, $me['onboardingComplete'] ?? null]);
        self::assertSame([], DatabaseContents::rowsMentioning($this->sql(), [$anna->id->value]));
    }

    public function test_FR_ACC_08_a_wrong_password_is_a_401_and_deletes_nothing(): void
    {
        $anna = $this->givenFullAccount('anna@example.org', 1);
        $before = DatabaseContents::all($this->sql(), ['rate_limits']);

        $response = $this->call('DELETE', '/me', ['password' => 'not the password'], $this->authorization($anna));

        self::assertSame(
            ['type' => 'invalid_credentials', 'title' => 'Invalid credentials', 'status' => 401, 'detail' => 'The password is wrong.'],
            self::assertProblem(401, 'invalid_credentials', $response),
        );
        // Not the challenge of a missing token: refreshing the token would not help.
        self::assertFalse($response->response->hasHeader('WWW-Authenticate'));
        self::assertSame($before, DatabaseContents::all($this->sql(), ['rate_limits']));
        self::assertSame(200, $this->call('GET', '/me', headers: $this->authorization($anna))->status());
    }

    public function test_FR_ACC_08_a_valid_token_alone_deletes_nothing(): void
    {
        $anna = $this->givenFullAccount('anna@example.org', 1);
        $before = DatabaseContents::all($this->sql());

        $responses = [
            'no body' => $this->callInvalid('DELETE', '/me', null, $this->authorization($anna)),
            'empty body' => $this->callInvalid('DELETE', '/me', [], $this->authorization($anna)),
            'empty password' => $this->callInvalid('DELETE', '/me', ['password' => ''], $this->authorization($anna)),
            'password of the wrong type' => $this->callInvalid('DELETE', '/me', ['password' => true], $this->authorization($anna)),
            'too long' => $this->callInvalid('DELETE', '/me', ['password' => str_repeat('x', 201)], $this->authorization($anna)),
            'confirm instead of password' => $this->callInvalid('DELETE', '/me', ['confirm' => true], $this->authorization($anna)),
            'an unknown member with the right password' => $this->callInvalid('DELETE', '/me', ['password' => TestAccounts::PASSWORD, 'userId' => $anna->id->value], $this->authorization($anna)),
        ];

        foreach ($responses as $case => $response) {
            self::assertSame(422, $response->status(), $case);
        }
        self::assertSame(
            [['field' => 'password', 'code' => 'required']],
            self::fieldCodes($responses['no body']->json()),
        );
        self::assertSame(
            [['field' => 'password', 'code' => 'too_long']],
            self::fieldCodes($responses['too long']->json()),
        );
        // Not even a failed attempt was counted: the password was never looked at.
        self::assertSame($before, DatabaseContents::all($this->sql()));
    }

    public function test_FR_ACC_08_the_password_alone_without_a_token_deletes_nothing(): void
    {
        $this->givenFullAccount('anna@example.org', 1);
        $before = DatabaseContents::all($this->sql());

        $response = $this->callInvalid('DELETE', '/me', ['password' => TestAccounts::PASSWORD]);

        self::assertProblem(401, 'unauthorized', $response);
        self::assertSame($before, DatabaseContents::all($this->sql()));
    }

    public function test_FR_ACC_08_the_token_of_one_user_and_the_password_of_another_delete_nobody(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');
        $this->sql()->execute('UPDATE users SET password_hash = ? WHERE email = ?', [password_hash('bobs own password', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1]), 'bob@example.org']);

        $response = $this->call('DELETE', '/me', ['password' => 'bobs own password'], $this->authorization($anna));

        self::assertProblem(401, 'invalid_credentials', $response);
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM users'));
    }

    public function test_NFR_05_after_five_wrong_passwords_the_right_one_is_refused_too_until_the_window_ends(): void
    {
        $anna = $this->givenUser();
        $this->clock()->advanceSeconds(60);
        for ($i = 1; $i <= 5; $i++) {
            self::assertProblem(401, 'invalid_credentials', $this->call('DELETE', '/me', ['password' => "guess {$i}"], $this->authorization($anna)));
        }

        $refused = $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        self::assertProblem(429, 'rate_limited', $refused);
        // 12:01, the window ends at 12:15.
        self::assertSame('840', $refused->header('Retry-After'));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM users'));

        $this->clock()->advanceSeconds(840);
        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna))->status());
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM users'));
    }

    public function test_NFR_05_guesses_at_login_and_at_deletion_count_together(): void
    {
        // A stolen access token must not be a way around the login limit: it is the same password
        // that is being guessed, so it is the same five attempts of the client.
        $anna = $this->givenUser();
        $this->fromClientAddress('198.51.100.66');
        for ($i = 1; $i <= 3; $i++) {
            $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => "guess {$i}", 'refreshIn' => 'body']);
        }
        for ($i = 4; $i <= 5; $i++) {
            $this->call('DELETE', '/me', ['password' => "guess {$i}"], $this->authorization($anna));
        }

        self::assertProblem(429, 'rate_limited', $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna)));
        self::assertProblem(429, 'rate_limited', $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => TestAccounts::PASSWORD, 'refreshIn' => 'body']));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM users'));
    }

    public function test_NFR_05_the_owner_can_still_delete_the_account_while_a_stranger_hammers_the_address_from_one_client(): void
    {
        // SRS OQ-11: five wrong logins by anybody used to lock the owner out of DELETE /me.
        $anna = $this->givenUser();
        $this->fromClientAddress('198.51.100.66');
        for ($i = 1; $i <= 5; $i++) {
            self::assertProblem(401, 'invalid_credentials', $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => "guess {$i}", 'refreshIn' => 'body']));
        }
        self::assertProblem(429, 'rate_limited', $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => 'guess 6', 'refreshIn' => 'body']));

        $this->fromClientAddress(self::CLIENT_ADDRESS);
        $response = $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        self::assertSame(204, $response->status());
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM users'));
        // What the stranger's guesses left behind about the address went with the account. What is
        // left is counted for client addresses alone.
        self::assertSame([], DatabaseContents::rowsMentioning($this->sql(), self::tracesOf($anna)));
        self::assertSame(
            [RateLimitKey::of('login.client', '198.51.100.66'), RateLimitKey::of('login.client', self::CLIENT_ADDRESS)],
            $this->sql()->column('SELECT `key` FROM rate_limits ORDER BY hits DESC'),
        );
    }

    public function test_FR_ACC_08_the_deletion_is_all_or_nothing(): void
    {
        $this->failingEraser = true;
        $anna = $this->givenFullAccount('anna@example.org', 1);
        $before = DatabaseContents::all($this->sql(), ['rate_limits']);

        $response = $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        // The erasers of the real tables had already run when the last one failed. Nothing of it stays.
        self::assertProblem(500, 'internal_error', $response);
        self::assertSame($before, DatabaseContents::all($this->sql(), ['rate_limits']));
        self::assertNotSame([], $before['plans'] ?? []);
    }

    public function test_NFR_05_the_password_is_neither_logged_nor_stored_by_a_deletion(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');

        $this->call('DELETE', '/me', ['password' => 'a wrong password of anna'], $this->authorization($anna));
        $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($bob));

        $everything = json_encode([DatabaseContents::all($this->sql()), $this->logs()->records], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('a wrong password of anna', $everything);
        self::assertStringNotContainsString(TestAccounts::PASSWORD, $everything);
        self::assertSame([], $this->logs()->records);
    }

    public function test_FR_ACC_08_no_mail_is_sent_and_the_response_has_no_body(): void
    {
        $anna = $this->givenUser();

        $response = $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna));

        self::assertSame([204, ''], [$response->status(), $response->body()]);
        self::assertSame([], $this->mails()->sent());
    }

    /**
     * A confirmed account with rows in every table that can hold data of a user.
     */
    private function givenFullAccount(string $email, int $number): User
    {
        $user = $this->givenUser($email);
        (new UserDataFixture($this->service(Sql::class)))->fill($user->id, $number);

        return $user;
    }

    /**
     * Everything by which a row could point at the user: the id, the email address, and the hashes
     * of both, under which rate limits are counted.
     *
     * @return list<string>
     */
    private static function tracesOf(User $user): array
    {
        return [
            $user->id->value,
            $user->email->value,
            // What a rate-limit key of the user ends with, and the plain hashes, which nothing may hold either.
            RateLimitKey::subjectHash($user->id->value),
            RateLimitKey::subjectHash($user->email->value),
            hash('sha256', $user->id->value),
            hash('sha256', $user->email->value),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>> Table => the rows that mention the user.
     */
    private function rowsOf(User $user): array
    {
        $traces = self::tracesOf($user);

        return array_map(
            static fn (array $rows): array => array_values(array_filter(
                $rows,
                static function (array $row) use ($traces): bool {
                    foreach ($row as $value) {
                        foreach ($traces as $trace) {
                            if (is_string($value) && str_contains($value, $trace)) {
                                return true;
                            }
                        }
                    }

                    return false;
                },
            )),
            DatabaseContents::all($this->sql()),
        );
    }

    /**
     * @return list<string> "GET /me", … in the order of the contract.
     */
    private static function operationsThatNeedAToken(): array
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        self::assertIsArray($document['paths'] ?? null);

        $operations = [];
        foreach ($document['paths'] as $path => $item) {
            self::assertIsArray($item);
            foreach (['get', 'put', 'post', 'delete', 'patch'] as $method) {
                if (isset($item[$method]) && is_array($item[$method]) && ($item[$method]['security'] ?? null) !== []) {
                    $operations[] = strtoupper($method) . ' ' . $path;
                }
            }
        }

        return $operations;
    }
}
