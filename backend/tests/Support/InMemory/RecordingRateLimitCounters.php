<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use SmokeDetector\Application\RateLimit\RateLimitCounters;

/**
 * Notes the window starts it is called with, exactly as they arrive (time zone included), and the
 * key endings it is asked to delete.
 */
final class RecordingRateLimitCounters implements RateLimitCounters
{
    /** @var list<string> */
    public array $windowStarts = [];

    /** @var list<string> What deleteKeysEndingWith() was called with. */
    public array $deletedSuffixes = [];

    public function increment(string $key, DateTimeImmutable $windowStart): int
    {
        $this->windowStarts[] = $windowStart->format('Y-m-d H:i:s T');

        return 1;
    }

    public function decrement(string $key, DateTimeImmutable $windowStart): void
    {
        $this->windowStarts[] = $windowStart->format('Y-m-d H:i:s T');
    }

    public function deleteWindowsStartedBefore(DateTimeImmutable $before): int
    {
        return 0;
    }

    public function deleteKey(string $key): int
    {
        return 0;
    }

    public function deleteKeysEndingWith(string $suffix): int
    {
        $this->deletedSuffixes[] = $suffix;

        return 0;
    }
}
