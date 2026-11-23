<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\LeagueResults;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\Contract\LeagueResultsContract;
use SmokeDetector\Tests\Support\InMemory\InMemoryLeagueResults;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory league results behave like the PDO ones: both run the same contract tests (the PDO
 * ones in tests/Integration/Infrastructure/Repository). The use case unit tests rest on that.
 */
final class InMemoryLeagueResultsTest extends TestCase
{
    use LeagueResultsContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryLeagueResults $leagueResults;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->leagueResults = new InMemoryLeagueResults($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $this->leagueResults->save(TestData::leagueResult(1));

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->leagueResults->save(TestData::leagueResult(1, award: [1, 3, 150]));
                $this->leagueResults->save(TestData::leagueResult(1, LeaguePeriod::Month, '2026-10'));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals([TestData::leagueResult(1)], $this->leagueResults->all());
    }

    protected function leagueResults(): LeagueResults
    {
        return $this->leagueResults;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
