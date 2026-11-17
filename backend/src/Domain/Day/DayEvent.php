<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Day;

use InvalidArgumentException;

/**
 * One celebration-worthy thing that happened when a day was finalized.
 */
final readonly class DayEvent
{
    public function __construct(
        public DayEventType $type,
        /** A length, count or level. Its meaning is documented on each DayEventType case. */
        public ?int $value = null,
    ) {
        if ($value !== null && $value < 0) {
            throw new InvalidArgumentException('A day event value cannot be negative.');
        }
    }
}
