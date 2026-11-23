<?php

declare(strict_types=1);

namespace SmokeDetector\Application\RateLimit;

use RuntimeException;

/**
 * Too many hits in the current window. Answered as 429 "rate_limited" with Retry-After.
 */
final class RateLimitExceeded extends RuntimeException
{
    public function __construct(
        /** Until the window ends. At least 1. */
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('Too many requests.');
    }
}
