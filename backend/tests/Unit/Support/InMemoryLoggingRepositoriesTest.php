<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Plan\PlanHistory;
use SmokeDetector\Application\Time\ZoneHistory;
use SmokeDetector\Tests\Support\Contract\LoggingRepositoriesContract;
use SmokeDetector\Tests\Support\InMemory\InMemoryDayRecords;
use SmokeDetector\Tests\Support\InMemory\InMemoryPlanHistory;
use SmokeDetector\Tests\Support\InMemory\InMemoryProgressRecords;
use SmokeDetector\Tests\Support\InMemory\InMemorySmokeLogs;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\InMemory\InMemoryXpLedger;
use SmokeDetector\Tests\Support\InMemory\InMemoryZoneHistory;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory repositories of step 4 behave like the PDO ones: both run the same contract tests
 * (the PDO ones in tests/Integration/Infrastructure/Repository). The use case unit tests rest on that.
 */
final class InMemoryLoggingRepositoriesTest extends TestCase
{
    use LoggingRepositoriesContract;

    private InMemoryTransactionRunner $transactions;
    private InMemorySmokeLogs $smokeLogs;
    private InMemoryDayRecords $dayRecords;
    private InMemoryProgressRecords $progressRecords;
    private InMemoryXpLedger $xpLedger;
    private InMemoryPlanHistory $planHistory;
    private InMemoryZoneHistory $zoneHistory;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->smokeLogs = new InMemorySmokeLogs($this->transactions);
        $this->dayRecords = new InMemoryDayRecords($this->transactions);
        $this->progressRecords = new InMemoryProgressRecords($this->transactions);
        $this->xpLedger = new InMemoryXpLedger($this->transactions);
        $this->planHistory = new InMemoryPlanHistory($this->transactions);
        $this->zoneHistory = new InMemoryZoneHistory($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $this->smokeLogs->save(TestData::smokeLog(10, 1, '2026-11-17', count: 2));
        $this->dayRecords->save(TestData::day(1, '2026-11-17', 2));
        $this->progressRecords->save(TestData::progress(1));
        $this->zoneHistory->record(TestData::userId(1), TestData::zoneChange('2026-11-17'));

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->smokeLogs->save(TestData::smokeLog(10, 1, '2026-11-17', count: 9));
                $this->smokeLogs->save(TestData::smokeLog(11, 1, '2026-11-17'));
                $this->dayRecords->save(TestData::day(1, '2026-11-17', 10, '2026-11-17 20:00:00'));
                $this->progressRecords->save(TestData::progress(1, totalXp: 500));
                $this->xpLedger->append(TestData::xpEvent(20));
                $this->planHistory->record(TestData::planTerms(1, '2026-11-17'));
                $this->zoneHistory->record(TestData::userId(1), TestData::zoneChange('2026-11-17', 'Asia/Tokyo'));
                $this->zoneHistory->record(TestData::userId(2), TestData::zoneChange('2026-11-17'));

                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);

        self::assertEquals([TestData::smokeLog(10, 1, '2026-11-17', count: 2)], $this->smokeLogs->all());
        self::assertEquals([TestData::day(1, '2026-11-17', 2)], $this->dayRecords->ofUser(TestData::userId(1)));
        self::assertEquals(TestData::progress(1), $this->progressRecords->find(TestData::userId(1)));
        self::assertSame([], $this->xpLedger->ofUser(TestData::userId(1)));
        self::assertSame([], $this->planHistory->ofUser(TestData::userId(1)));
        self::assertEquals([TestData::zoneChange('2026-11-17')], $this->zoneHistory->ofUser(TestData::userId(1)));
        self::assertSame([], $this->zoneHistory->ofUser(TestData::userId(2)));
    }

    protected function smokeLogs(): SmokeLogs
    {
        return $this->smokeLogs;
    }

    protected function dayRecords(): DayRecords
    {
        return $this->dayRecords;
    }

    protected function progressRecords(): ProgressRecords
    {
        return $this->progressRecords;
    }

    protected function xpLedger(): XpLedger
    {
        return $this->xpLedger;
    }

    protected function planHistory(): PlanHistory
    {
        return $this->planHistory;
    }

    protected function zoneHistory(): ZoneHistory
    {
        return $this->zoneHistory;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
