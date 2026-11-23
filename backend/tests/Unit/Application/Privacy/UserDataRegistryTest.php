<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Privacy;

use ArrayObject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\Privacy\UserDataTable;
use SmokeDetector\Tests\Support\Fake\RecordingUserData;
use SmokeDetector\Tests\Support\TestData;

/**
 * The list of every table that holds data of a user, each with its exporter and its eraser
 * (HLD §4.5 "Account", FR-ACC-07, FR-ACC-08).
 */
final class UserDataRegistryTest extends TestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->log = new ArrayObject();
    }

    public function test_FR_ACC_07_the_export_has_one_member_per_table_in_the_order_of_registration(): void
    {
        $registry = new UserDataRegistry([
            $this->table('consents', 'consents', [['type' => 'age18']]),
            $this->table('plans', 'plan', ['goalMode' => 'quit']),
            $this->table('smoke_logs', 'smokeLogs', []),
        ]);

        $export = $registry->export(TestData::userId(7));

        self::assertSame(
            ['consents' => [['type' => 'age18']], 'plan' => ['goalMode' => 'quit'], 'smokeLogs' => []],
            $export,
        );
        self::assertSame(['consents', 'plan', 'smokeLogs'], array_keys($export));
        self::assertSame(
            ['export consents ' . TestData::uuid(7), 'export plans ' . TestData::uuid(7), 'export smoke_logs ' . TestData::uuid(7)],
            $this->log->getArrayCopy(),
        );
    }

    public function test_FR_ACC_07_a_table_that_has_no_row_to_export_as_one_object_is_left_out(): void
    {
        $registry = new UserDataRegistry([$this->table('plans', 'plan', null), $this->table('consents', 'consents', [])]);

        // An empty list is the answer "no entries". Null is "there is no such object".
        self::assertSame(['consents' => []], $registry->export(TestData::userId(1)));
    }

    public function test_FR_ACC_08_erasing_runs_the_eraser_of_every_table_in_the_order_of_registration(): void
    {
        $registry = new UserDataRegistry([
            $this->table('reactions', 'reactions'),
            $this->table('feed_events', 'feedEvents'),
            $this->table('plans', 'plan'),
        ]);

        $registry->erase(TestData::userId(7));

        self::assertSame(
            ['erase reactions ' . TestData::uuid(7), 'erase feed_events ' . TestData::uuid(7), 'erase plans ' . TestData::uuid(7)],
            $this->log->getArrayCopy(),
        );
    }

    public function test_the_registry_names_its_tables(): void
    {
        $registry = new UserDataRegistry([$this->table('refresh_tokens', 'sessions'), $this->table('plans', 'plan')]);

        self::assertSame(['refresh_tokens', 'plans'], $registry->tables());
        self::assertSame([], (new UserDataRegistry([]))->tables());
        self::assertSame([], (new UserDataRegistry([]))->export(TestData::userId(1)));
    }

    public function test_a_table_cannot_be_registered_twice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The table "plans" is registered twice.');

        new UserDataRegistry([$this->table('plans', 'plan'), $this->table('consents', 'consents'), $this->table('plans', 'plans')]);
    }

    public function test_two_tables_cannot_share_a_member_of_the_export(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The export member "sessions" is used twice.');

        new UserDataRegistry([$this->table('refresh_tokens', 'sessions'), $this->table('devices', 'sessions')]);
    }

    public function test_the_account_itself_is_not_a_registered_table(): void
    {
        // It is exported as the profile and deleted last, after everything that refers to it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The table "users" is the account itself and cannot be registered.');

        new UserDataRegistry([$this->table('users', 'users')]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedMembers(): iterable
    {
        yield 'formatVersion' => ['formatVersion'];
        yield 'exportedAt' => ['exportedAt'];
        yield 'profile' => ['profile'];
    }

    #[DataProvider('reservedMembers')]
    public function test_a_table_cannot_take_a_member_the_export_document_has_itself(string $member): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The export member \"{$member}\" is reserved.");

        $this->table('plans', $member);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badNames(): iterable
    {
        yield 'empty table' => ['', 'plan'];
        yield 'table in upper case' => ['Plans', 'plan'];
        yield 'table with a space' => ['plans x', 'plan'];
        yield 'table with a backtick' => ['pl`ans', 'plan'];
        yield 'table starting with a digit' => ['1plans', 'plan'];
        yield 'table with a trailing line break' => ["plans\n", 'plan'];
        yield 'empty member' => ['plans', ''];
        yield 'member in snake case' => ['plans', 'smoke_logs'];
        yield 'member starting in upper case' => ['plans', 'Plan'];
        yield 'member with a dot' => ['plans', 'plan.x'];
        yield 'member with a trailing line break' => ['plans', "plan\n"];
    }

    #[DataProvider('badNames')]
    public function test_table_names_are_snake_case_and_export_members_camel_case(string $table, string $member): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->table($table, $member);
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
