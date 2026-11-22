<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Auth;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\IdGenerator;
use Symfony\Component\Uid\UuidV7;

/**
 * UUID version 7 (HLD §4.3): the first 48 bits are the time in milliseconds, the rest is random.
 * Ids made later sort later, so new rows are appended to the primary key index instead of being
 * scattered over it.
 */
final readonly class UuidV7Generator implements IdGenerator
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function next(): string
    {
        return UuidV7::generate($this->clock->now());
    }
}
