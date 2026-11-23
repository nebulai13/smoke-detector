<?php

declare(strict_types=1);

namespace SmokeDetector\Application\RateLimit;

use InvalidArgumentException;

/**
 * One rule: at most so many hits per subject in a fixed window of time (HLD §4.5 "Rate limits").
 */
final readonly class RateLimit
{
    public function __construct(
        /** Names the counter, e.g. "login.address". Two rules with one name share their counters. */
        public string $name,
        public int $maxHits,
        public int $windowSeconds,
    ) {
        if (preg_match('/\A[a-z]+(?:\.[a-z]+)*\z/', $name) !== 1 || strlen($name) > 64) {
            throw new InvalidArgumentException('A rate limit name is made of lower-case words joined by dots.');
        }
        if ($maxHits < 1) {
            throw new InvalidArgumentException('A rate limit allows at least one hit.');
        }
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('A rate limit window is at least one second long.');
        }
    }
}
