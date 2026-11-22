<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

/**
 * The answer to one item of a sync: accepted (no rejection), or why it was not stored.
 */
final readonly class ItemResult
{
    public function __construct(
        public string $id,
        public ?Rejection $rejection,
    ) {
    }
}
