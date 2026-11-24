<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Feed\FeedIds;

/**
 * Feed ids a test can predict: SequenceFeedIds::id(1), id(2), … In the application they are random,
 * so that an id says nothing about when its entry was written.
 */
final class SequenceFeedIds implements FeedIds
{
    private int $made = 0;

    public function next(): string
    {
        return self::id(++$this->made);
    }

    public static function id(int $number): string
    {
        return sprintf('f0000000-0000-4000-8000-%012d', $number);
    }
}
