<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use LogicException;
use SmokeDetector\Application\TransactionRunner;
use Throwable;

/**
 * Transactions for unit tests, with the behaviour of PdoTransactionRunner that use cases rely on:
 * what the work stored is undone when it throws, and nesting is refused.
 */
final class InMemoryTransactionRunner implements TransactionRunner
{
    /** Whether a transaction is running right now. */
    public bool $active = false;

    /** How many transactions were committed. */
    public int $commits = 0;

    /** How many transactions were rolled back. */
    public int $rollbacks = 0;

    /** @var list<string> The tables whose rows were locked, in order ("users", "refresh_tokens", …). */
    public array $locks = [];

    /** @var list<InMemoryState> */
    private array $participants = [];

    public function enlist(InMemoryState $repository): void
    {
        $this->participants[] = $repository;
    }

    public function run(callable $work): mixed
    {
        if ($this->active) {
            throw new LogicException('A transaction is already running. Only the use case starts one.');
        }

        $snapshots = array_map(static fn (InMemoryState $state): array => $state->snapshot(), $this->participants);
        $this->active = true;
        try {
            $result = $work();
        } catch (Throwable $failure) {
            foreach ($this->participants as $index => $participant) {
                $participant->restore($snapshots[$index]);
            }
            $this->rollbacks++;

            throw $failure;
        } finally {
            $this->active = false;
        }
        $this->commits++;

        return $result;
    }

    /**
     * For repository methods that lock a row: they only make sense inside a transaction. Notes the
     * table, so that a test can check the order the locks are taken in.
     */
    public function lock(string $table, string $what): void
    {
        $this->assertActive($what);
        $this->locks[] = $table;
    }

    public function assertActive(string $what): void
    {
        if (!$this->active) {
            throw new LogicException("{$what} must be called inside a transaction.");
        }
    }

    /**
     * For what must survive a rollback or must not happen before the commit: counting a rate limit
     * hit, sending a mail.
     */
    public function assertNotActive(string $what): void
    {
        if ($this->active) {
            throw new LogicException("{$what} must not be called inside a transaction.");
        }
    }
}
