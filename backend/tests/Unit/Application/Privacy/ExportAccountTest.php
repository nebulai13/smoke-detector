<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Privacy;

use ArrayObject;
use Closure;
use DateTimeImmutable;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportAccount;
use SmokeDetector\Application\Privacy\PrivacyLimits;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\Privacy\UserDataTable;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Tests\Support\Fake\RecordingUserData;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\AccountUseCaseTestCase;

/**
 * GET /me/export (HLD §4.5 "Account", FR-ACC-07): everything stored about the user as one document.
 */
final class ExportAccountTest extends AccountUseCaseTestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new ArrayObject();
    }

    public function test_FR_ACC_07_the_export_is_the_profile_and_the_data_of_every_registered_table(): void
    {
        $user = $this->givenUser();

        $export = $this->exportAccount([
            $this->table('consents', 'consents', [['type' => 'age18', 'version' => 'v1']]),
            $this->table('plans', 'plan', ['goalMode' => 'quit']),
        ])->execute($user->id);

        self::assertSame(
            [
                'formatVersion' => 1,
                'exportedAt' => '2026-11-17T12:00:00Z',
                'profile' => [
                    'id' => $user->id->value,
                    'email' => 'anna@example.org',
                    'displayName' => 'Stored Name',
                    'avatar' => 'avatar-07',
                    'locale' => 'de',
                    'timeZone' => 'Europe/Vienna',
                    'emailVerifiedAt' => '2026-11-01T08:00:00Z',
                    'createdAt' => '2026-11-01T07:00:00Z',
                ],
                'consents' => [['type' => 'age18', 'version' => 'v1']],
                'plan' => ['goalMode' => 'quit'],
            ],
            $export,
        );
    }

    public function test_NFR_05_the_export_never_contains_the_password_hash(): void
    {
        $user = $this->givenUser(password: 'a secret password');

        $export = $this->exportAccount([])->execute($user->id);

        $json = json_encode($export, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('a secret password', $json);
        self::assertStringNotContainsString('fake-v1', $json);
        self::assertStringNotContainsStringIgnoringCase('password', $json);
    }

    public function test_FR_ACC_07_times_are_written_in_utc_whatever_zone_they_are_held_in(): void
    {
        $user = $this->givenUser();
        $this->users->update($user->verifiedAt(new DateTimeImmutable('2026-11-01 23:30:00', new \DateTimeZone('Asia/Tokyo'))));
        $this->clock->travelTo(new DateTimeImmutable('2026-11-18 01:02:03', new \DateTimeZone('Europe/Berlin')));

        $export = $this->exportAccount([])->execute($user->id);

        self::assertSame('2026-11-18T00:02:03Z', $export['exportedAt']);
        self::assertIsArray($export['profile']);
        self::assertSame('2026-11-01T14:30:00Z', $export['profile']['emailVerifiedAt']);
    }

    public function test_FR_ACC_07_only_the_data_of_the_user_who_asks_is_exported(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $this->givenUser('bob@example.org');

        $export = $this->exportAccount([$this->table('plans', 'plan'), $this->table('consents', 'consents')])->execute($anna->id);

        self::assertIsArray($export['profile']);
        self::assertSame('anna@example.org', $export['profile']['email']);
        self::assertSame(["export plans {$anna->id->value}", "export consents {$anna->id->value}"], $this->log->getArrayCopy());
    }

    public function test_FR_ACC_07_the_whole_export_is_read_in_one_transaction(): void
    {
        // One snapshot of the database: a change that happens during the export cannot end up
        // half in the document.
        $user = $this->givenUser();
        $inside = [];
        $probe = new class (function () use (&$inside): void {
            $inside[] = $this->transactions->active;
        }) implements UserDataExporter {
            public function __construct(private readonly Closure $onExport)
            {
            }

            public function export(UserId $userId): array
            {
                ($this->onExport)();

                return [];
            }
        };
        $eraser = new RecordingUserData('x', $this->log);

        $this->exportAccount([new UserDataTable('plans', 'plan', $probe, $eraser), new UserDataTable('consents', 'consents', $probe, $eraser)])->execute($user->id);

        self::assertSame([true, true], $inside);
        self::assertSame([1, 0], [$this->transactions->commits, $this->transactions->rollbacks]);
    }

    public function test_FR_ACC_07_an_export_erases_nothing(): void
    {
        $user = $this->givenUser();

        $this->exportAccount([$this->table('plans', 'plan')])->execute($user->id);

        self::assertSame(["export plans {$user->id->value}"], $this->log->getArrayCopy());
        self::assertEquals($user, $this->users->find($user->id));
    }

    public function test_NFR_05_an_account_can_be_exported_five_times_an_hour(): void
    {
        $user = $this->givenUser();
        $other = $this->givenUser('bob@example.org');
        $export = $this->exportAccount([$this->table('plans', 'plan')]);

        for ($i = 1; $i <= 5; $i++) {
            $export->execute($user->id);
        }

        try {
            $export->execute($user->id);
            self::fail('Expected the sixth export of the hour to be refused.');
        } catch (RateLimitExceeded $refused) {
            // The window is the clock hour, and it is 12:00:00.
            self::assertSame(3600, $refused->retryAfterSeconds);
        }
        // Nothing was read for the refused request.
        self::assertCount(5, $this->log);
        self::assertSame(6, $this->hits(PrivacyLimits::exportsPerAccount(), $user->id->value));

        // The limit is per account, and over when the hour is.
        $export->execute($other->id);
        $this->clock->advanceSeconds(3600);
        $export->execute($user->id);
        self::assertCount(7, $this->log);
    }

    public function test_NFR_05_the_export_limit_is_five_per_hour_and_account(): void
    {
        $limit = PrivacyLimits::exportsPerAccount();

        self::assertSame(['export.account', 5, 3600], [$limit->name, $limit->maxHits, $limit->windowSeconds]);
    }

    public function test_FR_ACC_08_an_account_that_does_not_exist_any_more_is_reported_as_gone_and_not_counted(): void
    {
        $this->givenUser();

        try {
            $this->exportAccount([$this->table('plans', 'plan')])->execute(TestData::userId(999));
            self::fail('Expected the export to be refused.');
        } catch (AccountNotFound) {
        }

        // No counter may be left behind for an account that is gone.
        $this->assertNothingCounted();
        self::assertCount(0, $this->log);
    }

    public function test_FR_ACC_08_an_account_that_is_deleted_while_the_export_starts_is_reported_as_gone(): void
    {
        $user = $this->givenUser();
        // Deleted after the request was counted and before the snapshot was taken.
        $counters = $this->counters;
        $users = $this->users;
        $limiter = new RateLimiter(new class ($counters, $users, $user->id) implements \SmokeDetector\Application\RateLimit\RateLimitCounters {
            public function __construct(
                private readonly \SmokeDetector\Application\RateLimit\RateLimitCounters $inner,
                private readonly \SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository $users,
                private readonly UserId $userId,
            ) {
            }

            public function increment(string $key, DateTimeImmutable $windowStart): int
            {
                $this->users->delete($this->userId);

                return $this->inner->increment($key, $windowStart);
            }

            public function decrement(string $key, DateTimeImmutable $windowStart): void
            {
                $this->inner->decrement($key, $windowStart);
            }

            public function deleteWindowsStartedBefore(DateTimeImmutable $before): int
            {
                return $this->inner->deleteWindowsStartedBefore($before);
            }

            public function deleteKey(string $key): int
            {
                return $this->inner->deleteKey($key);
            }

            public function deleteKeysEndingWith(string $suffix): int
            {
                return $this->inner->deleteKeysEndingWith($suffix);
            }
        }, RateLimitKey::hasher());
        $export = new ExportAccount($this->transactions, $this->clock, $limiter, $this->users, new UserDataRegistry([$this->table('plans', 'plan')]));

        $this->expectException(AccountNotFound::class);

        $export->execute($user->id);
    }

    /**
     * @param list<UserDataTable> $tables
     */
    private function exportAccount(array $tables): ExportAccount
    {
        return new ExportAccount(
            $this->transactions,
            $this->clock,
            new RateLimiter($this->counters, RateLimitKey::hasher()),
            $this->users,
            new UserDataRegistry($tables),
        );
    }

    /**
     * @param array<array-key, mixed>|null $export
     */
    private function table(string $table, string $member, ?array $export = []): UserDataTable
    {
        $data = new RecordingUserData($table, $this->log, $export);

        return new UserDataTable($table, $member, $data, $data);
    }
}
