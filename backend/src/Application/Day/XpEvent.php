<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Xp\XpSource;

/**
 * One entry of the XP ledger (HLD §4.3 "xp_events"): XP a user earned on a local day, and what for.
 */
final readonly class XpEvent
{
    public function __construct(
        /** A UUIDv7. */
        public string $id,
        public UserId $userId,
        public LocalDate $date,
        public XpSource $source,
        public int $amount,
        /** The streak length of a milestone; null for the other sources. */
        public ?int $detail,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
