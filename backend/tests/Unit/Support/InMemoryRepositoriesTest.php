<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use SmokeDetector\Tests\Support\Contract\MailOutboxContract;
use SmokeDetector\Tests\Support\Contract\PlanRepositoriesContract;
use SmokeDetector\Tests\Support\Contract\RateLimitCountersContract;
use SmokeDetector\Tests\Support\Contract\TokenRepositoriesContract;
use SmokeDetector\Tests\Support\Contract\UserRepositoryContract;
use SmokeDetector\Tests\Support\InMemory\InMemoryBaselineHistory;
use SmokeDetector\Tests\Support\InMemory\InMemoryConsentRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryOneTimeTokenRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryPlanRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryRateLimitCounters;
use SmokeDetector\Tests\Support\InMemory\InMemoryRefreshTokenRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory repositories behave like the PDO ones: both run the same contract tests (the PDO
 * ones in tests/Integration/Infrastructure/Repository). The use case unit tests rest on that.
 */
final class InMemoryRepositoriesTest extends TestCase
{
    use UserRepositoryContract;
    use TokenRepositoriesContract;
    use RateLimitCountersContract;
    use PlanRepositoriesContract;
    use MailOutboxContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryUserRepository $users;
    private InMemoryConsentRepository $consents;
    private InMemoryOneTimeTokenRepository $oneTimeTokens;
    private InMemoryRefreshTokenRepository $refreshTokens;
    private InMemoryRateLimitCounters $counters;
    private InMemoryPlanRepository $plans;
    private InMemoryBaselineHistory $baselines;
    private InMemoryMailOutbox $outbox;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->users = new InMemoryUserRepository($this->transactions);
        $this->consents = new InMemoryConsentRepository($this->transactions);
        $this->oneTimeTokens = new InMemoryOneTimeTokenRepository($this->transactions);
        $this->refreshTokens = new InMemoryRefreshTokenRepository($this->transactions);
        $this->counters = new InMemoryRateLimitCounters($this->transactions);
        $this->plans = new InMemoryPlanRepository($this->transactions);
        $this->baselines = new InMemoryBaselineHistory($this->transactions);
        $this->outbox = new InMemoryMailOutbox($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $this->users->add(TestData::user(1));

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->users->add(TestData::user(2));
                $this->oneTimeTokens->put(TestData::oneTimeToken(10, TestData::userId(2), 'token'));
                $this->refreshTokens->add(TestData::session(20, TestData::userId(2), 'key', 'secret'));
                $this->consents->replaceAll(TestData::userId(1), []);
                $this->plans->save(TestData::plan(2));
                $this->baselines->append(TestData::baseline(2, '2026-11-17', 120));
                $this->outbox->add(TestData::queuedMail(30, 2));

                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }
        self::assertSame('failed', $passedOn);

        self::assertEquals([TestData::user(1)], $this->users->all());
        self::assertSame([], $this->oneTimeTokens->all());
        self::assertSame([], $this->refreshTokens->all());
        self::assertNull($this->plans->find(TestData::userId(2)));
        self::assertSame([], $this->baselines->ofUser(TestData::userId(2)));
        self::assertSame([], $this->outbox->all());
        self::assertSame(1, $this->transactions->rollbacks);
        self::assertSame(0, $this->transactions->commits);
        self::assertFalse($this->transactions->active);
    }

    public function test_a_committed_transaction_keeps_what_it_stored_and_returns_the_result(): void
    {
        $result = $this->transactions->run(function (): string {
            $this->users->add(TestData::user(1));

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertCount(1, $this->users->all());
        self::assertSame(1, $this->transactions->commits);
    }

    public function test_transactions_cannot_be_nested_like_in_the_database(): void
    {
        $this->expectException(LogicException::class);

        $this->transactions->run(fn (): mixed => $this->transactions->run(static fn (): int => 1));
    }

    public function test_a_row_can_only_be_locked_inside_a_transaction(): void
    {
        foreach ([
            fn (): mixed => $this->users->lock(TestData::userId(1)),
            fn (): mixed => $this->oneTimeTokens->lockByHash(TokenPurpose::VerifyEmail, 'x'),
            fn (): mixed => $this->refreshTokens->lock('x'),
            fn (): mixed => $this->outbox->claimDue(TestData::time('2026-11-17 12:00:00'), 10, TestData::time('2026-11-17 12:10:00')),
        ] as $lock) {
            try {
                $lock();
                self::fail('Expected locking outside a transaction to be refused.');
            } catch (LogicException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_rate_limit_hits_cannot_be_counted_inside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        $this->transactions->run(fn (): int => $this->counters->increment('k', TestData::time('2026-11-17 12:00:00')));
    }

    protected function users(): UserRepository
    {
        return $this->users;
    }

    protected function consents(): ConsentRepository
    {
        return $this->consents;
    }

    protected function oneTimeTokens(): OneTimeTokenRepository
    {
        return $this->oneTimeTokens;
    }

    protected function refreshTokens(): RefreshTokenRepository
    {
        return $this->refreshTokens;
    }

    protected function counters(): RateLimitCounters
    {
        return $this->counters;
    }

    protected function plans(): PlanRepository
    {
        return $this->plans;
    }

    protected function baselines(): BaselineHistory
    {
        return $this->baselines;
    }

    protected function outbox(): MailOutbox
    {
        return $this->outbox;
    }

    protected function storedMails(): array
    {
        $mails = $this->outbox->all();
        usort($mails, static fn ($a, $b): int => $a->id <=> $b->id);

        return $mails;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // Nothing to do: the in-memory repositories have no foreign keys.
    }

    protected function transaction(callable $work): mixed
    {
        return $this->transactions->run($work);
    }
}
