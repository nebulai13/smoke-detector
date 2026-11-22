<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use RuntimeException;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use Throwable;

/**
 * The rate limit counters, in memory. Behaves like PdoRateLimitCounters (both pass
 * RateLimitCountersContract). Not part of any transaction, and refuses to be called inside one.
 */
final class InMemoryRateLimitCounters implements RateLimitCounters
{
    /** @var array<string, array<int, int>> Key => window start (Unix time) => hits. */
    private array $hits = [];

    /** When set, every decrement() fails with it: a database that went away after the use case's commit. */
    public ?Throwable $failDecrement = null;

    /** When set, every deleteKey() fails with it. */
    public ?Throwable $failDeleteKey = null;

    /** That many of the next deleteKeysEndingWith() calls fail with a RuntimeException. */
    public int $failNextSuffixDeletes = 0;

    public function __construct(private readonly ?InMemoryTransactionRunner $transactions = null)
    {
    }

    public function increment(string $key, DateTimeImmutable $windowStart): int
    {
        $this->transactions?->assertNotActive('RateLimitCounters::increment()');

        $this->hits[$key][$windowStart->getTimestamp()] = $this->hits($key, $windowStart) + 1;

        return $this->hits($key, $windowStart);
    }

    public function decrement(string $key, DateTimeImmutable $windowStart): void
    {
        $this->transactions?->assertNotActive('RateLimitCounters::decrement()');
        if ($this->failDecrement !== null) {
            throw $this->failDecrement;
        }

        if ($this->hits($key, $windowStart) > 0) {
            $this->hits[$key][$windowStart->getTimestamp()]--;
        }
    }

    public function deleteWindowsStartedBefore(DateTimeImmutable $before): int
    {
        $deleted = 0;
        foreach ($this->hits as $key => $windows) {
            foreach (array_keys($windows) as $start) {
                if ($start < $before->getTimestamp()) {
                    unset($this->hits[$key][$start]);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    public function deleteKey(string $key): int
    {
        $this->transactions?->assertNotActive('RateLimitCounters::deleteKey()');
        if ($this->failDeleteKey !== null) {
            throw $this->failDeleteKey;
        }

        $deleted = count($this->hits[$key] ?? []);
        unset($this->hits[$key]);

        return $deleted;
    }

    public function deleteKeysEndingWith(string $suffix): int
    {
        $this->transactions?->assertNotActive('RateLimitCounters::deleteKeysEndingWith()');
        if ($this->failNextSuffixDeletes > 0) {
            $this->failNextSuffixDeletes--;

            throw new RuntimeException('The counters could not be deleted.');
        }

        $deleted = 0;
        foreach ($this->hits as $key => $windows) {
            if (str_ends_with((string) $key, $suffix)) {
                $deleted += count($windows);
                unset($this->hits[$key]);
            }
        }

        return $deleted;
    }

    public function hits(string $key, DateTimeImmutable $windowStart): int
    {
        return $this->hits[$key][$windowStart->getTimestamp()] ?? 0;
    }

    /**
     * @return array<string, array<int, int>> Key => window start (Unix time) => hits, without empty counters.
     */
    public function all(): array
    {
        return array_filter(array_map(
            static fn (array $windows): array => array_filter($windows, static fn (int $hits): bool => $hits > 0),
            $this->hits,
        ));
    }
}
