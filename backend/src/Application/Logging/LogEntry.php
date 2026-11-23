<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

use DateTimeImmutable;
use SmokeDetector\Application\Plan\ProductType;

/**
 * What a log item that is no deletion says (HLD §4.6 "POST /logs/sync"): sessions of a product,
 * smoked at one moment.
 */
final readonly class LogEntry
{
    public function __construct(
        /** In UTC, to the second. */
        public DateTimeImmutable $smokedAt,
        public ProductType $productType,
        public int $count,
    ) {
    }
}
