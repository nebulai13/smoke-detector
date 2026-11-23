<?php

declare(strict_types=1);

namespace SmokeDetector\Application\RateLimit;

use DateTimeImmutable;

/**
 * The stored counters (HLD §4.3 "rate_limits"): one per key and window.
 *
 * Counting must not be part of a use case's transaction: a hit has to stay counted when the
 * transaction is rolled back.
 */
interface RateLimitCounters
{
    /**
     * Adds one hit, as one step that cannot be interleaved with another request's.
     *
     * @return int The hits in that window now, this one included. Under concurrency it may already
     *             include later hits, never fewer.
     */
    public function increment(string $key, DateTimeImmutable $windowStart): int;

    /**
     * Takes one hit back. A counter never goes below zero.
     */
    public function decrement(string $key, DateTimeImmutable $windowStart): void;

    /**
     * @return int How many counters were deleted.
     */
    public function deleteWindowsStartedBefore(DateTimeImmutable $before): int;

    /**
     * Deletes the counters, of every window, that have exactly this key. Like counting, not part of
     * any transaction.
     *
     * @return int How many counters were deleted.
     */
    public function deleteKey(string $key): int;

    /**
     * Deletes the counters, of every window, whose key ends with exactly this text. For erasing
     * what was counted about one subject (FR-ACC-08): its keys of all rules end with its hash.
     * Like counting, not part of any transaction.
     *
     * @return int How many counters were deleted.
     */
    public function deleteKeysEndingWith(string $suffix): int;
}
