<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Tests\Support\Contract\BadgeRecordsContract;
use SmokeDetector\Tests\Support\InMemory\InMemoryBadgeRecords;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory badge records behave like the PDO ones: both run the same contract tests (the PDO
 * ones in tests/Integration/Infrastructure/Repository). The use case unit tests rest on that.
 */
final class InMemoryBadgeRecordsTest extends TestCase
{
    use BadgeRecordsContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryBadgeRecords $badgeRecords;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->badgeRecords = new InMemoryBadgeRecords($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $this->badgeRecords->add(TestData::badge(1, Badge::FirstCheckIn));

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->badgeRecords->add(TestData::badge(1, Badge::CheckInStreak7));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals([TestData::badge(1, Badge::FirstCheckIn)], $this->badgeRecords->all());
    }

    protected function badgeRecords(): BadgeRecords
    {
        return $this->badgeRecords;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
