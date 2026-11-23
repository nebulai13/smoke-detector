<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Feed;

use SmokeDetector\Application\Feed\FeedIds;
use Symfony\Component\Uid\Uuid;

/**
 * UUID version 4: 122 bits from the operating system's secure random source and no time in it
 * (HLD §4.12 "No time").
 */
final readonly class RandomFeedIds implements FeedIds
{
    public function next(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
