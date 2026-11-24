<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Nudge\Nudge;
use SmokeDetector\Application\Nudge\Nudges;
use SmokeDetector\Tests\Support\Contract\NudgesContract;
use SmokeDetector\Tests\Support\InMemory\InMemoryNudges;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory nudges behave like the PDO ones: both run the same contract tests (the PDO ones in
 * tests/Integration/Infrastructure/Repository).
 */
final class InMemoryNudgesTest extends TestCase
{
    use NudgesContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryNudges $nudges;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->nudges = new InMemoryNudges($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $kept = new Nudge(TestData::userId(1), TestData::userId(2), TestData::date('2026-11-17'), TestData::time('2026-11-17 12:00:00'), true);
        $this->nudges->add($kept);

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->nudges->add(new Nudge(TestData::userId(1), TestData::userId(3), TestData::date('2026-11-17'), TestData::time('2026-11-17 12:00:00'), true));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals([$kept], $this->nudges->all());
    }

    protected function nudges(): Nudges
    {
        return $this->nudges;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
