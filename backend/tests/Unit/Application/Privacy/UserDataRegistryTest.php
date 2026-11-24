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

    public function test_FR_ACC_07_a_table_whose_rows_a_user_has_in_two_roles_gives_the_export_a_second_member(): void
    {
        // A nudge the user sent and a nudge the user received are rows of one table (HLD §4.14).
        $sent = new RecordingUserData('nudges sent', $this->log, [['userId' => 'b']]);
        $received = new RecordingUserData('nudges received', $this->log, [['userId' => 'c', 'delivered' => true]]);
        $registry = new UserDataRegistry([
            $this->table('consents', 'consents', [['type' => 'age18']]),
            new UserDataTable('nudges', 'nudgesSent', $sent, $sent, ['nudgesReceived' => $received]),
            $this->table('plans', 'plan', null),
        ]);

        $export = $registry->export(TestData::userId(7));

        // In the order of registration, the members of one table in their order. Null leaves a member out.
        self::assertSame(
            ['consents' => [['type' => 'age18']], 'nudgesSent' => [['userId' => 'b']], 'nudgesReceived' => [['userId' => 'c', 'delivered' => true]]],
            $export,
        );
        self::assertSame(['consents', 'nudgesSent', 'nudgesReceived'], array_keys($export));
        self::assertSame(
            ['export consents ' . TestData::uuid(7), 'export nudges sent ' . TestData::uuid(7), 'export nudges received ' . TestData::uuid(7), 'export plans ' . TestData::uuid(7)],
            $this->log->getArrayCopy(),
        );

        // The table is erased once, by its own eraser.
        $this->log->exchangeArray([]);
        $registry->erase(TestData::userId(7));
        self::assertSame(['erase consents ' . TestData::uuid(7), 'erase nudges sent ' . TestData::uuid(7), 'erase plans ' . TestData::uuid(7)], $this->log->getArrayCopy());
        self::assertSame(['consents', 'nudges', 'plans'], $registry->tables());
    }

    public function test_FR_ACC_07_a_second_member_that_has_nothing_to_export_as_one_object_is_left_out(): void
    {
        $first = new RecordingUserData('settings', $this->log, ['milestoneSharing' => 'all']);
        $second = new RecordingUserData('settings history', $this->log, null);

        self::assertSame(
            ['settings' => ['milestoneSharing' => 'all']],
            (new UserDataRegistry([new UserDataTable('user_settings', 'settings', $first, $first, ['settingsHistory' => $second])]))->export(TestData::userId(1)),
        );
    }

    public function test_a_second_member_cannot_have_the_name_of_the_first(): void
    {
        // It would replace the first one in the export without a word.
        $data = new RecordingUserData('nudges', $this->log);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The export member "nudges" is used twice.');

        new UserDataTable('nudges', 'nudges', $data, $data, ['nudges' => $data]);
    }

    public function test_a_second_member_cannot_be_the_member_of_another_table(): void
    {
        $data = new RecordingUserData('nudges', $this->log);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The export member "sessions" is used twice.');

        new UserDataRegistry([$this->table('refresh_tokens', 'sessions'), new UserDataTable('nudges', 'nudgesSent', $data, $data, ['sessions' => $data])]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSecondMembers(): iterable
    {
        yield 'reserved' => ['profile'];
        yield 'in snake case' => ['nudges_received'];
        yield 'starting in upper case' => ['NudgesReceived'];
        yield 'empty' => [''];
    }

    #[DataProvider('badSecondMembers')]
    public function test_a_second_member_follows_the_rules_of_every_member(string $member): void
    {
        $data = new RecordingUserData('nudges', $this->log);

        $this->expectException(InvalidArgumentException::class);

        new UserDataTable('nudges', 'nudgesSent', $data, $data, [$member => $data]);
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
