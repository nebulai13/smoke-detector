<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Privacy;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SmokeDetector\Application\Privacy\ExportAccount;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Privacy\DatabaseContents;
use SmokeDetector\Tests\Support\Privacy\UserDataFixture;
use SmokeDetector\Tests\Support\TestData;

/**
 * The exporters and erasers of the real user data registry against the real database
 * (HLD §4.5 "Account", FR-ACC-07, FR-ACC-08).
 *
 * Two users have rows in every table (UserDataFixture). Whatever is done for the first must leave
 * the second alone.
 */
final class UserDataTablesTest extends IntegrationTestCase
{
    /**
     * Where each stored column of a user ends up in the export. "*" stands for every entry of a list.
     * A column that is added by a later migration must be added here or to NOT_EXPORTED.
     */
    private const array EXPORTED = [
        'users.id' => 'profile.id',
        'users.email' => 'profile.email',
        'users.display_name' => 'profile.displayName',
        'users.avatar_key' => 'profile.avatar',
        'users.locale' => 'profile.locale',
        'users.timezone' => 'profile.timeZone',
        'users.email_verified_at' => 'profile.emailVerifiedAt',
        'users.created_at' => 'profile.createdAt',
        'consents.type' => 'consents.*.type',
        'consents.version' => 'consents.*.version',
        'consents.granted_at' => 'consents.*.grantedAt',
        'plans.goal_mode' => 'plan.goalMode',
        'plans.target' => 'plan.target',
        'plans.taper_speed' => 'plan.taperSpeed',
        'plans.default_product' => 'plan.defaultProduct',
        'plans.start_date' => 'plan.startDate',
        'plans.rules_version' => 'plan.rulesVersion',
        'baseline_history.valid_from' => 'baselineHistory.*.validFrom',
        'baseline_history.value' => 'baselineHistory.*.value',
        'baseline_history.source' => 'baselineHistory.*.source',
        'baseline_history.w_mean' => 'baselineHistory.*.weekMean',
        'baseline_history.m_mean' => 'baselineHistory.*.monthMean',
        'baseline_history.w_days' => 'baselineHistory.*.weekDays',
        'baseline_history.m_days' => 'baselineHistory.*.monthDays',
        'baseline_history.rules_version' => 'baselineHistory.*.rulesVersion',
        'refresh_tokens.id' => 'sessions.*.session',
        'refresh_tokens.device_label' => 'sessions.*.deviceLabel',
        'refresh_tokens.created_at' => 'sessions.*.createdAt',
        'refresh_tokens.rotated_at' => 'sessions.*.lastRefreshedAt',
        'refresh_tokens.expires_at' => 'sessions.*.expiresAt',
        'refresh_tokens.revoked_at' => 'sessions.*.revokedAt',
        'one_time_tokens.purpose' => 'mailLinks.*.purpose',
        'one_time_tokens.expires_at' => 'mailLinks.*.expiresAt',
        'one_time_tokens.used_at' => 'mailLinks.*.usedAt',
        'mail_outbox.template' => 'queuedMails.*.template',
        'mail_outbox.created_at' => 'queuedMails.*.createdAt',
        'mail_outbox.attempts' => 'queuedMails.*.attempts',
    ];

    /** Stored columns that are deliberately not in the export, and why. */
    private const array NOT_EXPORTED = [
        'users.password_hash' => 'A secret. It says nothing to the user and would help whoever gets hold of the export.',
        'consents.user_id' => 'The user\'s own id, which is profile.id.',
        'plans.user_id' => 'The user\'s own id, which is profile.id.',
        'baseline_history.user_id' => 'The user\'s own id, which is profile.id.',
        'refresh_tokens.user_id' => 'The user\'s own id, which is profile.id.',
        'users.token_version' => 'How often the password was reset: the number an access token must carry to be accepted. Part of the credentials, like the password hash.',
        'refresh_tokens.key_hash' => 'A secret: the hash of the key that every token of the login carries.',
        'refresh_tokens.token_hash' => 'A secret: the hash of a token that may still be valid.',
        'refresh_tokens.generation' => 'How often the token of the login was exchanged: a counter the secret of the token is derived from. Part of the credentials. When it last happened is sessions.*.lastRefreshedAt.',
        'one_time_tokens.user_id' => 'The user\'s own id, which is profile.id.',
        'one_time_tokens.id' => 'An internal row number without meaning outside the database.',
        'one_time_tokens.token_hash' => 'A secret: the hash of a link that may still be valid.',
        'one_time_tokens.failed_attempts' => 'A security counter, like the rate limits: how many wrong passwords were tried with a confirmation link. It protects the link and says nothing the user can use.',
        'mail_outbox.id' => 'An internal row number without meaning outside the database.',
        'mail_outbox.user_id' => 'The user\'s own id, which is profile.id.',
        'mail_outbox.locale' => 'The language the mail will be written in: the language of the account when the mail was queued, which is profile.locale.',
        'mail_outbox.recipient' => 'The address the mail goes to: the address of the account, which is profile.email. The export shows an address in one place only.',
        'mail_outbox.sealed_values' => 'A secret: the links of the mail, encrypted. A link carries a token that may still be valid.',
        'mail_outbox.not_before' => 'When the sender tries next: the schedule of the cron job, which says nothing about the user.',
    ];

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = (new ContainerFactory())->create($this->settings(), [
            ClockInterface::class => FrozenClock::at('2026-11-17 12:00:00'),
        ]);

        $sql = $this->service(Sql::class);
        foreach ([1 => 'anna@example.org', 2 => 'bob@example.org'] as $number => $email) {
            $user = TestData::user($number, $email, 'hash-of-' . $number, displayName: "Name {$number}");
            $this->sql()->execute(
                'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$user->id->value, $email, $user->passwordHash, $user->displayName->value, 'avatar-03', 'de', 'Europe/Berlin', '2026-11-02 08:00:00', '2026-11-01 07:00:00'],
            );
            (new UserDataFixture($sql))->fill($user->id, $number);
        }
    }

    public function test_FR_ACC_07_the_export_holds_what_every_table_stores_about_the_user(): void
    {
        $export = $this->service(TransactionRunner::class)->run(
            fn (): array => $this->service(UserDataRegistry::class)->export(TestData::userId(1)),
        );

        self::assertSame(
            [
                'consents' => [
                    ['type' => 'age18', 'version' => 'age-v1', 'grantedAt' => '2026-11-01T08:00:00Z'],
                    ['type' => 'art9', 'version' => 'art9-v2', 'grantedAt' => '2026-11-01T08:00:00Z'],
                    ['type' => 'privacy', 'version' => 'privacy-v3', 'grantedAt' => '2026-11-01T08:00:01Z'],
                ],
                'plan' => [
                    'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'gentle', 'defaultProduct' => 'rolled',
                    'startDate' => '2026-11-02', 'rulesVersion' => 'v1',
                ],
                'baselineHistory' => [
                    ['validFrom' => '2026-11-02', 'value' => 12.0, 'source' => 'onboarding', 'weekMean' => null, 'monthMean' => null, 'weekDays' => null, 'monthDays' => null, 'rulesVersion' => 'v1'],
                    ['validFrom' => '2026-11-09', 'value' => 9.8, 'source' => 'weekly', 'weekMean' => 9.5, 'monthMean' => 10.25, 'weekDays' => 6, 'monthDays' => 7, 'rulesVersion' => 'v1'],
                    ['validFrom' => '2026-11-16', 'value' => 9.8, 'source' => 'unchanged_low_data', 'weekMean' => null, 'monthMean' => 9.75, 'weekDays' => 0, 'monthDays' => 9, 'rulesVersion' => 'v1'],
                ],
                'sessions' => [
                    // One entry per login, however often its token was exchanged.
                    ['session' => TestData::uuid(1001), 'deviceLabel' => 'Phone 1', 'createdAt' => '2026-11-10T09:00:00Z', 'lastRefreshedAt' => '2026-11-12T07:30:00Z', 'expiresAt' => '2027-01-11T07:30:00Z', 'revokedAt' => null],
                    ['session' => TestData::uuid(1003), 'deviceLabel' => null, 'createdAt' => '2026-11-13T20:00:00Z', 'lastRefreshedAt' => null, 'expiresAt' => '2027-01-12T20:00:00Z', 'revokedAt' => '2026-11-13T21:00:00Z'],
                ],
                'mailLinks' => [
                    ['purpose' => 'verify_email', 'expiresAt' => '2026-11-02T08:00:00Z', 'usedAt' => '2026-11-01T08:05:00Z'],
                    ['purpose' => 'reset_password', 'expiresAt' => '2026-11-17T13:00:00Z', 'usedAt' => null],
                ],
                'queuedMails' => [
                    // Which mail waits, since when, and how often the mail server refused it. Not where
                    // it goes (the profile says that) and never what is in it.
                    ['template' => 'confirm_email', 'createdAt' => '2026-11-17T11:58:00Z', 'attempts' => 0],
                    ['template' => 'reset_password', 'createdAt' => '2026-11-17T11:59:00Z', 'attempts' => 2],
                ],
            ],
            $export,
        );
    }

    public function test_FR_ACC_07_a_user_without_rows_exports_empty_lists_and_no_plan(): void
    {
        $this->sql()->execute(
            'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [TestData::uuid(3), 'carol@example.org', 'x', 'Carol', 'avatar-03', 'de', 'Europe/Berlin', '2026-11-02 08:00:00', '2026-11-01 07:00:00'],
        );

        $export = $this->service(UserDataRegistry::class)->export(TestData::userId(3));

        self::assertSame(['consents' => [], 'baselineHistory' => [], 'sessions' => [], 'mailLinks' => [], 'queuedMails' => []], $export);
    }

    public function test_FR_ACC_07_every_stored_column_about_a_user_is_exported_or_deliberately_left_out(): void
    {
        $tables = ['users', ...$this->service(UserDataRegistry::class)->tables()];

        $stored = [];
        foreach ($tables as $table) {
            foreach ($this->sql()->column('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]) as $column) {
                self::assertIsString($column);
                $stored[] = "{$table}.{$column}";
            }
        }
        $accountedFor = [...array_keys(self::EXPORTED), ...array_keys(self::NOT_EXPORTED)];

        self::assertSame([], array_values(array_diff($stored, $accountedFor)), 'Stored columns that are neither exported nor deliberately left out.');
        self::assertSame([], array_values(array_diff($accountedFor, $stored)), 'Columns that are listed here but do not exist (any more).');
        self::assertSame([], array_values(array_intersect(array_keys(self::EXPORTED), array_keys(self::NOT_EXPORTED))));

        // And the members named above are really in the document of a user who has rows everywhere.
        $export = $this->service(ExportAccount::class)->execute(TestData::userId(1));
        foreach (self::EXPORTED as $column => $path) {
            self::assertTrue(self::has($export, explode('.', $path)), "{$column} should be exported as {$path}.");
        }
    }

    public function test_NFR_05_the_export_contains_no_password_hash_no_token_and_no_token_hash(): void
    {
        $json = json_encode($this->service(ExportAccount::class)->execute(TestData::userId(1)), JSON_THROW_ON_ERROR);

        foreach (['hash-of-1', ...UserDataFixture::secrets(1)] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertDoesNotMatchRegularExpression('/"[^"]*(hash|password|token)[^"]*":/i', $json, 'No member is named like a secret.');
        // The address a queued mail goes to is in the export once, as the profile's.
        self::assertStringNotContainsString('mailbox-1@example.org', $json);
    }

    public function test_FR_ACC_07_the_export_of_one_user_contains_nothing_of_another(): void
    {
        $json = json_encode($this->service(ExportAccount::class)->execute(TestData::userId(1)), JSON_THROW_ON_ERROR);

        foreach ([TestData::uuid(2), 'bob@example.org', 'Name 2', 'Phone 2', TestData::uuid(2101), TestData::uuid(2102), 'mailbox-2@example.org'] as $bobs) {
            self::assertStringNotContainsString($bobs, $json);
        }
        self::assertStringContainsString('anna@example.org', $json);
    }

    public function test_FR_ACC_07_exporting_changes_nothing_in_any_table(): void
    {
        $before = DatabaseContents::all($this->sql(), ['rate_limits']);

        $this->service(ExportAccount::class)->execute(TestData::userId(1));

        self::assertSame($before, DatabaseContents::all($this->sql(), ['rate_limits']));
    }

    public function test_the_fixture_fills_every_registered_table_for_both_users(): void
    {
        // Otherwise the tests below would pass for a table they never looked at.
        foreach ($this->service(UserDataRegistry::class)->tables() as $table) {
            foreach ([1, 2] as $number) {
                self::assertNotSame(
                    [],
                    $this->rowsOfUser($number)[$table] ?? [],
                    "UserDataFixture stores no row in `{$table}` for user {$number}. Add one, so that export and erasure of the table are tested.",
                );
            }
        }
    }

    public function test_FR_ACC_08_the_erasers_alone_remove_every_row_of_the_user_from_every_table(): void
    {
        // Without deleting the account: so it is the erasers that do the work here, not the
        // foreign keys' ON DELETE CASCADE.
        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(1)),
        );

        self::assertSame(['users' => 1], DatabaseContents::rowsMentioning($this->sql(), [TestData::uuid(1)]));
    }

    public function test_FR_ACC_08_erasing_one_user_leaves_every_row_of_another_as_it_was(): void
    {
        $before = $this->rowsOfUser(2);

        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(1)),
        );

        self::assertSame($before, $this->rowsOfUser(2));
        self::assertNotSame([], array_filter($before));
    }

    public function test_FR_ACC_08_erasing_a_user_who_has_nothing_does_nothing(): void
    {
        $before = DatabaseContents::all($this->sql());

        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(99)),
        );

        self::assertSame($before, DatabaseContents::all($this->sql()));
    }

    /**
     * @return array<string, list<array<string, mixed>>> Table => the rows that hold the id of the user.
     */
    private function rowsOfUser(int $number): array
    {
        $rows = [];
        foreach (DatabaseContents::all($this->sql()) as $table => $all) {
            $rows[$table] = array_values(array_filter(
                $all,
                static fn (array $row): bool => in_array(TestData::uuid($number), $row, true),
            ));
        }

        return $rows;
    }

    /**
     * Whether the document has a member at the path. "*" must hold for at least one entry of a list
     * (a nullable column is filled in some rows of the fixture and empty in others).
     *
     * @param list<string> $path
     */
    private static function has(mixed $node, array $path): bool
    {
        if ($path === []) {
            return $node !== null;
        }
        if (!is_array($node)) {
            return false;
        }
        $key = array_shift($path);
        if ($key === '*') {
            foreach ($node as $entry) {
                if (self::has($entry, $path)) {
                    return true;
                }
            }

            return false;
        }

        return array_key_exists($key, $node) && self::has($node[$key], $path);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
