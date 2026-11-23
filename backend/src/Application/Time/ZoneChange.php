<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Time;

use SmokeDetector\Application\Account\TimeZone;

/**
 * One entry of a user's time zone history (HLD §4.6 "Local days"): from this local date on, the
 * user's days are counted in this zone.
 */
final readonly class ZoneChange
{
    public function __construct(
        public LocalDate $validFrom,
        public TimeZone $zone,
    ) {
    }
}
