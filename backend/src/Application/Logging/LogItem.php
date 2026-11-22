<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SmokeDetector\Application\Plan\ProductType;

/**
 * One item of a sync from the outbox of an app (HLD §4.6 "POST /logs/sync"): a log under the id
 * the app gave it, or the deletion of the log of that id.
 */
final readonly class LogItem
{
    /** The UUID the app chose, in lower case: an id is the same log however the app spells it. */
    public string $id;

    public bool $deleted;

    /** In UTC, to the second. Null for a deletion. */
    public ?DateTimeImmutable $smokedAt;

    public ?ProductType $productType;

    public ?int $count;

    private function __construct(
        string $id,
        /** What the log says. Null for a deletion. */
        public ?LogEntry $entry,
    ) {
        $this->id = strtolower($id);
        $this->deleted = $entry === null;
        $this->smokedAt = $entry?->smokedAt;
        $this->productType = $entry?->productType;
        $this->count = $entry?->count;
    }

    /**
     * @throws InvalidArgumentException When the count is outside 1 to SmokeLog::MAX_COUNT.
     */
    public static function entry(string $id, DateTimeImmutable $smokedAt, ProductType $productType, int $count): self
    {
        SmokeLog::assertCount($count);

        // Fractions of a second are dropped: the column holds seconds, and an item that is sent
        // again must compare equal to what was stored from it.
        $seconds = (new DateTimeImmutable('@' . $smokedAt->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));

        return new self($id, new LogEntry($seconds, $productType, $count));
    }

    public static function deletion(string $id): self
    {
        return new self($id, null);
    }
}
