<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use PDOException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Plan\PlanHistory;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\ZoneHistory;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoDayRecords;
use SmokeDetector\Infrastructure\Repository\PdoPlanHistory;
use SmokeDetector\Infrastructure\Repository\PdoProgressRecords;
use SmokeDetector\Infrastructure\Repository\PdoSmokeLogs;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Infrastructure\Repository\PdoXpLedger;
use SmokeDetector\Infrastructure\Repository\PdoZoneHistory;
use SmokeDetector\Tests\Support\Contract\LoggingRepositoriesContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The repositories of step 4 against the real database: the contract every implementation passes,
 * and what only the database can show (the columns, and that no row outlives its account).
 */
final class PdoLoggingRepositoriesTest extends IntegrationTestCase
{
    use LoggingRepositoriesContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_LOG_02_a_log_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->smokeLogs()->save(TestData::smokeLog(10, 1, '2026-11-18', '2026-11-17 23:30:00', 3, ProductType::HeatedTobacco, '2026-11-18 07:00:00', '2026-11-18 09:00:00', '2026-11-18 09:00:00'));

        self::assertSame(
            [[
                'id' => TestData::uuid(10), 'user_id' => TestData::uuid(1), 'smoked_at' => '2026-11-17 23:30:00', 'local_date' => '2026-11-18',
                'product_type' => 'heated_tobacco', 'count' => 3, 'created_at' => '2026-11-18 07:00:00', 'updated_at' => '2026-11-18 09:00:00',
                'deleted_at' => '2026-11-18 09:00:00',
            ]],
            $this->sql()->rows('SELECT * FROM smoke_logs'),
        );
    }

    public function test_FR_LOG_04_a_day_is_stored_in_the_columns_of_the_design(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->dayRecords()->save(TestData::day(1, '2026-11-17', 4));
        $this->dayRecords()->save(TestData::day(1, '2026-11-18', 2, '2026-11-18 21:45:10', TestData::dayResult(
            9,
            80,
            [new DayEvent(DayEventType::CheckedIn), new DayEvent(DayEventType::UnderLimitDay, 3), new DayEvent(DayEventType::LevelUp, 0)],
            true,
            '2026-11-20 23:00:41',
        )));

        self::assertSame(
            [
                [
                    'user_id' => TestData::uuid(1), 'local_date' => '2026-11-17', 'count' => 4, 'checked_in_at' => null, 'daily_limit' => null,
                    'xp' => null, 'events' => null, 'freeze_used' => null, 'finalized_at' => null, 'rules_version' => null,
                ],
                [
                    'user_id' => TestData::uuid(1), 'local_date' => '2026-11-18', 'count' => 2, 'checked_in_at' => '2026-11-18 21:45:10', 'daily_limit' => 9,
                    'xp' => 80, 'events' => '[{"type":"checked_in"},{"type":"under_limit_day","value":3},{"type":"level_up","value":0}]',
                    'freeze_used' => 1, 'finalized_at' => '2026-11-20 23:00:41', 'rules_version' => 'v1',
                ],
            ],
            $this->sql()->rows('SELECT * FROM day_summaries ORDER BY local_date'),
        );
    }

    public function test_FR_GAME_02_progress_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->progressRecords()->save(TestData::progress(1, [12, 40, 3, 21, 2], 123456, 70, '2027-02-28', '2027-03-02 23:00:00', '2027-03-07 23:00:00'));

        self::assertSame(
            [[
                'user_id' => TestData::uuid(1), 'check_in_current' => 12, 'check_in_longest' => 40, 'under_limit_current' => 3, 'under_limit_longest' => 21,
                'freezes_held' => 2, 'xp_total' => 123456, 'level' => 70, 'next_day' => '2027-02-28', 'finalize_due_at' => '2027-03-02 23:00:00',
                'baseline_due_at' => '2027-03-07 23:00:00',
            ]],
            $this->sql()->rows('SELECT * FROM user_progress'),
        );
    }

    public function test_the_ledger_and_the_histories_are_stored_in_the_columns_of_the_design(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->xpLedger()->append(TestData::xpEvent(20, 1, '2026-11-18', XpSource::StreakMilestone, 50, 7, '2026-11-20 23:00:45'));
        $this->xpLedger()->append(TestData::xpEvent(21, 1, '2026-11-18', XpSource::CheckIn, 10));
        $this->planHistory()->record(TestData::planTerms(1, '2026-11-17', Goal::quit(), TaperSpeed::Fast));
        $this->zoneHistory()->record(TestData::userId(1), TestData::zoneChange('2026-11-17', 'America/New_York'));

        self::assertSame(
            [
                ['id' => TestData::uuid(20), 'user_id' => TestData::uuid(1), 'local_date' => '2026-11-18', 'source' => 'streak_milestone', 'amount' => 50, 'detail' => 7, 'created_at' => '2026-11-20 23:00:45'],
                ['id' => TestData::uuid(21), 'user_id' => TestData::uuid(1), 'local_date' => '2026-11-18', 'source' => 'check_in', 'amount' => 10, 'detail' => null, 'created_at' => '2026-11-20 23:00:30'],
            ],
            $this->sql()->rows('SELECT * FROM xp_events ORDER BY id'),
        );
        self::assertSame(
            [['user_id' => TestData::uuid(1), 'valid_from' => '2026-11-17', 'goal_mode' => 'quit', 'target' => 0, 'taper_speed' => 'fast', 'rules_version' => 'v1']],
            $this->sql()->rows('SELECT * FROM plan_history'),
        );
        self::assertSame(
            [['user_id' => TestData::uuid(1), 'valid_from' => '2026-11-17', 'timezone' => 'America/New_York']],
            $this->sql()->rows('SELECT * FROM timezone_history'),
        );
    }

    public function test_FR_ACC_08_no_row_can_exist_without_its_account_and_none_outlives_it(): void
    {
        $writes = [
            'smoke_logs' => fn (int $user) => $this->smokeLogs()->save(TestData::smokeLog(10 + $user, $user)),
            'day_summaries' => fn (int $user) => $this->dayRecords()->save(TestData::day($user, '2026-11-17', 1)),
            'user_progress' => fn (int $user) => $this->progressRecords()->save(TestData::progress($user)),
            'xp_events' => fn (int $user) => $this->xpLedger()->append(TestData::xpEvent(20 + $user, $user)),
            'plan_history' => fn (int $user) => $this->planHistory()->record(TestData::planTerms($user, '2026-11-17')),
            'timezone_history' => fn (int $user) => $this->zoneHistory()->record(TestData::userId($user), TestData::zoneChange('2026-11-17')),
        ];
        $this->givenUsers(TestData::userId(1), TestData::userId(2));

        foreach ($writes as $table => $write) {
            $write(1);
            $write(2);
            try {
                $write(3);
                self::fail("A row in {$table} was stored for an account that does not exist.");
            } catch (PDOException) {
            }
        }
        (new PdoUserRepository(new Sql($this->database)))->delete(TestData::userId(1));

        foreach (array_keys($writes) as $table) {
            // Fixed names from the list above.
            self::assertSame([TestData::uuid(2)], $this->sql()->column("SELECT user_id FROM `{$table}`"), $table);
        }
    }

    protected function smokeLogs(): SmokeLogs
    {
        return new PdoSmokeLogs(new Sql($this->database));
    }

    protected function dayRecords(): DayRecords
    {
        return new PdoDayRecords(new Sql($this->database));
    }

    protected function progressRecords(): ProgressRecords
    {
        return new PdoProgressRecords(new Sql($this->database));
    }

    protected function xpLedger(): XpLedger
    {
        return new PdoXpLedger(new Sql($this->database));
    }

    protected function planHistory(): PlanHistory
    {
        return new PdoPlanHistory(new Sql($this->database));
    }

    protected function zoneHistory(): ZoneHistory
    {
        return new PdoZoneHistory(new Sql($this->database));
    }

    protected function givenUsers(UserId ...$ids): void
    {
        $users = new PdoUserRepository(new Sql($this->database));
        foreach ($ids as $id) {
            if ($users->find($id) === null) {
                $users->add(TestData::user((int) substr($id->value, -12)));
            }
        }
    }
}
