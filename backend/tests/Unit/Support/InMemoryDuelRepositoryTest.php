<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duels;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Tests\Support\Contract\DuelRepositoryContract;
use SmokeDetector\Tests\Support\InMemory\InMemoryDuels;
use SmokeDetector\Tests\Support\InMemory\InMemoryProgressRecords;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory duel repository behaves like the PDO one: both run the same contract tests (the
 * PDO one in tests/Integration/Infrastructure/Repository). The use case unit tests rest on that.
 */
final class InMemoryDuelRepositoryTest extends TestCase
{
    use DuelRepositoryContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryProgressRecords $progressRecords;
    private InMemoryDuels $duels;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->progressRecords = new InMemoryProgressRecords($this->transactions);
        $this->duels = new InMemoryDuels($this->progressRecords, $this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $this->duels->save(TestData::duel(10));
        $this->duels->save(TestData::duel(11, 2, 1));

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->duels->save(TestData::duel(10, status: DuelStatus::Declined, answeredAt: '2026-11-17 13:00:00'));
                $this->duels->delete(TestData::uuid(11));
                $this->duels->save(TestData::duel(12, 1, 3));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals([TestData::duel(10), TestData::duel(11, 2, 1)], $this->duels->all());
    }

    protected function duels(): Duels
    {
        return $this->duels;
    }

    protected function progressRecords(): ProgressRecords
    {
        return $this->progressRecords;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
