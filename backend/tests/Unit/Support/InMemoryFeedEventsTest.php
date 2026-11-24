<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEvents;
use SmokeDetector\Application\Feed\FeedEventType;
use SmokeDetector\Tests\Support\Contract\FeedEventsContract;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\InMemory\InMemoryFeedEvents;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory feed entries behave like the PDO ones: both run the same contract tests (the PDO
 * ones in tests/Integration/Infrastructure/Repository).
 */
final class InMemoryFeedEventsTest extends TestCase
{
    use FeedEventsContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryFeedEvents $feedEvents;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->feedEvents = new InMemoryFeedEvents($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $kept = new FeedEvent(SequenceFeedIds::id(1), TestData::userId(1), FeedEventType::LevelUp, '1', null, TestData::time('2026-11-17 12:00:00'));
        $this->feedEvents->append($kept);

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->feedEvents->append(new FeedEvent(SequenceFeedIds::id(2), TestData::userId(1), FeedEventType::LevelUp, '2', null, TestData::time('2026-11-17 12:00:00')));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals([$kept], $this->feedEvents->all());
    }

    protected function feedEvents(): FeedEvents
    {
        return $this->feedEvents;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
