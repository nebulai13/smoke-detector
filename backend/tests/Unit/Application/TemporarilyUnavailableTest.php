<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\TemporarilyUnavailable;

/**
 * "Not now, try again": what the database layer throws for a deadlock or a lock wait timeout, and
 * the API answers as 503 with Retry-After.
 */
final class TemporarilyUnavailableTest extends TestCase
{
    public function test_it_says_when_to_try_again_and_keeps_what_the_database_said_out_of_its_message(): void
    {
        $cause = new RuntimeException('SQLSTATE[40001]: Deadlock found, user 0199 token abc');

        $busy = new TemporarilyUnavailable(3, $cause);

        self::assertSame(3, $busy->retryAfterSeconds);
        // A fixed text and no code: this message may be shown or logged anywhere.
        self::assertSame('The database could not finish the work right now.', $busy->getMessage());
        self::assertSame(0, $busy->getCode());
        self::assertSame($cause, $busy->getPrevious());
    }

    public function test_the_cause_is_optional(): void
    {
        self::assertNull((new TemporarilyUnavailable(1))->getPrevious());
    }
}
