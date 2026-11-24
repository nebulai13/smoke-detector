<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Application\Feed\Reactions;
use SmokeDetector\Tests\Support\Contract\ReactionsContract;
use SmokeDetector\Tests\Support\Fake\SequenceFeedIds;
use SmokeDetector\Tests\Support\InMemory\InMemoryReactions;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory reactions behave like the PDO ones: both run the same contract tests (the PDO ones in
 * tests/Integration/Infrastructure/Repository).
 */
final class InMemoryReactionsTest extends TestCase
{
    use ReactionsContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryReactions $reactions;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->reactions = new InMemoryReactions($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $kept = new Reaction(SequenceFeedIds::id(1), TestData::userId(2), ReactionKey::Cheer, TestData::time('2026-11-17 12:00:00'));
        $this->reactions->save($kept);

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->reactions->save(new Reaction(SequenceFeedIds::id(1), TestData::userId(2), ReactionKey::Strong, TestData::time('2026-11-18 12:00:00')));
                $this->reactions->save(new Reaction(SequenceFeedIds::id(2), TestData::userId(2), ReactionKey::Cheer, TestData::time('2026-11-18 12:00:00')));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals([$kept], $this->reactions->all());
    }

    protected function reactions(): Reactions
    {
        return $this->reactions;
    }

    protected function givenUsersAndEvents(int $users, int $events): void
    {
        // In memory, rows can exist without an account or an entry.
    }
}
