<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

use DateTimeImmutable;
use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;

/**
 * One log of a user (HLD §4.3 "smoke_logs", FR-LOG-02): sessions smoked at one moment.
 *
 * The id is the one the app gave it, so the same log sent twice is stored once. A deleted log
 * stays stored with the time it was deleted, so a late copy from another device cannot bring it back.
 */
final readonly class SmokeLog
{
    /** The most sessions one log can hold (SRS OQ-9). */
    public const int MAX_COUNT = 50;

    public function __construct(
        /** The UUID the client chose. */
        public string $id,
        public UserId $userId,
        public DateTimeImmutable $smokedAt,
        /** The user's day the log counts for, worked out by the server (LocalDays). */
        public LocalDate $localDate,
        public ProductType $productType,
        public int $count,
        /** When the server first stored the log. It never changes. */
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
        self::assertCount($count);
    }

    /**
     * @throws InvalidArgumentException When the count is outside 1 to MAX_COUNT.
     */
    public static function assertCount(int $count): void
    {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException('A log holds 1 to ' . self::MAX_COUNT . ' sessions.');
        }
    }
}
