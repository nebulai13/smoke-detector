<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\IdGenerator;

/**
 * Predictable ids for unit tests: valid UUIDs that end in 1, 2, 3, …
 */
final class SequentialIdGenerator implements IdGenerator
{
    private int $count = 0;

    public function next(): string
    {
        return self::id(++$this->count);
    }

    public static function id(int $number): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $number);
    }
}
